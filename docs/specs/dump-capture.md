# Dump capture on worker termination (Implementation)

Makes `dd()` usable again under RoadRunner: when a worker dies mid-request, the rescue page names
**where the last `dump()`/`dd()` ran** and — when no dump server is configured — shows the dump
itself. Document type: **Implementation**. Status: **implemented**, live-validated on RoadRunner
(PHP 8.4/8.5) via the `/dd` case in `tests/docker-validate-error-pages.sh`.
Pinned to branch `early-hints-duplicate-headers` @ `905ac1e`, 2026-08-20.

Extends [`graceful-error-handling.md`](graceful-error-handling.md) Bucket B (the
`register_shutdown_function` rescue). Nothing here changes Buckets A/B′/C/D.

## 1. Problem & scope

`die()`/`exit()` leave **no** trace in PHP: `error_get_last()` returns `null` (or, before rev4 of the
graceful-error-handling spec, a stale unrelated deprecation), and a shutdown function runs on an
unwound stack, so `debug_backtrace()` there holds only the shutdown closure itself. The rescue page
therefore cannot point at the `die` — verified:

```php
register_shutdown_function(fn() => var_dump(debug_backtrace()));  // → [ ['function' => '{closure:…}'] ]
function inner() { die("bye"); }  inner();
```

But the overwhelmingly common way a Symfony developer terminates a request is **`dd()`**, and `dd()`
does leave a trace *before* it exits: it calls `VarDumper::dump()` on a fully intact stack. A handler
installed on `VarDumper` sees the call site. That is the hook this spec uses.

Second problem, same feature: under RoadRunner a `dump()` in a controller is invisible. RR's
`StdoutHandler` `ob_start()`s the worker and re-streams output-buffer writes to STDERR
(`graceful-error-handling.md` O9), so the HTML dump lands in the RoadRunner console log as escaped
markup, not in the browser. Developers with [Buggregator](https://buggregator.dev/) (or any
`VAR_DUMPER_SERVER`) are unaffected — the dump goes over TCP. Everyone else has nothing.

**Scope:** dev only (`kernel.debug === true`). HTTP gets the page; Centrifugo/Jobs get the location
in their STDERR line. Out of scope: surfacing `dump()` output on *successful* responses (OQ-2).

## 2. Verified facts

Checked live against the installed `symfony/var-dumper` (PHP 8.5.7), not assumed:

| # | Fact | Evidence |
|---|------|----------|
| F1 | `VarDumper::setHandler(?callable): ?callable` returns the **previous** handler, so a handler can chain instead of replacing. | `vendor/symfony/var-dumper/VarDumper.php:49-61` |
| F2 | `setHandler()` is a **silent no-op** when `$_SERVER['VAR_DUMPER_FORMAT']` is set — it returns the previous handler and never assigns. A naive install under `VAR_DUMPER_FORMAT=server` (a common Buggregator setup) captures nothing, with no error. | `VarDumper.php:52-55`; reproduced: handler never ran until the format key was unset around the call |
| F3 | `SourceContextProvider::getContext()` called **from inside** a dump handler returns the `dump()`/`dd()` call site (`file`, `line`, `name`, and a `file_excerpt` for Twig frames) — it walks the backtrace for the `VarDumper::dump` frame. | `vendor/symfony/var-dumper/Dumper/ContextProvider/SourceContextProvider.php:34-80`; reproduced: two `dump()` calls in one function reported lines 26 and 27 |
| F4 | When no previous handler exists, the default one can be obtained without duplicating `VarDumper::register()`: `setHandler(null)` → `VarDumper::dump($var, $label)` (registers + dumps through the env-configured dumper) → `setHandler($ours)` returns the freshly built default, which is then cached as the forward target. | reproduced: dumps printed exactly once each, forward target cached after the first dump |
| F5 | `dd()` calls `VarDumper::dump()` for every argument and then `exit(1)`; `dump()` does the same without exiting. Both route through the handler. | `vendor/symfony/var-dumper/Resources/functions/dump.php:44-67` |
| F6 | `symfony/debug-bundle`, when installed, sets its handler in `DebugBundle::boot()`, and the worker calls `$this->kernel->reboot(null)` after any exception — so a once-only install is **not** enough; it must be re-asserted per request. | `src/Worker/HttpWorker.php:212`; `vendor/symfony/debug-bundle/DebugBundle.php:33-46` |
| F7 | The worker calls `servicesResetter->reset()` in the per-request `finally`, so a `kernel.reset`-tagged service is reset once per request — and is **not** reset when the process dies, which is exactly when the capture must survive. | `src/Worker/HttpWorker.php:219` |
| F8 | DebugBundle's registered handler is **self-replacing**: on its first invocation it calls `VarDumper::setHandler($innerHandler)`, which uninstalls whatever wraps it — ours included. Re-asserting only per *request* would therefore lose every dump after the first one in that request. The handler must be re-taken after **every** forward. | `vendor/symfony/debug-bundle/DebugBundle.php:33-46` (read after the first implementation attempt; TC-D7 guards it) |
| F9 | DebugBundle exposes the configured dump destination only as argument 0 of the `var_dumper.server_connection` definition (`''` unless `dump_destination` is a `tcp://` URL) — there is no `debug.dump_destination` parameter to read. | `vendor/symfony/debug-bundle/DependencyInjection/DebugExtension.php:48-66`, `Resources/config/services.php:110-112` |
| F10 | `CliDumper` writes to `php://stdout`, which `ob_start()` cannot capture; `HtmlDumper` uses `php://output`, which it can. | `vendor/symfony/var-dumper/Dumper/CliDumper.php:27` vs `AbstractDumper.php:30` |

