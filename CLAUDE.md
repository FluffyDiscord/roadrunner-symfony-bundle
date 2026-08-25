# roadrunner-symfony-bundle

RoadRunner runtime bundle for Symfony (HTTP + Centrifugo + Jobs + Temporal workers).

## Quality checks

Run PHPStan and PHPUnit before committing; both are green as of the latest cleanup. The
benchmark and the Docker harnesses below are situational — run them when you touch what they
cover, not on every change.

### Benchmarks — `tests/docker-bench.sh`

RPS + conversion-attribution harness. Runs wrk inside docker against a raw-PHP
ceiling worker and the bundle with both `http.request_factory` strategies; numbers are
session-relative — compare only within one run.

### Static analysis — PHPStan (level `max`)

```bash
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

- Config: `phpstan.neon` — level `max`, analyses `src` only, with `phpstan-symfony`.
- **`--memory-limit=1G` is required.** The default 128 MB crashes the parallel
  worker with "reached configured PHP memory limit" and reports an incomplete result.
- Do **not** silence errors with `@phpstan-ignore`, baseline entries, `assert()`,
  inline `@var`, or type casts/widening added purely to quiet the analyser — fix the
  underlying type instead (validate `mixed` from framework APIs with `is_*`/`is_array`
  guards, type routing tables via `@phpstan-type`, null-check nullable containers).

### Tests — PHPUnit 13

```bash
php vendor/bin/phpunit tests
```

- **There is no `phpunit.xml`** — you must pass the `tests` directory explicitly;
  a bare `php vendor/bin/phpunit` finds no configuration and runs nothing.
- Final classes (`RoadRunner\Centrifugo\CentrifugoWorker`, the `Request\*` types,
  `respond()`/`error()`/`disconnect()`) cannot be mocked. Worker tests instead build
  real fixtures around a mocked goridge `WorkerInterface` and drive the loop through
  `waitRequest()` / `registerShutdown()` / `logError()` seams on testable subclasses.

### Runtime docblock enforcement — TypePHP

`typephp/typephp` (require-dev, pinned `0.6.2`) boots from composer autoload and rewrites `src/**`
at include time, so a full `php vendor/bin/phpunit tests` enforces the bundle's own
`@param`/`@return`/`@var` contracts at runtime (~9s instead of ~0.5s). Config: `typephp.php`.
Operational details in `TESTING.md`.

- **Stay on `0.6.2` or newer.** `0.6.0` mis-handled three things this repo depends on, all fixed in
  `0.6.2` (upstream typephp-php/typephp#46, #47, #48): user `include`/`exclude` lists are now
  replaced wholesale instead of index-merged over the defaults, the tooling opt-out matches the
  argv[0] basename exactly instead of scanning argv and the script path for substrings, and an
  anonymous class passes a `class-string` contract.
- **`cache => false` is a speed call, not a security one.** `0.6.2` hardened the transform cache
  (per-euid directory, `0700`/`0600`, symlink and ownership checks), so it is safe to enable — it
  is simply not worth it: transformation is not the bottleneck, and caching it saves ~5% of the
  suite. Runtime checking is the rest.
- Globs are absolute (`__DIR__ . '/src/**'`) so they do not depend on the caller's working
  directory. `include` names `src/**` and nothing else, which is why no `exclude` list is needed.
- Never silence a violation with `@typephp-ignore` on a `src/` method — that drops all runtime
  checks there. Fix the docblock if it is wrong, or the test if the contract is right.
- Enforcement is host + `docker-test-symfony.sh` only. `docker-bench.sh` and the path-repo live
  scripts never install it; `docker-validate-all.sh` — the one run with zero skips — sets
  `TYPEPHP_DISABLE=1`, so the exhaustive suite is unenforced. PHPStan is unaffected.

## Layout

- `src/Worker/` — `HttpWorker`, `CentrifugoWorker`, `JobsWorker` (graceful error handling:
  one frame per request, STDERR/Sentry logging, `register_shutdown_function` rescue for
  die/exit/fatal). See `docs/specs/graceful-error-handling.md`. The Jobs (queue consumer)
  worker — ack-on-success / nack-with-requeue-on-failure — is specced in
  `docs/specs/rr-jobs-worker.md` and registered under `Mode::MODE_JOBS`.
- `src/Job/` — typed message bus over RR Jobs built on **Symfony Messenger** (additive on top of
  `JobsRunEvent`): `#[AsJob]` (producer attribute) + `JobDispatcher`, `JobEnvelope` (wire contract:
  `x-job-class` / `x-job-serializer` headers), igbinary/Native (PHP serialize) + optional Symfony
  serializers. On consume, `JobRoutingListener` deserializes and dispatches the message into
  `MessageBusInterface` (passing the RR task via a `HandlerArgumentsStamp`); handlers are plain
  `#[AsMessageHandler]`. Specced in `docs/specs/jobs-message-bus.md`. `symfony/messenger` and
  `symfony/serializer` are `require-dev` + `suggest` only.
- `src/Factory/ServerParamsFactory.php` — builds the Symfony `Request` server bag from the
  RoadRunner request alone (method, URI, protocol, remote address, host, `HTTP_*` headers). The
  worker's boot-time `$_SERVER` is never mixed in, so the process environment (and an `HTTP_PROXY`
  env var posing as a request header) cannot reach the request. The `$_SERVER` superglobal itself is
  left at its boot-time state — read request data off the `Request`.
- `src/Http/InformationalHeaders.php` — tracks the header values already emitted in `1xx` (Early
  Hints) frames so they are not repeated in the final response. RoadRunner's Go handler `Add`s
  worker headers and keeps `1xx` headers per RFC 8297, so re-sending the bag duplicates them on the
  wire. Statics are forced here: the `headers_send()` polyfill is a global function with no DI
  access (same constraint as `HttpWorker::$currentHttpWorker`). Live-tested by
  `tests/docker-validate-early-hints.sh`.
- `src/Grpc/` + `src/Worker/GrpcWorker.php` — gRPC worker (`Mode::MODE_GRPC`, optional on
  `spiral/roadrunner-grpc`): owns the RR frame loop (spiral's `final Server` offers no per-frame
  hooks), routes to services implementing protoc-generated `*Interface`s (discovered by
  `GrpcServicePass` via autoconfiguration on `ServiceInterface`), dispatches
  `Event\Grpc\*` with decoded protobuf messages, synthesises a **full** Symfony profile per call
  through FrameworkBundle's virtual-request stack (pop before `Profiler::collect()` — else
  `DumpDataCollector` writes to the goridge relay), optional Symfony Security auth via
  `AccessTokenHandlerInterface` + `#[IsGranted]`, `grpc:debug` command. Specced in
  `docs/specs/rr-grpc-worker.md`; guide in `docs/grpc.md`; live-tested by
  `tests/docker-validate-grpc.sh`.
- `src/ErrorHandler/MinimalErrorPage.php` — dependency-free fallback error page.
- `src/ErrorHandler/FatalError.php` — filters `error_get_last()` to genuinely fatal types, so a stale
  deprecation is never reported as the cause of a `die`/`exit`.
- `src/ErrorHandler/DumpCapture.php` — chains onto `VarDumper`'s handler to record where the last
  `dump()`/`dd()` ran (PHP records nothing for `exit`), so the rescue page can name and IDE-link it.
  Specced in `docs/specs/dump-capture.md`; live-tested by the `/dd` case in
  `tests/docker-validate-error-pages.sh`.
- `src/EventListener/CentrifugoEventRouter.php` + `src/DependencyInjection/Compiler/CentrifugoRouterPass.php`
  — compile-time routing table for `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`.
- Optional **distributed locks**: when `roadrunner-php/symfony-lock-driver` is installed, `config/services.php`
  wires a Symfony `LockFactory` / `PersistingStoreInterface` onto RR's Lock plugin over the bundle's RPC
  (no `src/` class of our own — pure DI wiring, guarded by `class_exists(RoadRunnerStore::class)`).
