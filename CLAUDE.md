# roadrunner-symfony-bundle

RoadRunner runtime bundle for Symfony (HTTP + Centrifugo workers).

## Quality checks

Run both before committing. As of the latest cleanup, **both are green**: PHPStan
reports 0 errors and the full suite is 196 tests / 350 assertions passing.

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

- `src/Worker/` — `HttpWorker`, `CentrifugoWorker` (graceful error handling: one frame
  per request, STDERR/Sentry logging, `register_shutdown_function` rescue for
  die/exit/fatal). See `docs/specs/graceful-error-handling.md`.
- `src/ErrorHandler/MinimalErrorPage.php` — dependency-free fallback error page.
- `src/EventListener/CentrifugoEventRouter.php` + `src/DependencyInjection/Compiler/CentrifugoRouterPass.php`
  — compile-time routing table for `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`.