## 3. Architecture decisions

**ADR-1 — hook `VarDumper`, not `dd()`.** `dd()` is a plain global function; the only interception
point is the handler (F5). No monkey-patching, no `composer` function override.

**ADR-2 — chain, never replace.** The installed handler always forwards to the previous one (F1), or
to VarDumper's own default via F4's dance. Consequence: Buggregator, the web profiler's dump
collector, and `VAR_DUMPER_FORMAT=cli` keep working exactly as before. A dump is never swallowed and
never duplicated.

**ADR-3 — re-assert the handler per request *and* after every forward.** On
`WorkerRequestReceivedEvent`: `$previous = VarDumper::setHandler($ours); if ($previous !== $ours) { $this->forwardHandler = $previous; }`,
which self-heals after `kernel->reboot()` re-installs DebugBundle's handler (F6). The same
re-assert runs after each forwarded dump, because DebugBundle's handler swaps itself out on first
use and would otherwise silently uninstall us mid-request (F8). Whatever replaced us becomes the
new forward target — one cheap `setHandler()` call per dump.

**ADR-4 — work at dump time, not at shutdown time.** Location resolution and (when needed) HTML
rendering happen inside the handler, during the request, with a healthy process. The shutdown path
only concatenates ready-made strings — preserving Invariant I-4 ("no operation that can re-fatal").

**ADR-5 — dump server present ⇒ location only.** If a dump destination is configured, the dump is
already going somewhere better; the page then shows only the location plus the destination, and the
handler skips HTML rendering entirely (no cost, no duplicated payload). Without a destination, the
page carries the rendered dumps — the fallback that gives non-Buggregator users the dump back.

**ADR-6 — dev only.** In prod the handler is never installed (no overhead, no information
disclosure); `die`/`exit`/`dd` keep returning a bare empty `500`.

**ADR-7 — bounded by construction.** At most `getMaxRenderedDumps()` dumps are rendered and at most
`getRenderedDumpMaxBytes()` of rendered HTML in total; further dumps keep updating the *location*
(cheap) but stop accumulating HTML. A `dump()` inside a loop must not turn the rescue into an OOM.

## 4. Behavior specification

`kernel.debug === true`, HTTP worker, no final response started (Bucket B):

| Situation | Rescue page |
|---|---|
| `dd($x)` in a controller, no dump server | `500` + "Terminated by `dd()` / `die()` — last dump at `src/Controller/X.php:42`" + the rendered dump(s) |
| `dd($x)`, dump destination configured (`tcp://buggregator:9912`) | same heading and location, plus "dump sent to tcp://buggregator:9912"; no inline dump |
| `dump($x)` earlier, unrelated bare `die()` later | location line, worded as **last dump**, not as the termination point (honest: PHP cannot prove they are the same statement) |
| bare `die()`/`exit()`, nothing dumped | unchanged — the generic die/exit explanation from `graceful-error-handling.md` §4.2 |
| genuine fatal (`E_ERROR`, …) | unchanged — fatal message + real `file:line`; a captured dump location is appended only if one exists |
| `VAR_DUMPER_FORMAT` set (F2) | identical to the rows above — the installer unsets the key around `setHandler()` and restores it immediately |

