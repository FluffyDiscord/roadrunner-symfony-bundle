# Upgrade guide

## v7.1 → v7.2

**`$request->server` is now built from the RoadRunner request alone** — it used to start as a copy of the worker's boot-time `$_SERVER`.

Kept: `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `SERVER_PROTOCOL`, `SERVER_NAME`, `SERVER_PORT`, `HTTPS`, `REMOTE_ADDR`, `REQUEST_TIME`, `REQUEST_TIME_FLOAT`, `HTTP_HOST`, `CONTENT_TYPE`, `CONTENT_LENGTH`, `HTTP_*` headers.

Gone: env vars, `argv`, `SCRIPT_NAME`, `SCRIPT_FILENAME`, `PHP_SELF`, `DOCUMENT_ROOT`.

- Read config from the container / `$_ENV` / `$_SERVER` — untouched, still the boot-time environment.
- `HTTP_*` env vars (`HTTP_PROXY`) no longer turn into request headers.
- `getBaseUrl()` / `getScriptName()` now always return `''`.

## v7.0 → v7.1

### Boot failures

**A boot failure now answers the client** instead of killing the worker before its first request (RoadRunner returned its own error with an `EOF` body).

- Debug: Symfony error page. Prod: bare 500.
- Failed `WorkerBootingEvent` listener → keeps serving; only warmup is lost.
- Logged as `[roadrunner-symfony] BOOT FAILURE` — alert on that marker.
- A broken worker answers RoadRunner's PID probe, so the pool starts healthy instead of failing at `rr serve`.
- **Breaking:** `protected HttpWorker::renderHtmlError()` removed. Override `HttpWorker::getThrowableResponder()`, return a `WorkerErrorResponder` subclass.

### `http.request_factory`

**RoadRunner requests convert straight to Symfony requests**, skipping the PSR-7 object (~half the conversion cost). No action needed for typical apps.

- `auto` (default) keeps the PSR-7 path when a custom `Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface` service is registered.
- Force the old path: `http.request_factory: psr7`.
- Native path differences: uploads are `HttpFoundation\File\UploadedFile` pointing at RoadRunner's temp file, not a bridge-subclass copy; headers forwarded verbatim, no PSR-7 re-validation; `QUERY_STRING`/`REQUEST_URI` keep the raw wire encoding.
- **Deprecated:** the `HttpWorker` constructor's `$httpFoundationFactory` argument (still works, still forces PSR-7 when no strategy is injected). Register the service, or pass a `SymfonyRequestFactoryInterface` strategy.
- Conversion errors (malformed JSON body flagged as parsed, unparseable URI, PSR-7-invalid headers) now take the graceful-error path — Sentry capture, 500/debug page, reboot on fatal — instead of a bare `418`. `418` still covers transport/frame-decode errors.
- `$_SERVER` is no longer overwritten per request; it holds the boot-time environment only. Read request data from `$request->server` / `$request->headers`.
- The Centrifugo worker no longer calls `$kernel->boot()` per event. Lazy boot on the first event and per-event service resets are unchanged.

## v6 → v7

**Breaking:** `http.early_router_initialization` removed, replaced by zero-config [worker warmup](README.md#worker-warmup).

- Delete the key from `config/packages/fluffy_discord_road_runner.yaml` — an unknown key throws on boot.
- `HttpWorker::DUMMY_REQUEST_ATTRIBUTE` and the boot-time dummy request are gone — delete listeners that checked it.
