# roadrunner-symfony-bundle

RoadRunner runtime bundle for Symfony (HTTP + Centrifugo + Jobs workers).

## Quality checks

Run both before committing. As of the latest cleanup, **both are green**: PHPStan
reports 0 errors and the full suite is 299 tests / 611 assertions passing (6 skipped:
the `@group jobs-live` tests plus Symfony-version-gated tests, none of which need a
provisioned RoadRunner jobs pool to be considered green).

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

## Layout

- `src/Worker/` — `HttpWorker`, `CentrifugoWorker`, `JobsWorker` (graceful error handling:
  one frame per request, STDERR/Sentry logging, `register_shutdown_function` rescue for
  die/exit/fatal). See `docs/specs/graceful-error-handling.md`. The Jobs (queue consumer)
  worker — ack-on-success / nack-with-requeue-on-failure — is specced in
  `docs/specs/rr-jobs-worker.md` and registered under `Mode::MODE_JOBS`.
- `src/Job/` — Messenger-like message bus over RR Jobs (additive on top of `JobsRunEvent`):
  `#[AsJob]` / `#[AsJobHandler]` attributes, `JobDispatcher` (producer), `JobEnvelope`
  (wire contract: `x-job-class` / `x-job-serializer` headers), Native (PHP serialize) +
  optional Symfony serializers, `JobHandlerPass` (compile-time message→handler map, modeled
  on `CentrifugoRouterPass`) and `JobRoutingListener`. Specced in `docs/specs/jobs-message-bus.md`.
  `symfony/serializer` is `require-dev` + `suggest` only.
- `src/ErrorHandler/MinimalErrorPage.php` — dependency-free fallback error page.
- `src/EventListener/CentrifugoEventRouter.php` + `src/DependencyInjection/Compiler/CentrifugoRouterPass.php`
  — compile-time routing table for `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`.
- Optional **distributed locks**: when `roadrunner-php/symfony-lock-driver` is installed, `config/services.php`
  wires a Symfony `LockFactory` / `PersistingStoreInterface` onto RR's Lock plugin over the bundle's RPC
  (no `src/` class of our own — pure DI wiring, guarded by `class_exists(RoadRunnerStore::class)`).