Other workers: Centrifugo and Jobs append `; last dump at <file>:<line>` to the existing
`worker terminated via die/exit …` STDERR line. No page (no client to render one for).

Prod (`kernel.debug === false`): no handler, no capture, bare `500` — unchanged from today.

**Invariant I-5:** the capture never changes what a dump *does*. Every dump reaches the same
destination it reached before the handler existed, exactly once.

**Invariant I-6:** nothing captured is rendered outside `kernel.debug`.

## 5. Component design

### `src/ErrorHandler/DumpCapture.php` (new service, `ResetInterface`)

```
public function installHandler(): void        // ADR-3; no-op when !$debug or VarDumper is absent
public function getSnapshot(): ?DumpSnapshot  // null until something was dumped this request
public function reset(): void                 // per-request, via services_resetter (F7)
private function getMaxRenderedDumps(): int       // 5
private function getRenderedDumpMaxBytes(): int   // 262144
private function getBacktraceLimit(): int         // 12 — VarDumper's 9 plus our own frames
```

Constructor: `bool $debug`, `string $projectDir`, `?FileLinkFormatter $fileLinkFormatter`,
`?string $dumpDestination`. Holds a `SourceContextProvider` and a lazily built `HtmlDumper` +
`VarCloner` (built only when rendering is actually needed, ADR-5).

`DumpSnapshot` (`src/ErrorHandler/DumpSnapshot.php`) is the readonly carrier: `location`,
`fileLink`, `renderedDumps`, `dumpDestination`, plus `getLogSuffix()` for the STDERR line.

The handler body: resolve context (F3) → record location → forward (ADR-2, F4) → render if
rendering is enabled and the bounds (ADR-7) still allow it.

`installHandler()` wraps `setHandler()` in the F2 dance:
unset `$_SERVER['VAR_DUMPER_FORMAT']` → `setHandler()` → restore. Single-threaded worker, so the
window is not observable.

### `src/EventListener/DumpCaptureListener.php` (new)

`#[AsEventListener(WorkerRequestReceivedEvent::class)]` → `$this->dumpCapture->installHandler()`.
Registered for every worker type, so a `dd()` in a job handler also yields a located STDERR line.

### `src/DependencyInjection/Compiler/DumpDestinationPass.php` (new)

Runs before optimization; reads argument 0 of the `var_dumper.server_connection` definition (F9) and,
when it is a non-empty string, injects it as `DumpCapture`'s 4th argument. Absent or empty ⇒ `null`,
and `DumpCapture` falls back to `$_SERVER['VAR_DUMPER_FORMAT']` at dump time. A `%…%` reference from
`config/services.php` is not an option — the service does not exist without DebugBundle, and an
unresolvable parameter is a hard container error.

### `src/ErrorHandler/MinimalErrorPage.php` (extended)

`render(int $statusCode, ?array $error, ?string $detail = null, ?DumpSnapshot $dumpSnapshot = null)`.
The snapshot carries only ready-made strings (ADR-4): location, IDE link, pre-rendered dump HTML and
destination. The location and destination are HTML-escaped, the IDE link is escaped into the `href`,
and the dump HTML — produced by `HtmlDumper`, which escapes the dumped values itself — is embedded
verbatim. The class stays dependency-free.

### Workers (`HttpWorker`, `CentrifugoWorker`, `JobsWorker`)

Constructor gains `?DumpCapture $dumpCapture` (null when `symfony/var-dumper` is absent).
`handleShutdown()` reads the two getters — plain property reads on an already-injected object; no
container access, Invariant I-4 intact.

### `composer.json`

