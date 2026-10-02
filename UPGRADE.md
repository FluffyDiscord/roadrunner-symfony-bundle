# Upgrade guide

## v8.1.1 → v8.1.2

### Temporal (beta)

**Client calls give up when Temporal is down** — 5 s per attempt, 3 attempts — instead of hanging the request. See [Temporal down](docs/temporal.md#temporal-down).

- `getResult()` / update results without a timeout now throw `TimeoutException` after 5 s while the workflow still runs. Pass a timeout: `$run->getResult(timeout: 300)`.
- Tune: `temporal.client.rpc_timeout`, `temporal.client.rpc_max_attempts` (`0` = retry forever, as before).
- **Breaking:** `TemporalClientFactory::serviceClient()` takes `$rpcTimeoutSeconds` and `$rpcMaxAttempts`; `$apiKey` is no longer optional.

**A PHP `\Error` in an activity fails it for good** — no retry. See [Activity errors](docs/temporal.md#activity-errors).

- Own interceptor doing the same? Delete it.
- Relied on retries? `temporal.non_retryable_activity_errors: false`.
- Read failure messages with the cause's `getOriginalMessage()` — `getMessage()` is decorated by the SDK.
- The worker now also restarts when the `\Error` is wrapped (anywhere in the `previous` chain), e.g. a `TypeError` inside the SDK's `InvalidArgumentException`.

## v8.0 → v8.1

### Temporal (beta)

**Workers are config only.** `TemporalWorkerInterface`, `DefaultTemporalWorker`, `TemporalWorkerFactoryInterface`, `DefaultTemporalWorkerFactory` and `DuplicateTemporalWorkerException` are gone. One worker runs per task queue.

```yaml
# before
temporal:
    default_worker_options: { maxConcurrentActivityExecutionSize: 10 }
    worker_options: { billing: { maxConcurrentActivityExecutionSize: 4 } }

# after
temporal:
    worker_options:
        default: { max_concurrent_activity_execution_size: 10 }
        billing: { max_concurrent_activity_execution_size: 4, workflow_panic_policy: FailWorkflow }
```

- Option keys are snake_case; enums take the case name. A queue no `#[TaskQueue]` uses now fails the build.
- Custom worker class → move its options to `worker_options.<queue>` and delete the class.
- Custom `TemporalWorkerFactoryInterface` for a data converter → alias `Temporal\DataConverter\DataConverterInterface` to your converter. Anything else → decorate `Temporal\Worker\WorkerFactoryInterface`.
- `TemporalIntrospectorInterface::workerSummaries()` returns `{taskQueue, options}` instead of `{class, taskQueue}`.

**Commands renamed.** `temporal:debug` → `debug:temporal`; `temporal:diagram -o flow.mmd` → `debug:temporal --format=mermaid > flow.mmd`; `centrifugo:debug` → `debug:centrifugo`.

**Interceptors you define now run.** Any service implementing a Temporal SDK interceptor interface joins the pipeline. Before, only the bundle's own did — check that yours are meant to run.

**OpenTelemetry wires itself** when `temporal/open-telemetry-interceptors` is installed. Registered its interceptors yourself? Remove your definitions, or you get every span twice.

**Activity failures are logged** on the `temporal` channel (error level) and sent to Sentry on the first attempt.

## v7 → v8

### Centrifugo

**Breaking: an unanswered Centrifugo request is now denied.** It used to be accepted — anonymous connect, publish passed through, refresh never expired.

| Request | Before | Now |
|---|---|---|
| Connect | accepted, user `''` | disconnect `4500 forbidden` |
| Publish, Subscribe, RPC | accepted | error `403 forbidden` |
| Refresh, SubRefresh | accepted, no expiry | `expired: true` |

- Relied on the implicit accept → set it explicitly: `$event->setResponse(new PublishResponse())`.
- Refusing by throwing → use `$event->reject($code, $message)` or `$event->disconnect($code, $reason)` instead. Throwing still works but counts as a crash (Sentry, error log, kernel reboot). See [Refusing a request](README.md#refusing-a-request).

### `$request->server`

**`$request->server` is now built from the RoadRunner request alone** — it used to start as a copy of the worker's boot-time `$_SERVER`.

Kept: `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `SERVER_PROTOCOL`, `SERVER_NAME`, `SERVER_PORT`, `HTTPS`, `REMOTE_ADDR`, `REQUEST_TIME`, `REQUEST_TIME_FLOAT`, `HTTP_HOST`, `CONTENT_TYPE`, `CONTENT_LENGTH`, `HTTP_*` headers.

Gone: env vars, `argv`, `SCRIPT_NAME`, `SCRIPT_FILENAME`, `PHP_SELF`, `DOCUMENT_ROOT`.

- Read config from the container / `$_ENV` / `$_SERVER` — untouched, still the boot-time environment.
- `HTTP_*` env vars (`HTTP_PROXY`) no longer turn into request headers.
- `getBaseUrl()` / `getScriptName()` now always return `''`.

### Boot failures

**A boot failure now answers the client** instead of killing the worker before its first request (RoadRunner returned its own error with an `EOF` body).

- Debug: Symfony error page. Prod: bare 500.
- Failed `WorkerBootingEvent` listener → keeps serving; only warmup is lost.
- Logged as `[roadrunner-symfony] BOOT FAILURE` — alert on that marker.
- A broken worker answers RoadRunner's PID probe, so the pool starts healthy instead of failing at `rr serve`.
- **Breaking:** `protected HttpWorker::renderHtmlError()` removed. Override `HttpWorker::getThrowableResponder()`, return a `WorkerErrorResponder` subclass.
- **Breaking:** `Runtime\Runner` is no longer a `readonly` class — a `readonly` subclass must drop the modifier.

## v7.0 → v7.1

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
