# Upgrade guide

## v7.0 → v7.1

**Boot failures now answer the client** instead of killing the worker before it ever
receives a request (RoadRunner used to return its own error with an `EOF` body). Debug gets
the Symfony error page; in prod a kernel boot failure returns a bare 500, while a failed
`WorkerBootingEvent` listener keeps serving — the kernel is fine, only warmup was lost.
Everything is logged as `[roadrunner-symfony] BOOT FAILURE`.

- A broken worker now answers RoadRunner's PID probe, so the pool comes up healthy instead
  of failing at `rr serve` startup — alert on the `BOOT FAILURE` marker.
- **Breaking:** the `protected HttpWorker::renderHtmlError()` seam was removed; override
  `HttpWorker::getThrowableResponder()` and return a `WorkerErrorResponder` subclass instead.

**`http.request_factory` — the HTTP worker now converts RoadRunner requests to Symfony
requests directly by default**, skipping the intermediate PSR-7 object (about half the
conversion cost per request).

- No action needed for typical apps. The default (`auto`) keeps the previous PSR-7 path
  automatically when a custom `Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface`
  service is registered; set `http.request_factory: psr7` to force the previous behavior
  explicitly.
- Deliberate behavior differences of the native path: uploaded files are the base
  `HttpFoundation\File\UploadedFile` class pointing at RoadRunner's temp file instead of a
  bridge-subclass copy; header values are forwarded verbatim without PSR-7 re-validation;
  `QUERY_STRING`/`REQUEST_URI` keep the raw wire encoding.
- Passing a custom `HttpFoundationFactoryInterface` as the `HttpWorker` constructor's
  `$httpFoundationFactory` argument is deprecated (it still works and keeps forcing the
  PSR-7 path when no explicit strategy is injected); register the service in the container
  or pass a `SymfonyRequestFactoryInterface` strategy instead.
- Request-conversion errors (malformed JSON body flagged as parsed, unparseable request
  URI, PSR-7-invalid headers on the psr7 path) previously short-circuited to a bare
  `418 I'm a teapot` response with no Sentry capture and no error page. They now follow
  the normal graceful-error path: Sentry capture, 500/debug error page, worker reboot on
  fatal errors. The 418 short-circuit still applies to transport/frame-decode errors.
- The `$_SERVER` superglobal is no longer overwritten per request (the previous
  PSR-7 chain replaced it with request-enriched values on every request). It now keeps
  the worker's boot-time environment only, matching the documented behavior in the
  README's reverse-proxy section. Read request data from the Symfony `Request` object
  (`$request->server`, `$request->headers`) instead of `$_SERVER`.
- The Centrifugo worker no longer calls `$kernel->boot()` on every event (it was a no-op
  guard after the first event); lazy boot still happens on the first event and per-event
  service resets are unchanged.

## v6 → v7

**Breaking:** `http.early_router_initialization` was removed, replaced by the
zero-config [worker warmup](README.md#worker-warmup).

- Remove `http.early_router_initialization` from
  `config/packages/fluffy_discord_road_runner.yaml` — the unknown key throws on boot.
- The boot-time dummy request is gone and `HttpWorker::DUMMY_REQUEST_ATTRIBUTE` no
  longer exists — delete any listener that checked it.
