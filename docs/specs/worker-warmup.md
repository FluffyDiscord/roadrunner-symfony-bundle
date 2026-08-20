# Worker Warmup System (Implementation)

Replaces `http.early_router_initialization` with a zero-config, extensible pre-warming
system that lets a freshly booted RoadRunner worker serve its first request at hot-worker
latency. Document type: **Implementation**. Pinned to bundle master @ 3845df2 (v6.1.0),
2026-07-23.

## 1. Problem & scope

A fresh RR worker process serves its first request several times slower than its steady
state. Measured in a production-like Sylius app (PHP 8.5.5 cli NTS, RR 2025.1.15,
`opcache.enable_cli=1 validate_timestamps=0`, timings taken inside `HttpWorker` between
`waitRequest()` returning and `respond()` completing; full data:
`docs/specs/worker-warmup-measurements.md`):

| | first request | steady state |
|---|---|---|
| homepage | 252 ms | 33–43 ms |
| product page | 105 ms | 24–30 ms |

Two mechanisms, both per-process because RR workers are independent `cli` execs with
per-process opcache SHM:

1. **Lazy class compilation** — a fully-trafficked worker holds 6 500+ declared
   symbols (in-worker measurement); kernel boot alone declares ~240 classes and the
   `early_router_initialization` dummy request adds only ~480.
2. **Lazy runtime initialization** — Doctrine metadata + entity persisters, form-registry
   type resolution, event-listener instantiation, Twig runtimes, and `include` of
   Symfony cache-pool PHP files (validator/serializer metadata as anonymous-class files).

Neither is fixable by `opcache.preload`: the Symfony-generated preload file opens with
`if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) { return; }` — a **no-op under
RoadRunner** (workers are `cli`).

The existing dummy request is also actively harmful: it carries no Host header, so
host-based channel/tenant resolution (e.g. Sylius) throws during boot, the uncaught
exception kills the worker and the pool never allocates. Downstream apps carry
short-circuit listeners as a workaround.

Scope: HTTP-first (the recorder is HTTP-only) but warmers run for every worker type via
`WorkerBootingEvent`. Out of scope: request replay of learned URLs (executes controllers —
side effects; rejected), Twig load-all (measured 394–504 ms for 4 162 templates with
marginal residual benefit; rejected).

## 2. Architecture decisions

### ADR-1 — Two layers: deterministic warmers + learned manifest

Layer 1, **generic warmers**, exercise enumerable framework infrastructure (router,
Doctrine, listeners, forms, Twig runtimes) — deterministic, no state, works on first boot.
Measured: first request 252 → 93–152 ms. Layer 2, **learned manifest**, records what
real traffic actually loaded (declared symbols + included cache-dir files) and replays it
at every subsequent worker boot — closes the residue. Measured: 41 ms ≈ hot (33–43 ms
range). Both run before RoadRunner marks the worker ready, so warm-up cost (~600 ms) is
paid pre-ready — invisible to the first request of a booted worker; worker respawns
(supervisor limits, post-`\Error` stops) still pay it before rejoining the pool.

### ADR-2 — Extensibility via `WorkerWarmerInterface` + autoconfigured tag; `WorkerBootingEvent` remains an equal seam

`FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmerInterface { public function warmup(): void; }`,
autoconfigured with tag `fluffy_discord.road_runner.worker_warmer` (priority via standard
tag priority / `#[AsTaggedItem]`). A `WorkerWarmupRunner` (itself a `WorkerBootingEvent`
listener, the same seam `DoctrinePreconnectListener` uses) iterates the tagged warmers in
priority order, wraps each in try/catch, and debug-logs per-warmer duration. The runner
exists to give every warmer one uniform failure policy, one timing log, one master kill
switch, and cross-warmer ordering. Third parties may equally register a plain
`WorkerBootingEvent` listener (documented); the tag is a convenience, not a gate.

### ADR-3 — Classes through `Preloader`, `opcache_compile_file()` only for cache-dir files