`symfony/var-dumper` → `require-dev` + `suggest` ("Shows where the last dump()/dd() ran on the
worker error page"), guarded by `class_exists(VarDumper::class)` in `config/services.php` — the same
pattern as messenger/lock.

## 6. Assumptions

| # | Assumption | If wrong |
|---|------------|----------|
| A-1 | `DebugBundle::boot()` installs a handler that our re-assert (ADR-3) correctly chains to. | **CONFIRMED from source** (F6/F8) and covered by TC-D7, which drives a handler with DebugBundle's exact self-replacing shape. DebugBundle itself is deliberately *not* a dev dependency of this bundle (it drags in Twig). |
| A-2 | `SourceContextProvider` resolves the call site correctly through the extra handler frame we add. | F3 reproduced it with our closure in the stack; if a future version regresses, fall back to a manual `debug_backtrace()` scan for the `VarDumper::dump` frame. |
| A-3 | Rendering an `HtmlDumper` payload per dump is acceptable in dev when no dump server is configured. | ADR-7's bounds cap it; if it still hurts, make rendering opt-in via config. |
| A-4 | `$_SERVER['VAR_DUMPER_FORMAT']` unset/restore is safe in a single-threaded RR worker. | Any code reading it inside the same tick would misbehave — none does; the window is two statements. |

## 7. Open questions

| # | Question | Status |
|---|----------|--------|
| OQ-1 | Should the page hyperlink the location via `FileLinkFormatter` (`framework.ide`) so the file opens in PhpStorm? | **RESOLVED — yes (user, 2026-08-20).** `debug.file_link_formatter` is injected into `SourceContextProvider`, which returns `file_link`; the page renders the location as an `<a href>`. With no `framework.ide` configured `FileLinkFormatter` still yields a `file://%f#L%l` link, so the anchor is always present. Live-verified: the `/dd` body contains `href="phpstorm://open?file=…"`. |
| OQ-2 | Should captured dumps also be injected into *successful* responses (restoring `dump()` without the profiler)? | Out of scope — that is the web-debug-toolbar's job and it needs response rewriting. |
| OQ-3 | Should a `dd()` be reported to Sentry like a fatal? | No — it is a developer action in dev. Keep the existing generic capture. |

## 8. Anti-patterns (DO NOT)

- **Do not** call `VarDumper::setHandler()` without forwarding to the previous handler — it silently
  breaks Buggregator and the profiler's dump panel.
- **Do not** re-implement `VarDumper::register()` to build the forward target; use F4.
- **Do not** render or clone anything inside `handleShutdown()` (Invariant I-4).
- **Do not** install the handler in prod, and never render captured data when `kernel.debug` is false.
- **Do not** leave `$_SERVER['VAR_DUMPER_FORMAT']` unset after installing.
- **Do not** accumulate dumps unboundedly — a `dump()` in a loop must not OOM the rescue.

## 9. Test cases

Unit (`tests/ErrorHandler/DumpCaptureTest.php`):

| # | Case | Expectation |
|---|------|-------------|
| TC-D1 | dump with a previous handler installed | previous handler called exactly once, with the same value |
| TC-D2 | dump with no previous handler | VarDumper's default still produced output; forward target cached (F4) |
| TC-D3 | `$_SERVER['VAR_DUMPER_FORMAT']` set before install | handler runs; key restored to its original value afterwards (F2) |
| TC-D4 | location | `getLastDumpLocation()` is the caller's `file:line`, not a var-dumper internal |
| TC-D5 | bounds | 50 dumps → at most `getMaxCapturedDumps()` rendered, rendered size ≤ `getRenderedDumpMaxBytes()`, location still updated |
| TC-D6 | prod (`debug: false`) | `installHandler()` is a no-op; `getRenderedDumps()` stays null |
| TC-D7 | re-assert after reboot | a foreign handler installed between requests becomes the new forward target, ours stays active (ADR-3) |
| TC-D8 | dump destination configured | `getRenderedDumps()` null, destination exposed, no `HtmlDumper` instantiated |

Worker unit: `handleShutdown` with a populated `DumpCapture` → page contains the location; with an
empty one → today's generic page (regression guard for the existing tests).

Live (`tests/docker-validate-error-pages.sh`, per the project rule that worker/runtime behaviour
needs a real RoadRunner run): a `/dd` route calling `dd(['captured' => true])` must return `500`
whose body contains the controller's `file:line` **and** the dumped payload; a second pass with
`VAR_DUMPER_FORMAT=server` + an unreachable `VAR_DUMPER_SERVER` must still show the location (F2
regression guard). Prod pass: `/dd` returns an empty `500`.

## 10. References

- `docs/specs/graceful-error-handling.md` — Bucket B rescue, Invariants I-1…I-4, rev4 fatal filter
- `src/Worker/HttpWorker.php:123` (`FatalError::getLastFatalError()`), `:212` (reboot), `:219` (reset)
- `vendor/symfony/var-dumper/VarDumper.php`, `Dumper/ContextProvider/SourceContextProvider.php`