Verified by crash: `opcache_compile_file()` **early-binds** top-level classes; on files
declaring multiple classes (e.g. `symfony/messenger` `StackMiddleware.php` also declaring
`MiddlewareStack`) a later autoloader include fatals with "Cannot redeclare class",
killing the worker. Therefore: learned **symbols** (classes/interfaces/traits) are loaded
with `Symfony\Component\DependencyInjection\Dumper\Preloader::preload()` — autoloader-
driven and idempotent; `opcache_compile_file()` is used **only** for non-autoloadable
files under `kernel.cache_dir`, and files already in `get_included_files()` are skipped
(compile-time redeclare of composer/twig function files). Why cache-dir files are safe:
cache-pool entries are anonymous-class/return-value files (nothing to early-bind);
compiled Twig templates DO declare named top-level classes, but Twig guards every
template load with `class_exists(..., false)` before requiring the file, so a
boot-compiled (and thus possibly early-bound) template class is simply reused. Do not
extend the compile-file list beyond these two categories without re-checking early
binding.

### ADR-4 — `opcache.file_cache` detected → the compile-file half is skipped

Measured interaction: with `opcache.file_cache` set, boot-time `opcache_compile_file()`
of volatile cache-pool files degrades first requests (102 ms vs 44 ms — mechanism not
root-caused, labeled assumption: file-cache entries for runtime-rewritten pool files
trigger revalidation; the skip rule makes the mechanism moot). With file_cache the
class-preload half remains beneficial and boots 3× faster (194 ms vs 604 ms) at +3–10 ms
first-request cost. Rule: `ini_get('opcache.file_cache')` non-empty ⇒
`LearnedManifestWarmer` skips its compile-file step and logs at debug level.
`opcache.file_cache` is additionally documented in the README as the recommended
deployment flag for large pools / frequent restarts.

### ADR-5 — Failures are logged and swallowed; the worker always boots

Same policy as `DoctrinePreconnectListener` (doctrine-preconnect.md ADR-5). A warmer
throwing, a corrupt manifest, an unwritable directory, missing opcache — all degrade to
"less warm", never to "no worker". The runner catches `\Throwable` per warmer; the
recorder catches `\Throwable` per write. Additionally, stdout is the goridge protocol:
a PHP warning or stray `echo` during boot-time class loading would corrupt it and kill
the worker (verified: measurements trap 2). The runner therefore wraps the ENTIRE warmup
run in `ob_start()` … `ob_get_clean()`, discarding captured output into a warning-level
log line; the recorder wraps its record path the same way. The README's
`display_errors=0`/stderr guidance remains as defense-in-depth.

### ADR-6 — Top-level `warmup:` config node, orthogonal to `lazy_boot`

Follows doctrine-preconnect.md ADR-6: a `WorkerBootingEvent` listener cannot know which
worker dispatched it or that worker's `lazy_boot`; nesting under `http.` would misstate
its scope and gating on `lazy_boot` would couple worker types. `warmup.enabled: false` is
the single opt-out. Under `lazy_boot: true` the kernel is booted by `Runner::run()` before
the worker starts, so warmers still run — a lazy-boot user who wants zero boot cost sets
both flags (documented).

### ADR-7 — Learning is on by default, bounded, off the latency path, self-disabling

Owner requirement: zero-config best latency. The recorder listens ONLY on the existing
`WorkerResponseSentEvent` (dispatched after `respond()`, so recording cost lands on worker
availability, not response latency). There is **no baseline**: each record captures the
FULL current declared-symbol set (+ `get_included_files()` restricted to
`kernel.cache_dir`) — replay is idempotent (`Preloader` skips declared symbols, the
warmer skips already-included files), the storage union-merge dedupes, and a fresh
recorder instance after the kernel reboot `HttpWorker` performs on app exceptions simply
records the full set again (correct by construction). Baseline-delta designs are
FORBIDDEN here: any baseline captured at or after the first response swallows request
#1's cold-load graph — the single biggest thing to learn (found live: 208-symbol manifest
instead of ~7,800, cold requests stuck at ~100 ms). A write is skipped when the symbol
and include counts have not grown since the last record (the converged steady state —
most responses). After `learn_requests` HTTP responses (default 30) the recorder sets an
internal flag, checked as the handler's first statement. Per-event cost bound: two counts
+ (rarely) a full-set union write, measured 1–5 ms. Multi-worker races: last-writer-wins
with union-merge inside `write()`, converges over requests. The manifest is
**traffic-shaped**: only routes actually visited during learning are fully warmed —
documented honestly in the README.

### ADR-8 — Manifest lives in `%kernel.cache_dir%/roadrunner/warmup.manifest.json`

Wiped by `cache:clear`/deploy ⇒ relearns each release; `ContainerPreloadWarmer` (ADR-9)
covers first boots. Path configurable (`warmup.manifest_path`) for persistence across
releases. On unwritable target (read-only prod filesystems) the storage logs one warning per
storage instance and every write degrades to a no-op; warmers degrade to layer 1.

Format: **JSON** — `{"version": 1, "build_id": string, "classes": string[], "files": string[]}`.
JSON, not executable PHP, for three reasons: (a) a PHP manifest read via `include` is
cached by opcache and, under the recommended `validate_timestamps=0`, every re-read in the
recorder's read-merge-write cycle would return the stale first read — silently losing
concurrent workers' writes; (b) a data file is never executed, so a relocated
`manifest_path` on a shared volume is not an arbitrary-code-execution surface; (c) one
boot-time `json_decode` of ~8k strings costs single-digit ms. `build_id` =
`%container.build_id%`; a mismatch on read ⇒ manifest treated as absent (auto-relearn per
release, bounding growth on persistent paths; within one release the manifest grows to
the app's hot set and is never pruned — tolerated, stated in README).

Write mechanics: the storage creates the manifest directory recursively on first write;
the temp file is created with a per-process unique name **in the manifest's own
directory** (same filesystem ⇒ `rename()` atomic); union-merge with current disk content
happens inside `write()`; directory-absent and unwritable both follow the same degrade
path.

### ADR-9 — First-boot fallback parses the Symfony-generated preload list

`ContainerPreloadWarmer` runs only when no learned manifest exists: it reads
`{kernel.build_dir}/*.preload.php` and extracts `$classes[] = '...';` lines by regex, then
`Preloader::preload()`s them (2 965 classes, ~300 ms boot; request-time effect
approximates the ini-preload experiment: cold homepage 252→91 ms in that run).
The regex couples to `PhpDumper`'s dump format — accepted: the format has been stable for
years, the failure mode is fail-open (no matches ⇒ no-op + debug log), and the alternative
does not exist (the file's `PHP_SAPI` guard cannot be satisfied from `cli`; re-deriving
the class list needs compile-time container access the runtime doesn't have). Pinned by a
fixture test so a format change fails loudly in CI.

### ADR-10 — `early_router_initialization` is removed (major version)

Owner directive. The config node, constructor argument, dummy-request block, and
`DUMMY_REQUEST_ATTRIBUTE` constant are deleted; `RouterWarmer` (matcher + generator
instantiation, no HTTP request) replaces the only value it had. This removes the
host-less-request crash class entirely. `HttpWorker::$bootWarmupInProgress` is retained
but now guards the whole warmup run (a custom warmer may emit output; the `headers_send`
polyfill's boot-time swallow still applies). BC: apps referencing the removed node get a
config exception at compile; apps referencing the constant get a fatal — release as
**v7.0.0** with an upgrade note.

### ADR-11 — Debug pools (`http.pool.debug: true`) run no warmup and learn nothing

Under `pool.debug` RoadRunner discards the PHP process after every request, so warmed state
never reaches a second request and the boot-time replay is pure latency (measured in a Sylius
dev app: 1.3 s per request on top of a 0.55 s kernel boot). It is also incorrect: replaying
compiled Twig templates through `opcache_compile_file()` early-binds `__TwigTemplate_*`
classes whose parent is already loaded, and `Twig\Environment::loadTemplate()` checks
`class_exists($cls, false)` *before* its `auto_reload` freshness check — the edited source is
never recompiled and the shop keeps rendering the stale template until the cache dir is
cleared. ADR-3's "boot-compiled template class is simply reused" is only correct when the
cache dir is immutable for the worker's lifetime, which a dev pool is not.

`WorkerWarmupRunner` and `WarmupManifestRecorder` therefore receive
`%kernel.runtime_mode.worker%` (an env-resolved parameter, so it reflects the booting
process) and no-op when it is false. `Runtime::resolveRuntimeMode()` already maps
`pool.debug` → `worker=0`, so the gate needs no new configuration and no file I/O of its own.
`warmup.enabled: false` remains the explicit opt-out for persistent pools.

## 3. Behavior specification

Boot sequence (`HttpWorker::start()`, non-lazy path; identical hooks for other workers
via `WorkerBootingEvent`):

1. `kernel->boot()`
2. `WorkerBootingEvent` dispatched → `WorkerWarmupRunner` (listener priority 128, so it
   runs before `DoctrinePreconnectListener`'s default 0) sets
   `HttpWorker::$bootWarmupInProgress = true`, opens an output buffer (ADR-5), runs
   warmers by descending tag priority, then in `finally` discards captured output to a
   log line and resets the flag:
   - `LearnedManifestWarmer` (priority 64): manifest exists ⇒ `Preloader::preload(classes)`;
     unless `opcache.file_cache` active, `opcache_compile_file()` each `files` entry that
     `is_file()` and is not in `get_included_files()`.
   - `ContainerPreloadWarmer` (48): no manifest ⇒ parse + preload the generated list.
   - `RouterWarmer` (32), `DoctrineWarmer` (32), `EventListenersWarmer` (16),
     `FormRegistryWarmer` (16), `TwigRuntimesWarmer` (16) — order among peers irrelevant.
   - third-party warmers (default priority 0) run last unless they choose otherwise.
3. Worker enters the serve loop; RR marks it ready only now.
4. `WarmupManifestRecorder` (on `WorkerResponseSentEvent`, HTTP mode only): each event
   writes the full current symbol + cache-dir-include sets unless neither count grew
   since the last record; after `learn_requests` HTTP responses it self-disables.

Warmer specifics:

- **RouterWarmer**: wired to `router.default` (`nullOnInvalid`), NOT the `router`
  alias — FrameworkBundle registers the concrete
  `Symfony\Bundle\FrameworkBundle\Routing\Router` under that stable id even when the
  alias is decorated (verified: Sylius's `LocaleStrippingRouter` decorates `router`;
  `router.default` remains the framework Router underneath). If the injected service
  is a `Symfony\Component\Routing\Router`, call `getMatcher()` + `getGenerator()` —
  pure loading, no matching. Anything else (or absent) is a no-op; the learned
  manifest covers such setups after the first learning window. `match()` is never
  called: no route path (`/` included) may be assumed to exist, and on custom/DB-backed
  routers `match()` executes real lookup logic with fabricated input (queries, side
  effects). No URL generation either (route names are app-specific).
- **DoctrineWarmer**: for each manager in `ManagerRegistry`:
  `getMetadataFactory()->getAllMetadata()`; when the manager is an
  `Doctrine\ORM\EntityManagerInterface`, additionally
  `getUnitOfWork()->getEntityPersister($metadata->getName())` for every non-superclass,
  non-embedded metadata. Measured 12–14 ms + 0.4 ms.
- **EventListenersWarmer**: `EventDispatcherInterface::getListeners()` — forces
  instantiation of all lazily-registered listeners (307 registrations, 13–60 ms).
- **FormRegistryWarmer**: for each service tagged `form.type`,
  `FormRegistryInterface::getType($type::class)` in try/catch (258 types, 12–37 ms).
- **TwigRuntimesWarmer**: iterate services tagged `twig.runtime` (instantiation is the
  warm-up; 13 runtimes, 4–9 ms).

## 4. Configuration contract (public API — irreversible, Clarity check 7)

```yaml
fluffy_discord_road_runner:
    warmup:
        # Master switch for the whole system (runner + all built-in warmers + recorder).
        enabled: true
        # Learned-manifest layer: record after real responses, replay at boot.
        learn: true
        # Stop recording after this many responses per worker process.
        learn_requests: 30
        # null => %kernel.cache_dir%/roadrunner/warmup.manifest.php
        manifest_path: null
```

Removed: `http.early_router_initialization` (ADR-10). `http.lazy_boot` untouched.
Defaults chosen: `enabled`/`learn` true (zero-config goal); `learn_requests` 30 —
provisional heuristic (covers several page archetypes per worker at 1–3 ms each; override
with rationale if traffic profile demands).

## 5. Component design

All new code under `src/Warmup/`. ALL wiring — service definitions AND
`registerForAutoconfiguration(WorkerWarmerInterface::class)` — lives in a
`registerWarmup()` method of `FluffyDiscordRoadRunnerExtension`, gated on the processed
`warmup.enabled` config (precedent: doctrine-preconnect.md §5.2 rules that
config-flag-gated registration belongs in the extension, not `config/services.php`).
Nothing warmup-related goes into `config/services.php`.

### 5.1 `WorkerWarmerInterface` (new)
`warmup(): void`. No return, no context argument (a warmer needing services injects them).

### 5.2 `WorkerWarmupRunner` (new)
`readonly`; ctor: `iterable $warmers` (tagged iterator, priority-ordered),
`?LoggerInterface` (nullOnInvalid). `__invoke(WorkerBootingEvent)`: guard flag, loop,
try/catch per warmer, `debug` log `warmer, duration_ms` each + one `info` summary
`(n warmers, total ms)`.

### 5.3 `WarmupManifestStorage` (new)
`readonly`; resolves the manifest path (config or default). `read(): ?array` —
`json_decode(file_get_contents(...))`, validates shape + `version` + `build_id` match;
the build id is read lazily at runtime from the `parameter_bag` service
(`container.build_id` is added by PhpDumper at dump time and does NOT exist during
container compilation — a compile-time `%container.build_id%` reference throws); missing
parameter ⇒ empty-string id (still versioned), any mismatch or `\Throwable` ⇒ null. `write(array
$classes, array $files): bool` (true only when the manifest actually landed on disk; the
recorder advances its seen-counts only then, so a failed write is retried when the sets
next grow) — merge-union with current disk content (re-read fresh;
JSON is never opcache-cached), filter `@anonymous` and non-UTF-8 symbols/paths (single owner of both rules; found
live: a vendor-generated class name carrying a raw 0xA9 byte fails `json_encode` for the
whole manifest),
create the manifest directory recursively, write to a uniquely-named temp file in the
manifest's own directory, atomic `rename()`. `exists(): bool` = `read() !== null`.
Shared by warmer + recorder.

### 5.4 `LearnedManifestWarmer` / `ContainerPreloadWarmer` (new)
Per ADR-3/-4/-9. Both no-op silently (debug log) when their input is absent.
`opcache_compile_file` guarded by `function_exists`. file_cache detection
(`ini_get('opcache.file_cache')`) lives in an overridable `protected` method — the
test seam for U12.

### 5.5 `RouterWarmer`, `DoctrineWarmer`, `EventListenersWarmer`, `FormRegistryWarmer`, `TwigRuntimesWarmer` (new)
Per §3. Dependencies wired `nullOnInvalid` / `tagged_iterator`; each no-ops when its
dependency is null/empty. `RouterWarmer` references `router.default`, not `router`
(§3 rationale); its parameter stays typed `?RouterInterface` with an `instanceof
Router` guard inside — a stricter ctor type would turn an exotic `router.default`
class into a DI `TypeError` at iterator advancement, aborting the remaining warmers. Registered only when their underlying class exists
(`class_exists` guard in extension, mirroring `registerDoctrinePreconnect`).

### 5.6 `WarmupManifestRecorder` (new)
Listener on `WorkerResponseSentEvent` ONLY (HTTP mode only). Per-event: compare current
symbol/include counts to the last recorded counts → unchanged ⇒ no write; grown ⇒
`storage->write(full sets)` inside output buffering; after `learn_requests` HTTP
responses set the done-flag (checked first in the handler). Not `readonly` (mutable
counters/flag). Kernel reboots keep the ORIGINAL recorder receiving events (HttpWorker holds its
boot-time dispatcher reference across reboot()); a rebuilt dispatcher would deliver to a
fresh instance instead — both are correct under full-set records + union-merge (ADR-7).

### 5.7 `HttpWorker` (modify)
Remove `earlyRouterInitialization` ctor arg, dummy-request block, `DUMMY_REQUEST_ATTRIBUTE`.
Keep `$bootWarmupInProgress` (now set by the runner; update its doc comment and the
polyfill comment).

### 5.8 `Configuration` / `FluffyDiscordRoadRunnerExtension` (modify)
Add `warmup` root node (§4); delete `early_router_initialization` node and its
`replaceArgument(0, ...)`; renumber `HttpWorker` args; update the phpdoc config-shape
array at `FluffyDiscordRoadRunnerExtension.php:106` (drop `early_router_initialization`,
add `warmup`). Register warmup services (and the autoconfiguration) only when
`warmup.enabled` (recorder additionally requires `learn`).

### 5.8b Existing files referencing the removed API (complete inventory)
- `tests/DependencyInjection/ConfigurationTest.php:22,46-48` — old-node assertions
  replaced by W5.
- `tests/Worker/AbstractHttpWorkerTestCase.php:113,121` — `earlyRouterInitialization`
  harness parameter removed (every worker test inherits this).
- `tests/Worker/HttpWorkerEarlyHintsTest.php:247` — dummy-request boot scenario
  re-expressed as "flag set externally ⇒ 1xx swallowed".
- `tests/Worker/HttpWorkerBootTest.php` — dummy-request tests replaced (§9).
- `docs/specs/graceful-error-handling.md:54,86,266` — three references to the
  "dummy early-router request" model marked `superseded:` with a pointer to this spec
  (that spec is otherwise historical, pinned to its own commit).

### 5.9 `README.md` (modify)
Replace the `early_router_initialization` section with: the warmup system (what is warmed,
zero-config), the learned manifest + traffic-shaped caveat, `opcache.file_cache`
recommendation + detection rule, the `display_errors=0`/stderr requirement (PHP warnings
on stdout corrupt the goridge protocol — verified), extension guide (interface/tag or
plain `WorkerBootingEvent` listener), v7 upgrade note.

## 6. Assumptions

- A1 (labeled): the file_cache × compile-file degradation mechanism is unverified
  (ADR-4); the skip rule sidesteps it regardless.
- A2: `PhpDumper` preload-file format stability (ADR-9); fail-open + fixture-pinned.
- A3 (labeled, untested for memory): `Preloader::preload()` of ~7 200–7 800 learned
  symbols measured 244–246 ms without file_cache and 67–138 ms with it. Memory impact was
  NOT measured; opcache SHM is per-process, so budget `opcache.memory_consumption` ×
  worker count. README carries the sizing note.
- A4: recording cost 1–3 ms/response for 30 responses is acceptable on the availability
  path (ADR-7).

## 7. Open Questions

None blocking. Deferred (tracked in README as tuning guidance, not spec gaps):
whether `learn_requests` should decay per-deploy (relearn shrink), and persistent-path
recommendations for autoscaling fleets.

## 8. Anti-Patterns (DO NOT)

| Don't | Do instead | Why |
|---|---|---|
| Replay recorded/configured URLs through `kernel->handle()` at boot | Class/file manifest + service warmers | Executes controllers: side effects, auth redirects, host-dependent crashes (the dummy-request lesson) |
| `opcache_compile_file()` vendor/src class files | `Preloader::preload()` symbol names | Early binding fatals on multi-class files (verified crash) |
| Compile cache-dir files when `opcache.file_cache` is active | Detect via `ini_get` and skip | Measured 2× first-request degradation (ADR-4) |
| Let a warmer failure propagate | try/catch + log per warmer | A warm-up aid must never prevent serving (ADR-5) |
| Gate warmup wiring on `http.lazy_boot` | Top-level orthogonal `warmup.enabled` | Runner can't know the dispatching worker's mode (ADR-6, preconnect precedent) |
| Write the manifest in place / non-atomically | temp file + `rename`, union-merge | Concurrent workers; partial reads must be impossible |
| Load all Twig templates at boot | Learned cache-dir file list | 394–504 ms for 4k templates, marginal benefit (measured) |
| Warm routes by matching a fabricated path (`match('/')` or any guess) | `router.default` + `getMatcher()`/`getGenerator()` | No route may be assumed to exist; `match()` runs real lookup logic on custom/DB-backed routers (queries, side effects); the `router` alias may be a decorator hiding the compiled matcher |
| Emit warmer output to stdout | STDERR / PSR logger only | stdout is the goridge protocol; bytes there kill the worker (verified) |

## 9. Test Case Specifications

Unit — `tests/Warmup/`:

| ID | Test | Input | Expected |
|---|---|---|---|
| U1 | RunnerRunsWarmersInOrder | 3 stub warmers, priorities | called high→low, summary logged |
| U2 | RunnerSwallowsWarmerFailure | 2nd warmer throws | 1st+3rd still run, error logged, no throw |
| U3 | RunnerSetsBootWarmupFlag | stub warmer asserting flag | `$bootWarmupInProgress` true during, false after (also on throw) |
| U3b | RunnerSwallowsStdout | warmer that echoes | nothing reaches stdout; captured output logged |
| U3c | RunnerSkipsNonPersistentWorker | `persistentWorker: false` | no warmer invoked, one debug log (ADR-11) |
| U4 | StorageRoundTrip | write(classes, files) → read | same lists, version=1, build_id stamped |
| U5 | StorageMergesUnion | write A, write B | read = A∪B, no dupes |
| U6 | StorageFiltersAnonymous | class names with `@anonymous` | not persisted |
| U7 | StorageRejectsCorruptManifest | wrong shape / version / build_id | read() = null, no throw |
| U7b | StorageRereadSeesLatestWrite | write A, write B, read | read = A∪B even with opcache enabled (JSON path never stale) |
| U8 | StorageUnwritableDirDegrades | non-writable parent; also absent nested dir | write() no-throw, warning logged once; absent dir gets created recursively |
| U9 | ContainerPreloadWarmerParsesFixture | committed fixture of generated preload file | expected class list extracted |
| U10 | ContainerPreloadWarmerFailOpen | missing/garbled file | no-op, debug log |
| U11 | ContainerPreloadWarmerSkippedWhenManifestExists | storage with manifest | parser never invoked |
| U12 | LearnedManifestWarmerSkipsCompileUnderFileCache | fake `file_cache` ini via seam | compile step not attempted, classes still preloaded |
| U13 | RecorderRecordsFullSetsAndStops | N and a few events | event 1 writes the FULL current sets (carries request #1's cold graph); events past the window return before any storage interaction |
| U13b | RecorderSkipsUngrownSets | event where neither count grew | no storage write (verified via a write spy or backdated mtime — same-second mtime comparison is vacuous) |
| U13c | RecorderCacheDirFilter | cacheDir set to a real include prefix | manifest files contain only cache-dir entries; vendor/src files excluded (they would resurrect the early-binding fatal) |
| U14 | RecorderHttpModeOnly | event mode ≠ http | no write |
| U14b | RecorderSkipsNonPersistentWorker | `persistentWorker: false`, http event | no write (ADR-11) |
| U14c | WiringGatesOnRuntimeModeWorker | default config | runner arg 2 and recorder arg 4 = `%kernel.runtime_mode.worker%` |
| U15 | RouterWarmer / DoctrineWarmer / FormRegistryWarmer null-dep no-op | null / empty deps | no throw, no-op |
| U15b | RouterWarmerWarmsConcreteRouter | `Router` instance | `getMatcher()` + `getGenerator()` each called once |
| U15c | RouterWarmerIgnoresNonRouterImplementations | plain `RouterInterface` mock | no method invoked — `match()` in particular never called |

Integration / wiring — `tests/Warmup/WarmupWiringTest.php` (pattern:
`DoctrinePreconnectWiringTest`):

| ID | Test | Expected |
|---|---|---|
| W1 | DefaultConfigWiresRunnerAndWarmers | runner tagged `kernel.event_listener` on WorkerBootingEvent priority 128; built-ins tagged warmer with specced priorities (64/48/32/16); RouterWarmer argument references `router.default`, not `router` |
| W2 | EnabledFalseWiresNothing | no warmup services in container |
| W3 | LearnFalseDropsRecorderKeepsWarmers | recorder absent, warmers present |
| W4 | AutoconfigurationTagsCustomWarmer | app class implementing interface gets the tag (and no autoconfiguration exists when enabled: false) |
| W5 | EarlyRouterInitializationNodeGone | old config key ⇒ InvalidConfigurationException |
| W6 | HttpWorkerSignatureUpdated | container compiles; worker args match new ctor |

Worker behavior — update `tests/Worker/HttpWorkerBootTest.php`: boot no longer calls
`kernel->handle()` (no dummy request); flag lifecycle covered by U3.

Live validation (manual, documented in the spec's measurement companion): the Sylius app
run matrix from §1 re-executed against the built bundle — acceptance: median first-request time over ≥5 fresh worker boots ≤ 1.5× the median hot
time from the same session (provenance: measured medians 41 ms vs 33 ms = 1.24×, with
declared ±30 % run-to-run cold variance the 1.5× bound stays discriminating without
flipping inside noise).

## 10. Error Handling Matrix

| Condition | Detection | Response | Log | Recovery |
|---|---|---|---|---|
| Warmer throws | try/catch in runner | continue with next warmer | error, warmer FQCN + exception | worker serves less-warm |
| Manifest unreadable/corrupt/build_id mismatch | storage read validation | treat as absent → fallback warmer | debug | relearned by recorder |
| Manifest dir unwritable | first write attempt | recorder self-disables | warning (once/process) | layer-1 warmers only |
| opcache disabled / `opcache_compile_file` missing | `function_exists` | skip compile step | debug | Preloader path still runs |
| `opcache.file_cache` active | `ini_get` | skip compile step (ADR-4) | debug | class preload still runs |
| Preload file absent (dev) / format drift | glob empty / regex no match | no-op | debug | generic warmers only |
| Class in manifest no longer exists | `Preloader` (class_exists false) | skipped by Preloader | — | tolerated until manifest invalidation (build_id mismatch or cache:clear); union-merge never prunes |
| Recorder throws mid-record | try/catch in recorder | skip this record | error | next response retries |

User-facing errors: none — the system has no request-path surface; misconfiguration
(unknown keys, wrong types) fails at container compile via the Configuration tree.

## 11. References

- Measurements companion: `docs/specs/worker-warmup-measurements.md` (same directory).
- Precedent: `docs/specs/doctrine-preconnect.md` (ADR-1, ADR-5, ADR-6 reused here).
- Seams: `src/Event/Worker/WorkerBootingEvent.php`, `WorkerResponseSentEvent.php`.
- Symfony `Preloader`: `symfony/dependency-injection` `Dumper/Preloader.php` (existing dep).

## Divergence Log

- 2026-08-20 — ADR-11 added (v7.1.2): warmup + learning gated on persistent worker mode after
  a dev app with `pool.debug: true` served stale Twig templates through the early-bound
  manifest replay.
