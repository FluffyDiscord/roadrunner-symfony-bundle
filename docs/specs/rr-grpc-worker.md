# RoadRunner gRPC Worker (Implementation)

**Source pinned to:** `master` @ `e1aa45e`, 2026-08-20.
**Component group:** new `Worker\GrpcWorker` registered under `Spiral\RoadRunner\Environment\Mode::MODE_GRPC`, plus `src/Grpc/*` (service registry + routing table, invoker, frame codec, metadata accessor, security, introspector, tracing), `Event\Grpc\*`, `Exception\Grpc\*`, `Profiler\Grpc*`, `Command\GrpcDebugCommand`, `config/grpc.php`, the `grpc` configuration node and a `MODE_GRPC` branch in `Runtime\Runner`.
**Revision:** rev2b (2026-08-20) — Gate 3/4 (two iterations each) fixes + user additions: full Symfony-profile per call, inbound auth via Symfony Security, TLS surfaced in `grpc:debug`, typed metadata accessor.
**Dependency added:** `spiral/roadrunner-grpc` (installed `v3.6.0`; constraint `^3.6`) as `require-dev` + `suggest` — the same optional-gating model as `temporal/sdk`, `spiral/roadrunner-jobs`, `spiral/roadrunner-kv`, `roadrunner-php/centrifugo`.
**Scope decision:** a fifth worker type (gRPC server) that keeps the **spiral interface route** — application services implement the `protoc-gen-php-grpc`-generated `*Interface extends Spiral\RoadRunner\GRPC\ServiceInterface` — and lets the bundle discover, wire, observe and debug them the way it already does for Temporal and Centrifugo.

This is **brownfield delta** work: worker registration, boot-failure handling, graceful error handling, the profiler/debug-command pattern and the live-validation harness already exist and are recorded below from the code (file+line). The gRPC worker is specified as a delta against them.

---

## 1. Reverse-engineered baseline (cited @ `e1aa45e`)

| # | Fact about the existing system | Evidence |
|---|--------------------------------|----------|
| B1 | Workers implement `WorkerInterface` with a single `start(): void`. | `src/Worker/WorkerInterface.php:5-8` |
| B2 | `Runner::run()` boots the kernel, resolves `WorkerRegistry`, looks the worker up by RR mode and calls `start()`; a missing worker logs `This bundle does not support worker "%s" yet` and returns 1. | `src/Runtime/Runner.php:28-50` |
| B3 | Kernel boot failure inside `Runner` is answered to the client **only for `MODE_HTTP`** (`handleBootFailure` returns 1 for every other mode). Workers that boot the kernel themselves catch the boot throwable and call `reportBootFailure()` (STDERR + Sentry), then keep serving — the recent "answer the client on boot failure instead of dying" behaviour. | `src/Runtime/Runner.php:53-75`; `src/Worker/TemporalWorker.php:37-43`; `src/Worker/JobsWorker.php:41-47`; `src/ErrorHandler/BootFailureReporting.php` |
| B4 | Each optional subsystem has its own `config/<name>.php`, **imported from `config/services.php`** (`$container->import('temporal.php')` …), returning early when the third-party marker class is missing (`class_exists(WorkflowInterface::class)` for Temporal); it registers the worker as `public()` and calls `WorkerRegistry::registerWorker(Mode::MODE_*, service(Worker))`. Package-presence gating lives in `config/*.php`; config-*value* gating lives in the Extension as programmatic `Definition`s (`registerTemporalTracing`, `registerWarmup`). | `config/services.php:96-100`; `config/temporal.php:53-84`; `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:303-322` |
| B5 | Debug-only services (data collector tagged `data_collector` with `template`/`id`/`priority`, profiler subscriber tagged `kernel.event_subscriber` + `kernel.reset`) live in `config/debug.php`, loaded only when `kernel.debug`. | `config/debug.php:14-32`; `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:71-78` |
| B6 | Non-HTTP work is surfaced in the Symfony profiler by **synthesising a `Profile`** per worker request: the subscriber listens to `WorkerRequestReceivedEvent` / the domain event / `WorkerResponseSentEvent`, fills a dedicated `DataCollector` via `populate(...)`, builds `new Profile($token)` with `setUrl('/centrifugo/<type>')`, `setMethod('CENTRIFUGO')`, `setStatusCode(200|500)`, `addCollector`, `saveProfile`. The collector's `collect()` is a deliberate no-op. | `src/Profiler/CentrifugoProfilerSubscriber.php:49-126`; `src/Profiler/CentrifugoDataCollector.php:25-28` |
| B7 | Profiler templates live in `src/Resources/views/Collector/*.html.twig` and extend `@WebProfiler/Profiler/layout.html.twig` with `toolbar` / `menu` / `panel` blocks. | `src/Resources/views/Collector/centrifugo.html.twig:1-60` |
| B8 | Debug console commands are `#[AsCommand(name: '<subsystem>:debug', description: '... (no server connection).')]`, autowired + autoconfigured from the subsystem's config file, and render Symfony `Table`s from a dedicated introspector service. | `src/Command/TemporalDebugCommand.php:13`; `src/Command/CentrifugoDebugCommand.php:12`; `config/temporal.php:240-250` |
| B9 | Opt-in tracing: `Extension::prepend()` prepends a Monolog channel when the marker class exists and the `monolog` extension is present; `registerTemporalTracing()` defines a listener with `monolog.logger.<channel>` / `request_stack` / Sentry hub (all `NULL_ON_INVALID_REFERENCE`) and one `kernel.event_listener` tag per event (`event` + `method`) only when `<subsystem>.tracing === true`. | `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:59-67,168-170,303-315` |
| B10 | Configuration nodes are added per subsystem via `add<Name>Node(ArrayNodeDefinition)` **behind a `class_exists` gate on a package class** (`WorkerOptions` for Temporal), with `->info($this->toInfo([...]))`, the sentence `Will activate only when "<package>" is installed.`, and `addDefaultsIfNotSet()`. | `src/DependencyInjection/Configuration.php:257-259,264-342` |
| B11 | Subsystem-specific compiler passes are added in `FluffyDiscordRoadRunnerBundle::build()` behind the same `class_exists` guard. | `src/FluffyDiscordRoadRunnerBundle.php:16-30` |
| B12 | Worker lifecycle events: `WorkerBootingEvent` (after kernel boot), `WorkerRequestReceivedEvent` (per request), `WorkerResponseSentEvent(string $workerType)` (per response, carrying the `Mode::MODE_*` string). | `src/Event/Worker/*.php`; `src/Worker/JobsWorker.php:46,71,82` |
| B13 | Graceful error handling in request-loop workers: Sentry `pushScope`/`captureException`/`flush`/`popScope` best-effort; `\Error` → `rrWorker->stop()`; `RebootableInterface::reboot(null)` after an exception; `ServicesResetterInterface::reset()` in `finally`; `register_shutdown_function` rescue when the loop dies mid-request without having answered. | `src/Worker/JobsWorker.php:54-162`; `docs/specs/graceful-error-handling.md` |
| B14 | The RR `Spiral\RoadRunner\WorkerInterface` is already a bundle service (`createFromEnvironment`), as is `EnvironmentInterface`. | `config/services.php:40-47` |
| B15 | Wiring tests load `config/services.php` into a bare `ContainerBuilder` (`kernel.debug` param set) and assert definitions/aliases/`registerWorker` calls. | `tests/Temporal/TemporalServiceWiringTest.php:26-51` |
| B16 | Live validation: `tests/docker-validate-<feature>.sh` builds one Docker image per PHP version (8.4, 8.5) containing a real `rr` server and runs a `#[Group('<feature>-live')]` PHPUnit test as the client; `tests/docker-validate-all.sh` already installs **ext-grpc** (for the Temporal client). | `tests/docker-validate-temporal.sh:1-60`; `tests/docker-validate-all.sh:1-20,550-568` |
| B17 | `Mode::MODE_GRPC === 'grpc'` is already defined by the installed RR worker package. | `vendor/spiral/roadrunner-worker/src/Environment/Mode.php` |
| B20 | `Profiler::collect(Request $request, Response $response, ?\Throwable $exception = null): ?Profile` runs every registered collector and returns a `Profile`; `saveProfile(Profile)`. `RequestDataCollector` tolerates a request without routing attributes (`_route` defaults to `'n/a'`, `_controller` parsed when present); `TimeDataCollector` falls back to `REQUEST_TIME_FLOAT` when no kernel is injected. | `vendor/symfony/http-kernel/Profiler/Profiler.php:88,130`; `DataCollector/RequestDataCollector.php:158-160`; `DataCollector/TimeDataCollector.php:37-39` |
| B21 | Profile token derivation used by the Centrifugo subscriber: `substr(hash('xxh128', uniqid('rr_centrifugo_', true)), 0, 6)`. | `src/Profiler/CentrifugoProfilerSubscriber.php:117` |
| B22 | `Extension::getRoadRunnerConfig()` dials RR's RPC at **compile time** (`rpc.Config`) and falls back to `readRoadRunnerYaml()`; Temporal/KV need it at compile time because they create definitions from it. | `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:201-256` |
| B23 | FrameworkBundle's profiler for console commands ("virtual requests", Symfony ≥ 7.3): `CliRequest extends Request` with attributes `_controller` + `_virtual_type => 'command'` and overridden `getUri()`/`getMethod()`; pushed onto the `.virtual_request_stack` service (`VirtualRequestStack`), which the `request`, `events`, `logger` collectors and the traceable dispatcher/log processor read (`ignoreOnInvalid`); `ConsoleProfilerListener` sets `_stopwatch_token`, `stopwatch->openSection()`, later `stopSection($token)`, then `Profiler::collect($request, $response, $error)`; `Profiler::collect()` copies `_virtual_type` into `Profile::setVirtualType()`. | `vendor/symfony/console/Debug/CliRequest.php:25-46`; `vendor/symfony/framework-bundle/Resources/config/profiling.php:47,59`; `collectors.php:34,52,60`; `framework-bundle/EventListener/ConsoleProfilerListener.php:82-125`; `http-kernel/Profiler/Profiler.php:147-148` |
| B24 | `LoggerDataCollector`/`EventDataCollector` filter their records by the current request of the (virtual) request stack; `TraceableEventDispatcher` buckets calls by `spl_object_hash($currentRequest)` (or `''` with none) — a request created **after** the work ran matches nothing. `DumpDataCollector::collect()` writes buffered `dump()` output to `php://output` when the request **is** the stack's current request and the response has no HTML body. `TimeDataCollector` is wired with `service('kernel')` and uses `Kernel::getStartTime()` first; `REQUEST_TIME_FLOAT` is only the no-kernel fallback. | `vendor/symfony/http-kernel/DataCollector/LoggerDataCollector.php:43`; `EventDataCollector.php:51`; `event-dispatcher/Debug/TraceableEventDispatcher.php:143-158`; `DumpDataCollector.php:103-125`; `TimeDataCollector.php:34-39`; `framework-bundle/Resources/config/collectors.php:65-68` |
| B25 | The bundle's `RoadRunnerMicroKernelTrait::boot()` refreshes `Kernel::$startTime` on every call in debug (so per-request `boot()` calls give the profiler a correct start time); `JobsWorker` calls `kernel->boot()` per task. README mandates the trait. | `src/Kernel/RoadRunnerMicroKernelTrait.php:12-17`; `src/Worker/JobsWorker.php:72` |
| S2 | `AccessTokenAuthenticator::authenticate()` replaces the badge's loader with the provider when the loader is `null` **or** `instanceof FallbackUserLoader` (`@internal`, returned by the OIDC handlers); the HTTP path runs `UserCheckerInterface::checkPreAuth/checkPostAuth` via `CheckPassportEvent`; SecurityBundle aliases `UserCheckerInterface` to the firewall user checker; `AuthorizationChecker::isGranted()` substitutes a `NullToken` when the storage is empty. | scratch: `security-http/Authenticator/AccessTokenAuthenticator.php:58-66`; `security-core/User/UserCheckerInterface.php:32-39`; `security-bundle/DependencyInjection/SecurityExtension.php:358`; `security-core/Authorization/AuthorizationChecker.php:45-49` |
| S1 | Symfony Security (verified in `symfony/security-http` + `security-core` + `security-bundle` v8.1.1 sources; the bundle supports 7.4/8.x and the same symbols exist in 7.4): `AccessTokenHandlerInterface::getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge`; `UserBadge::getUser()` throws `\LogicException` when no user loader is set, `setUserLoader(callable)` attaches one, a `null` load → `UserNotFoundException`; `PostAuthenticationToken(UserInterface $user, string $firewallName, array $roles)`; `TokenStorageInterface::setToken(?TokenInterface)`; `AuthorizationCheckerInterface::isGranted(mixed $attribute, mixed $subject = null)`; `#[IsGranted(string\|Expression\|\Closure $attribute, $subject = null, ?string $message, ?int $statusCode, ?int $exceptionCode, $methods)]`; SecurityBundle aliases `UserProviderInterface` to the single configured provider and tags `security.token_storage` with `kernel.reset` → `setToken(null)`. | scratch copy: `security-http/AccessToken/AccessTokenHandlerInterface.php`; `security-http/Authenticator/Passport/Badge/UserBadge.php:45-122`; `security-http/Authenticator/Token/PostAuthenticationToken.php:24-28`; `security-core/Authentication/Token/Storage/TokenStorage.php:40`; `security-core/Authorization/AuthorizationCheckerInterface.php:27`; `security-http/Attribute/IsGranted.php:38-45`; `security-bundle/DependencyInjection/SecurityExtension.php:304`; `security-bundle/Resources/config/security.php:73-81` |
| B18 | `rr download-protoc-binary` (installs `protoc-gen-php-grpc`) ships with the already-required `spiral/roadrunner-cli`. | `vendor/spiral/roadrunner-cli/src/DownloadProtocBinaryCommand.php:38,49` |
| B19 | `Runtime::resolveRuntimeMode()` reads `.rr.yaml` `<section>.pool.debug` only for `http`/`centrifuge` (`match` with `default => null`) to decide `worker=0` vs `worker=1`; every other mode gets `worker=1` even with `pool.debug: true`. | `src/Runtime/Runtime.php:35-62` |

## 2. `spiral/roadrunner-grpc` API surface (reverse-engineered, cited — `v3.6.0`)

| # | Fact | Evidence |
|---|------|----------|
| G1 | `Server` is **`final`**; ctor `(InvokerInterface $invoker = new Invoker(), array $options = [])`, `options['debug']` controls whether non-gRPC throwables are reported to RR as `(string) $e` (full trace) or `$e->getMessage()`. | `Server.php` ctor, `isDebugMode()` |
| G2 | `Server::registerService(string $interface, ServiceInterface $service)` wraps the pair in a `ServiceWrapper`, keyed by the interface's `NAME` constant; `ServiceWrapper::configure()` **requires `NAME` to be a string constant on the interface**, requires `$service instanceof $interface`, and enumerates handler methods by reflecting the **service object** (`ReflectionObject`, public methods matching `Method::match`). A violation throws `ServiceException` (gRPC `INTERNAL`). | `Server.php::registerService`; `ServiceWrapper.php::configure/fetchMethods` |
| G3 | `Server::serve(?WorkerInterface $worker = null, ?callable $finalize = null)` owns the request loop: `waitPayload()` → `null` returns; `CallContext::decode($request->header)` yields `service`, `method`, `context` (the metadata map RR sends); a fresh `Context` is built with `ResponseHeaders::class` / `ResponseTrailers::class` entries; `invoke()`; responds with `Payload(body, headers-json)`. `GRPCExceptionInterface` → responds `{"error": base64(google.rpc.Status)}` with empty body; any other `\Throwable` → `$worker->error(message)`. `$finalize` is called in `finally` **on every iteration**, as `$finalize($e)` when a throwable was caught and `$finalize()` otherwise. | `Server.php::serve` |
| G4 | `Server::invoke()` is `protected` but the class is `final` → the only per-call extension points are (a) a custom `InvokerInterface` and (b) `$finalize`. Unknown service → `NotFoundException` (`NOT_FOUND`) thrown **before** the invoker is reached; unknown method → `NotFoundException` thrown by `ServiceWrapper::invoke`, also before the invoker. | `Server.php::invoke`; `ServiceWrapper.php::invoke` |
| G5 | `InvokerInterface::invoke(ServiceInterface $service, Method $method, ContextInterface $ctx, ?string $input): string`. The concrete `final Invoker` widens the input to `string\|Message\|null` and checks the result type only with a PHP `assert()` (disabled under `zend.assertions=-1`). It decodes `$input` into `new $method->inputType()` + `mergeFromString`, calls `[$service, $method->name]($ctx, $input)`, asserts a `Message` result and returns `serializeToString()`; decode/encode failures → `InvokeException` (`INTERNAL`). | `Invoker.php` |
| G6 | `Method` is `final` with public readonly `name`, `inputType`, `outputType`; `Method::match(\ReflectionMethod)` / `Method::parse(\ReflectionMethod)` validate the `(ContextInterface $ctx, <Message> $in): <Message>` signature and work on **interface** methods as well as object methods (pure reflection). | `Method.php` |
| G7 | `ContextInterface` = `withValue`, `getValue`, `getValues`; `Context::__construct(array $values)` is `final`, immutable-by-`withValue`, and additionally `ArrayAccess`/`Countable`/`IteratorAggregate`. `ResponseHeaders` / `ResponseTrailers` are `final` string maps: `set(string, string)`, `get(string, ?string)`, `count()`, `getIterator()`, `packHeaders(): string` / `packTrailers(): string` (JSON object, `'{}'` when empty). | `ContextInterface.php`; `Context.php`; `ResponseHeaders.php`; `ResponseTrailers.php` |
| G8 | `GRPCException extends \RuntimeException implements MutableGRPCExceptionInterface`, ctor is `final`, `static create(message, code = static::CODE, ?previous, details = [])`; subclasses `NotFoundException`, `InvokeException`, `ServiceException`, `UnauthenticatedException`, `UnimplementedException`. `StatusCode` is a `final` class of `int` constants `OK=0 … UNAUTHENTICATED=16`. | `Exception/*.php`; `StatusCode.php` |
| G9 | `ServiceInterface` is an **empty marker interface**; generated service interfaces extend it and carry `public const NAME = '<package>.<Service>'`. | `ServiceInterface.php`; `ServiceWrapper.php::configure` |
| G10 | The wire error is built with `Google\Rpc\Status` + `Google\Protobuf\Any` (from `google/common-protos` / `google/protobuf`, hard deps of the package) and base64-encoded into the `error` header key. | `Server.php::createGrpcError`; package `composer.json` |
| G11 | `Internal\CallContext` and `Internal\Json` are in an `Internal` namespace — not part of the public contract. | `src/Internal/*` |
| G13 | `UnauthenticatedException extends InvokeException` with `CODE = StatusCode::UNAUTHENTICATED` (16); `StatusCode::PERMISSION_DENIED = 7`. | `Exception/UnauthenticatedException.php`; `StatusCode.php` |
| G12 | RR side (official docs, fetched 2026-08-20): `.rr.yaml` `grpc:` block — `listen`, `proto: [...]` (proto files RR loads to route calls), `tls`, `max_send_msg_size`/`max_recv_msg_size` (MB), `max_concurrent_streams`, `ping_time`, `timeout`, `pool`. RR auto-registers `grpc.health.v1.Health` and flips to `SERVING` once workers are up. `RR_MODE=grpc`. | https://docs.roadrunner.dev/docs/grpc/grpc |

## 3. The 7 Questions (brownfield — answers settled by the existing system are recorded as-is)

1. **Exact problem, for which actor:** A Symfony developer running this bundle under RoadRunner can serve HTTP, Centrifugo, Jobs and Temporal pools but **cannot serve a RoadRunner `grpc` pool** — `Runner` finds no `MODE_GRPC` worker (B2) and exits with `This bundle does not support worker "grpc" yet`. Goal: the developer generates PHP interfaces with `protoc-gen-php-grpc`, writes a plain autowired Symfony service `class GreeterService implements GreeterInterface`, and the bundle serves it with the same boot-failure answer, graceful error handling, events, Sentry, tracing channel and `grpc:debug` command the other subsystems have — **plus** a Symfony-profiler experience equal to HTTP requests (one full profile per call: request/response messages, logs, Doctrine, events, timing), inbound authentication through Symfony Security, and typed access to call metadata.
2. **Acceptance criteria:** (a) `rr serve` with a `grpc` pool starts `GrpcWorker` and `grpcurl` receives the handler's response (IT-01); (b) a handler throwing `GRPCException(code)` surfaces as that exact gRPC status (IT-02); (c) a handler throwing any other `\Exception` surfaces as a non-OK status, is logged to STDERR and the same worker process answers the next call (IT-03; an `\Error` additionally stops the worker so RR replaces it); (d) `GrpcCallReceivedEvent` / `GrpcCallCompletedEvent` / `GrpcCallFailedEvent` fire per call with decoded messages (TC-05..07, TC-12, IT-04); (e) a kernel-boot or routing-table failure answers each call that reaches a worker with `UNAVAILABLE`, and boot is retried by a fresh process (TC-16, TC-18, IT-05); (f) `grpc:debug` lists every registered service, its methods/types and the RR listen/TLS facts (TC-19, IT-06); (g) in `kernel.debug` every call produces a full Symfony profile (`grpc://<service>/<method>`, method `GRPC`, virtual type `grpc`) whose gRPC panel shows the decoded request and response and whose logger/time panels show the call's work (TC-20, TC-31, IT-07); (h) with `grpc.security.enabled: true`, a call with a valid `authorization: Bearer …` metadata reaches the handler with `Security::getUser()` populated, a missing/invalid token yields `UNAUTHENTICATED`, and a `#[IsGranted]` the user fails yields `PERMISSION_DENIED` (TC-26..29, IT-08); (i) PHPStan level max → 0 errors; (j) `php vendor/bin/phpunit tests` fully green with the §N-2 IDs added.
3. **Existing contracts touched:** `WorkerRegistry::registerWorker` (new mode entry, `config/grpc.php`); `Configuration` tree (new `grpc` node, B10); `FluffyDiscordRoadRunnerExtension` (`prepend()` Monolog channel `grpc`, `load()` autoconfiguration + tracing + security registration; `readRoadRunnerYaml()` delegates to `RoadRunnerYamlConfigReader`, §4.17); `FluffyDiscordRoadRunnerBundle::build()` (new `GrpcServicePass`, B11); `config/debug.php` (collector + subscriber, B5); `Runtime\Runner::handleBootFailure()` (new `MODE_GRPC` branch, §4.7); `Runtime\Runtime::resolveRuntimeMode()` (`grpc` arm, §4.12); `composer.json` `require-dev`/`suggest` (G35: via `composer require --dev`); `tests/docker-validate-all.sh` (new gRPC pool + proto + `grpc-live` gate). **No existing public class signature changes.**
4. **Core design decision (ADR-1):** `GrpcWorker` **owns its request loop** over `Spiral\RoadRunner\WorkerInterface::waitPayload()` — the same shape as `JobsWorker`/`CentrifugoWorker` (B13) — and uses `spiral/roadrunner-grpc` for the **contract**, not the loop: `ServiceInterface` + generated interfaces, `Method` (signature validation/parsing), `Context`/`ContextInterface`, `ResponseHeaders`/`ResponseTrailers`, `GRPCException*`/`StatusCode` (the bundle's `GrpcInvoker` replaces spiral's `Invoker`, ADR-7). Trade-off: the bundle owns ~40 lines of RR gRPC frame protocol (three-key JSON header in, `headers`/`trailers`/`error` JSON out — unchanged between the spiral/roadrunner-grpc v2 and v3 PHP sources; the Go side is A1/A5) instead of inheriting `final Server::serve()`; in exchange every sibling invariant holds per frame. Gate 3 (2026-08-20) found the `Server::serve` + decorator design produced a Sentry-scope leak, reboot-on-intentional-status and per-frame event gaps; the owned loop was adopted.
5. **Irreversible here (Clarity check 7 list):** (i) the public event classes `Event\Grpc\*` and their constructor shapes; (ii) the DI tag name `fluffy_discord.roadrunner.grpc.service`; (iii) the `grpc` configuration node (`tracing`, `security.*`); (iv) the `GrpcServiceRegistry` public API; (v) the Monolog channel name `grpc`; (vi) **security model**: the bundle authenticates gRPC calls from a bearer token in call metadata through the app's `AccessTokenHandlerInterface` and puts a `PostAuthenticationToken` (firewall name `grpc`) into the token storage — the user chose this on 2026-08-20 (Tier-3 sign-off for this list item). No DB, no deletes.
6. **Smallest shippable slice:** everything in §4. Nothing in §4 is deferred.
7. **NOT doing:** a gRPC **client** / outbound credentials (RR's plugin is server-only; a client needs `ext-grpc` + `grpc/grpc`; the user deselected it); a `#[AsGrpcService]` attribute (the generated interface already identifies the service; ADR-2); `lazy_boot` (ADR-3); server/client streaming (the PHP contract is unary-only: `Method` requires exactly `(ctx, Message): Message`, G6); a Mermaid diagram command; cross-checking registered `NAME`s against the services declared in `.rr.yaml` `grpc.proto` files (a PHP service absent from the protos is `UNIMPLEMENTED` at RR and invisible to the worker — documented, not detected); generating proto/PHP stubs at build time (documented `protoc` workflow, G12/B18); an interceptor/middleware chain beyond the event dispatcher; a user-replaceable invoker (replacing it would silently disable every event); running Symfony **firewalls** for gRPC calls (firewalls are bound to `RequestEvent`/HTTP; the bundle authenticates via the access-token handler contract directly, ADR-9); peer-certificate identity for mTLS (RR terminates TLS; whether it forwards peer-cert facts into the frame `context` is unverified — surfaced as OQ-4, not built).

## ADRs

| ID | Decision | Alternatives rejected | Consequence |
|----|----------|-----------------------|-------------|
| ADR-1 | Own the request loop (see Q4). Spiral supplies the contract types; the bundle supplies `GrpcInvoker`, frame codec and the loop. | Reuse `final Server::serve()` + `InvokerInterface` decorator + `$finalize` (Gate 3: scope leak, reboot on `GRPCException`, no event/Sentry scope for unroutable frames, needs a `kernel.reset` tracker service, no hook to re-resolve services after a kernel reboot). | The bundle depends on the RR gRPC frame protocol (§4.4) — covered by the live tests, which fail loudly if RR changes it. |
| ADR-2 | Service discovery = `registerForAutoconfiguration(ServiceInterface::class)->addTag('fluffy_discord.roadrunner.grpc.service')` + `GrpcServicePass` that resolves, per tagged definition, every implemented interface that **declares** a string `NAME` constant itself, and records `(interface, serviceId, class)` on `GrpcServiceRegistry` with a `ServiceLocator`. A `NAME` seen from two service ids is a compile-time error. | `#[AsGrpcService]` attribute; `instanceof()` in `config/grpc.php` (file-scoped); definition scan à la `TemporalWorkerPass` (needed there only for attribute markers on interfaces). | Non-autoconfigured services add the tag manually (documented). |
| ADR-3 | No `grpc.lazy_boot`: `Runner` has already booted the kernel before any worker exists (B2) — laziness buys nothing. | Lazy routing on first call. | Routing table built once per boot (and after a reboot). |
| ADR-4 | Boot failure answers the client with gRPC `UNAVAILABLE` (14) **once**, then exits so RR respawns and retries boot (the `HttpWorker::serveBootFailure` shape). Two sites: `Runner::handleBootFailure()` gains a `MODE_GRPC` branch (the only place that sees container/compile/env failures, D-O1), and `GrpcWorker::start()` does the same when building the routing table fails. A failing `WorkerBootingEvent` **listener** is reported and the worker keeps serving (B3). | Serve `UNAVAILABLE` forever in a loop (never retries boot); exit 1 silently (clients hit RR's allocate timeout). | Respawn churn under sustained failure is the same churn HTTP accepts today. |
| ADR-5 | Sentry: `pushScope()` per frame; `captureException()` for every throwable that is **not** a `GRPCExceptionInterface`, plus for the bundle's own `INTERNAL`-coded `GrpcHandlerFaultException` / `GrpcSecurityConfigurationException` (server-side defects converted into a status, §4.3/§4.15); `flush()` + `popScope()` in `finally`. | Capture everything; capture nothing that carries a status. | Intentional statuses are not incidents (like HTTP 4xx); a wrong return type is. |
| ADR-6 | Profiler: one **full** Symfony profile per frame using FrameworkBundle's own virtual-request facility (B23): a `Profiler\GrpcRequest extends Request` (`_virtual_type => 'grpc'`, `_stopwatch_token` at construction, `_controller => Handler::method` once the frame is decoded; `getUri()` → `grpc://<NAME>/<method>`, `getMethod()` → `GRPC`) is pushed onto `.virtual_request_stack` on `WorkerRequestReceivedEvent` and a stopwatch section opened, so logs/events/Doctrine/time/memory/security collectors attribute the frame's work to it; on `WorkerResponseSentEvent` the section is stopped, the request **popped**, then `Profiler::collect($request, $response, $throwable)` + `saveProfile()`. HTTP status derived from `GrpcCallFailedEvent::$workerStatusCode` only: `200` OK; `500` for `INTERNAL\|UNKNOWN\|UNAVAILABLE\|DATA_LOSS`; `400` for every other code. Degrades to a collect-only profile when `.virtual_request_stack`/`debug.stopwatch` are absent (`nullOnInvalid`). | Centrifugo-style single-collector synthetic `Profile` (no logs/DB/events — not "like HTTP requests"); `Profiler::collect()` on a request built after the fact (Gate 3 rev2: logs/events panels empty, B24); pushing onto the real `request_stack` (would affect `UsageTrackingTokenStorage`, Twig `app.request`, firewalls). | Popping **before** `collect()` is load-bearing: it keeps `DumpDataCollector` from writing `dump()` output to STDOUT — the goridge relay (B24). The per-frame `kernel->boot()` in the loop (B25) gives the timeline the frame's start time. Metadata is shown **with values**, redacted for `authorization`, the configured `security.metadata_key`, and `*-bin` keys (summarised as `<binary, N bytes>`) — HTTP parity without storing credentials. |
| ADR-7 | Events carry **decoded** `Google\Protobuf\Internal\Message` objects, produced by the bundle-owned `GrpcInvoker`; the result guard is `instanceof $method->outputType`. | Raw protobuf bytes. | Listeners and the profiler work with fields; a wrong return type is an `INTERNAL` fault, not an `assert()`. |
| ADR-8 | In `kernel.debug` the client receives `(string) $throwable` (full trace) for unhandled throwables; in prod only `$throwable->getMessage()`. The same rule applies to the boot-failure message in both sites (§4.6/§4.7). | Always message-only. | Mirrors `WorkerErrorResponder` (B3); stated in `docs/grpc.md` because gRPC clients are usually other services. |
| ADR-9 | Inbound auth = `Grpc\Security\GrpcAccessTokenAuthenticator`: bearer token from call metadata → the app's `AccessTokenHandlerInterface` (the same contract the `access_token` firewall authenticator uses, S1) → `UserBadge` → user (badge loader, else the autowired `UserProviderInterface`) → `PostAuthenticationToken($user, 'grpc', $user->getRoles())` into `TokenStorageInterface`; `#[IsGranted]` on the generated-interface method or the handler method is evaluated with `AuthorizationCheckerInterface` after authentication. `UserCheckerInterface::checkPreAuth/checkPostAuth` run like the HTTP path (S2). The authenticator sits behind `Grpc\Security\GrpcCallAuthenticatorInterface` (one method) so mTLS/API-key schemes can replace it without touching the worker. Failures map to `UNAUTHENTICATED` / `PERMISSION_DENIED`; an anonymous caller hitting `#[IsGranted]` gets `UNAUTHENTICATED`, not `PERMISSION_DENIED`. | Run the Symfony firewall with a synthetic `RequestEvent` (firewall map is path-based and HTTP-bound; `access_token` authenticator reads HTTP headers; brittle); `Security::login()` / `AuthenticatorManager` (need a request on the **real** `request_stack`, run the firewall map against `/grpc/...`, skip `CheckPassportEvent` anyway — Gate 3 rev2). | Apps without SecurityBundle: the whole §4.15 is gated on `interface_exists(AccessTokenHandlerInterface::class)`; `grpc.security.enabled: true` without it is a configuration error. |

## 4. Design

> The pseudo-code in this section shows **shape only**; the implementation follows the house rules (CR1–CR8: no comments, no calls inside conditions, one operation per condition, named temporaries, no `?? throw`).

### 4.1 `Event\Grpc\*` (new, public contract — irreversible)

All extend `Symfony\Contracts\EventDispatcher\Event`; all properties `public readonly`.

```php
class GrpcCallReceivedEvent extends Event
{
    public function __construct(
        public readonly string           $serviceName,   // NAME constant, e.g. "bundle.test.Echo"
        public readonly string           $methodName,
        public readonly ServiceInterface $service,
        public readonly Method           $method,
        public readonly ContextInterface $context,       // metadata + ResponseHeaders/ResponseTrailers/GrpcMetadata entries
        public readonly Message          $request,
    ) {}
}

class GrpcCallCompletedEvent extends Event
{
    public function __construct(
        public readonly string           $serviceName,
        public readonly string           $methodName,
        public readonly ContextInterface $context,
        public readonly Message          $request,
        public readonly Message          $response,
        public readonly float            $durationMs,
    ) {}
}

class GrpcCallFailedEvent extends Event
{
    public function __construct(
        public readonly string            $serviceName,   // as sent by RR ('' when the frame could not be decoded)
        public readonly string            $methodName,    // idem
        public readonly ?ContextInterface $context,       // null only when the frame could not be decoded
        public readonly ?Message          $request,       // null when routing/decoding failed before the handler was called
        public readonly \Throwable        $throwable,
        public readonly int               $workerStatusCode, // the gRPC code the worker answered with (GRPCExceptionInterface::getCode(); INVALID_ARGUMENT for a malformed frame; else StatusCode::UNKNOWN — RR picks the client's status for error())
        public readonly float             $durationMs,
    ) {}
}
```

Dispatch invariants: every frame produces exactly one of `GrpcCallCompletedEvent` | `GrpcCallFailedEvent`, after at most one `GrpcCallReceivedEvent`. `GrpcInvoker` (§4.3) dispatches for everything from decoding the request body onward; the worker loop (§4.6) dispatches `GrpcCallFailedEvent` for frames that never reach the invoker (malformed frame, unknown service/method, authentication failure). `WorkerRequestReceivedEvent` fires per frame before decoding; `WorkerResponseSentEvent(Mode::MODE_GRPC)` fires after every `respond()`/`error()`. `workerStatusCode` is the worker's classification; for unhandled throwables RR decides what the client sees (§N-1 client table) — the profiler labels the field accordingly.

### 4.2 `Grpc\GrpcRoutingTable` + `Grpc\GrpcServiceRoute` (built once per boot)

```php
readonly class GrpcMethodRoute
{
    /** @param list<IsGranted> $accessAttributes  #[IsGranted] from the handler method, else the interface method; [] when security is disabled */
    public function __construct(
        public Method $method,
        public array  $accessAttributes,
    ) {}
}

readonly class GrpcServiceRoute
{
    /** @param array<string, GrpcMethodRoute> $methods  keyed by method name */
    public function __construct(
        public string           $serviceName,
        public string           $interface,
        public ServiceInterface $service,
        public array            $methods,
    ) {}
}
```

`GrpcRoutingTable::fromRegistry(GrpcServiceRegistry $registry): self` — for every descriptor (§4.5): resolve the handler from the locator; `$service instanceof $interface` else `GrpcServiceConfigurationException`; methods = every public method of the **interface** for which `Method::match()` is true → `Method::parse()`, paired with its `#[IsGranted]` attributes — **always** collected: handler **class** attributes first, then handler method attributes, else the interface method's (`ReflectionClass/ReflectionMethod::getAttributes(IsGranted::class)`); an `Expression`/`\Closure` attribute or a `subject` other than `null`/`'request'` → `GrpcServiceConfigurationException` at build time. `GrpcWorkerRuntimeFactory::create()` throws `GrpcServiceConfigurationException('#[IsGranted] on %s::%s requires grpc.security.enabled: true')` when any route carries attributes while no `GrpcAuthorizationGuard` is wired (boot failure → `UNAVAILABLE`, never a silently open endpoint); an interface method failing `Method::match()` → `GrpcServiceConfigurationException` carrying the `Method::parse()` message (G6). `getRoute(string $serviceName): ?GrpcServiceRoute`; `getRoutes(): list<GrpcServiceRoute>`. Reflecting the interface means only proto-declared RPCs are routable.

### 4.3 `Grpc\GrpcInvoker` (internal) — marshalling + call events

Ctor: `EventDispatcherInterface $eventDispatcher`, `?GrpcAuthorizationGuard $authorizationGuard` (§4.15; null when security is not enabled). It does not implement spiral's `InvokerInterface`.

`invoke(GrpcServiceRoute $route, GrpcMethodRoute $methodRoute, ContextInterface $context, string $input): string` (`$method = $methodRoute->method`) — single dispatch site per outcome:

```
$startedAt = hrtime(true); $request = null
try {
    $request = decodeRequest($method, $input)              // new ($method->inputType)(); mergeFromString when $input !== ''
                                                           // failure → GrpcRequestDecodingException extends InvokeException (INTERNAL)
    dispatch(GrpcCallReceivedEvent(...))
    authorizationGuard?->assertGranted($methodRoute, $request)     // precomputed #[IsGranted] → UNAUTHENTICATED (anonymous) / PERMISSION_DENIED (§4.15)
    $response = $route->service->{$method->name}($context, $request)
    if (!$response instanceof $method->outputType) throw GrpcHandlerFaultException (extends InvokeException, INTERNAL, message '%s::%s() must return %s, got %s')
    $body = $response->serializeToString()                 // failure → GrpcHandlerFaultException (INTERNAL)
} catch (\Throwable $throwable) {
    dispatch(GrpcCallFailedEvent($route->serviceName, $method->name, $context, $request, $throwable, classify($throwable), duration))
    throw $throwable
}
dispatch(GrpcCallCompletedEvent(...))                      // outside the try: a Completed-listener throwable propagates as unhandled, no Failed event
return $body
```

`classify()`: `GRPCExceptionInterface` → `getCode()`, else `StatusCode::UNKNOWN`. Exception classes (all in `Exception\Grpc\`, §4.16): `GrpcRequestDecodingException` (client sent undecodable bytes — quiet client error) and `GrpcHandlerFaultException` (handler programming error — logged + Sentry by the loop, ADR-5) both extend spiral's `InvokeException` so the client receives `INTERNAL` either way.

### 4.4 Frame protocol — `Grpc\GrpcFrameDecoder`, `Grpc\GrpcResponseEncoder`, `Grpc\GrpcFrame`

Derived from `Server::serve()` (G3); A1/A5 are the live-verified assumptions:

- **Inbound** `Payload::$header` is JSON `{"service": string, "method": string, "context": object<string, list<string>>}`; `Payload::$body` is the protobuf-encoded request. `GrpcFrameDecoder::decode(string $header): GrpcFrame` (`readonly` DTO: `serviceName`, `methodName`, `metadata` `array<string, list<string>>`) — `json_decode(..., true, 512, JSON_THROW_ON_ERROR)` + `is_string`/`is_array` guards; any violation → `GrpcFrameDecodingException`.
- **Outbound success**: `new Payload($body, $headerJson)` — `GrpcResponseEncoder::encodeSuccessHeaders(ResponseHeaders, ResponseTrailers): string` builds `json_encode($document)` where `$document` is `[]` → `'{}'`, else `['headers' => iterator_to_array($headers)]` and/or `['trailers' => iterator_to_array($trailers)]` (arrays taken via `getIterator()`, never re-decoded JSON; `packHeaders()` is not used).
- **Outbound gRPC error**: `new Payload('', json)` with `json = {"error": base64(google.rpc.Status{code, message, details[Any]})}` plus optional `headers`/`trailers` — `encodeError(GRPCExceptionInterface, ResponseHeaders, ResponseTrailers): string`; `encodeStatus(int $code, string $message): string` (boot-failure paths, malformed frames).

### 4.5 `Grpc\GrpcServiceRegistry` (public API — irreversible), `Grpc\GrpcServiceDescriptor`, `Compiler\GrpcServicePass`

`GrpcServiceDescriptor` (`readonly`): `serviceName`, `interface` (`class-string<ServiceInterface>`), `serviceId`, `handlerClass`.

`GrpcServiceRegistry` ctor: `ContainerInterface $serviceLocator`. `addService(string $interface, string $serviceId, string $handlerClass): void`; `getDescriptors(): list<GrpcServiceDescriptor>`; `getService(GrpcServiceDescriptor): ServiceInterface`. `NAME` is read with `ReflectionClass::getConstant('NAME')` + `is_string` guard → else `GrpcServiceConfigurationException`. The registry is private; the worker re-resolves only `GrpcWorkerRuntimeFactory` (public).

`GrpcServicePass` (`TYPE_BEFORE_OPTIMIZATION`, added in `build()` behind `class_exists(ServiceInterface::class)`, B11): for every definition tagged `fluffy_discord.roadrunner.grpc.service`: resolve the class (skip abstract/synthetic; same helper logic as `TemporalWorkerPass::getClassFromDefinition`); collect `class_implements($class)` entries that are sub-interfaces of `ServiceInterface`, `hasConstant('NAME')`, **and** declare `NAME` themselves (`(new \ReflectionClassConstant($interface, 'NAME'))->getDeclaringClass()->name === $interface` — an app interface extending a generated one inherits `NAME` and is skipped); none → `GrpcServiceInterfaceMissingException` naming the class; the same `NAME` from two service ids → `GrpcServiceDuplicateNameException` listing both ids; each → `addMethodCall('addService', [$interface, $serviceId, $class])` + locator reference; finally replace the registry's `$serviceLocator` argument with `ServiceLocatorTagPass::register($container, $references)`. The tag is attached by `FluffyDiscordRoadRunnerExtension::load()` via `registerForAutoconfiguration(ServiceInterface::class)` behind `class_exists(ServiceInterface::class)` (B4).

### 4.6 `Worker\GrpcWorker` — `implements WorkerInterface`, `use BootFailureReporting`

Ctor (CR8): `KernelInterface $kernel`, `RrWorkerInterface $rrWorker`, `GrpcFrameDecoder $frameDecoder`, `GrpcResponseEncoder $responseEncoder`, `bool $debug`, `?SentryHubInterface $sentryHubInterface = null`. Every container-bound collaborator (`EventDispatcherInterface`, `GrpcServiceRegistry`, `GrpcInvoker`, `GrpcCallAuthenticatorInterface` (nullable), `services_resetter` (nullable)) is **not** constructor-injected: `Grpc\GrpcWorkerRuntimeFactory` (public) builds a `Grpc\GrpcWorkerRuntime` (`readonly` aggregate: `eventDispatcher`, `invoker`, `routingTable`, `authenticator`, `servicesResetter`) from `kernel->getContainer()` at boot and again after every reboot, so the only constructor-pinned collaborators are the RR worker, the frame codec pair and the Sentry hub (all container-independent); the token storage the authenticator writes to is never pinned to a dead container (precedent: `TemporalWorkerInitializer` resolves activities per boot). `WorkerBootingEvent` is **not** re-dispatched after a reboot (same as the siblings; warm-up listeners run once per process). *Known sibling gap, out of scope:* `JobsWorker`/`CentrifugoWorker` keep dispatching through their constructor-pinned dispatcher after a reboot. Frame state lives in instance properties (`private bool $handlingFrame = false; private bool $responded = false; private ?GrpcFrame $currentFrame = null;`) so the shutdown closure (`function () { $this->handleShutdown(error_get_last()); }`) observes live values.

`start()`:

```
try {
    kernel->boot()
    $runtime = buildRuntime()                  // kernel->getContainer()->get(GrpcWorkerRuntimeFactory::class)->create(): dispatcher, invoker, registry → GrpcRoutingTable, authenticator (nullable), services_resetter (nullable)
} catch (\Throwable $bootThrowable) {
    reportBootFailure($bootThrowable)
    serveBootFailure($bootThrowable)           // ONE payload answered UNAVAILABLE, then return → RR respawns (ADR-4)
    return
}

try { $runtime->eventDispatcher->dispatch(WorkerBootingEvent) } catch (\Throwable $listenerThrowable) { reportBootFailure($listenerThrowable) }   // degrade (B3)

registerShutdown(function () { $this->handleShutdown(error_get_last()) })   // once, $shutdownRegistered guard

while (true) {
    $payload = waitPayload(); if ($payload === null) break
    $this->handlingFrame = true; $this->responded = false; $this->currentFrame = null
    $routed = false; $hadUnhandledThrowable = false; $startedAt = hrtime(true)
    $responseHeaders = new ResponseHeaders(); $responseTrailers = new ResponseTrailers(); $context = null
    try {
        kernel->boot()                                                                         // first: no-op after the first frame except refreshing the debug start time (B25) — before the profiler opens its section
        sentry?->pushScope()
        dispatch(WorkerRequestReceivedEvent)
        $this->currentFrame = frameDecoder->decode($payload->header)                       // GrpcFrameDecodingException → handled below as INVALID_ARGUMENT
        $metadata = new GrpcMetadata($frame->metadata)
        $context  = new Context([ResponseHeaders::class => $responseHeaders, ResponseTrailers::class => $responseTrailers, GrpcMetadata::class => $metadata] + $frame->metadata)   // bundle entries win over same-named metadata
        $frame = $this->currentFrame
        $route    = $runtime->routingTable->getRoute($frame->serviceName)   → null: throw NotFoundException::create("Service `{$frame->serviceName}` not found.", StatusCode::NOT_FOUND)
        $methodRoute = $route->methods[$frame->methodName] ?? null          → null: throw NotFoundException::create("Method `{$frame->methodName}` not found in service `{$frame->serviceName}`.", StatusCode::NOT_FOUND)
        $runtime->authenticator?->authenticate($metadata)                      // UNAUTHENTICATED on failure (§4.15); null when security disabled
        $routed = true
        $body = $runtime->invoker->invoke($route, $methodRoute, $context, $payload->body)
        answer(new Payload($body, responseEncoder->encodeSuccessHeaders($responseHeaders, $responseTrailers)))
    } catch (GrpcFrameDecodingException $decodingException) {
        dispatch(GrpcCallFailedEvent('', '', null, null, $decodingException, StatusCode::INVALID_ARGUMENT, duration))
        logError((string) $decodingException)
        sentry?->captureException($decodingException) (best-effort)                                            // protocol-compatibility incident (A1) — report it
        answer(new Payload('', responseEncoder->encodeStatus(StatusCode::INVALID_ARGUMENT, 'Malformed gRPC frame')))   // no reboot: the kernel is healthy
    } catch (GRPCExceptionInterface $grpcException) {
        if (!$routed) dispatch(GrpcCallFailedEvent(frameServiceName(), frameMethodName(), $context, null, $grpcException, $grpcException->getCode(), duration))   // '' when no frame decoded yet (a WorkerRequestReceived listener may throw)
        if ($grpcException instanceof GrpcHandlerFaultException) { $hadUnhandledThrowable = true; sentry?->captureException(...); logError((string) $grpcException) }
        if ($grpcException instanceof GrpcSecurityConfigurationException) { sentry?->captureException(...); logError((string) $grpcException) }   // misconfiguration: report, no reboot
        answer(new Payload('', responseEncoder->encodeError($grpcException, $responseHeaders, $responseTrailers)))
    } catch (\Throwable $throwable) {
        $hadUnhandledThrowable = true
        if (!$routed) dispatch(GrpcCallFailedEvent(frameServiceName(), frameMethodName(), $context, null, $throwable, StatusCode::UNKNOWN, duration))
        sentry?->captureException($throwable) (best-effort)                     // after the failed event so tracing breadcrumbs precede the capture
        answerError($debug ? (string) $throwable : $throwable->getMessage())
        logError((string) $throwable)
        if ($throwable instanceof \Error) rrWorker->stop()
    } finally {
        $runtime->servicesResetter?->reset()                                                                                  // reset the container that served the frame FIRST (token storage, dump buffers); failure → logError + stop()
        if ($hadUnhandledThrowable && kernel instanceof RebootableInterface) { kernel->reboot(null); $runtime = buildRuntime() }   // failure → logError('Fatal worker cleanup error: …') + stop()
        sentry flush + popScope (best-effort)
        $this->handlingFrame = false; $this->currentFrame = null
    }
}
```

`answer(Payload)` / `answerError(string)`: **no-op with `logError('Frame already answered')` when `$this->responded`** (one frame → exactly one goridge answer, whatever throws later); otherwise call `rrWorker->respond()` / `rrWorker->error()`, set `$this->responded = true`, then dispatch `WorkerResponseSentEvent(MODE_GRPC)` **best-effort** (a listener throwable → `logError` only). A throwable from `respond()`/`error()` itself → `logError('Failed to answer gRPC frame: …')` + `rrWorker->stop()`. Every `dispatch(GrpcCallFailedEvent)` inside a `catch` block is likewise best-effort (`logError`, continue to answer). Nothing propagates out of the loop. `serveBootFailure(\Throwable)`: `waitPayload()` once (exceptions → return); `null` → return; `respond(new Payload('', responseEncoder->encodeStatus(StatusCode::UNAVAILABLE, bootFailureMessage($throwable))))` where the message is `Worker boot failed` / `Worker boot failed: <(string) $throwable>` in debug (ADR-8). `handleShutdown(?array $error)`: no-op unless `$this->handlingFrame && !$this->responded`; lifts `memory_limit` on `Allowed memory size` (B13); `rrWorker->error('Worker terminated during gRPC call <service>/<method>: <fatal message | die/exit>')` (`unknown/unknown` before a frame was decoded) best-effort; `logError(...)`; Sentry `captureMessage` + `flush` best-effort.

Test seams (`protected`): `waitPayload(): ?Payload`, `registerShutdown(callable)`, `logError(string)`, `getBootFailureSentryHub()`; `TestableGrpcWorker` exposes `callHandleShutdown(?array $error)` and the registered closure.

### 4.7 `Runtime\Runner::handleBootFailure()` — `MODE_GRPC` branch

After `reportBootFailure()`: `Mode::MODE_HTTP` → existing PSR-7 path; `Mode::MODE_GRPC` → `serveGrpcBootFailure($throwable)`; else `return 1`. `serveGrpcBootFailure()`: `$worker = createFallbackRoadRunnerWorker()` (`RoadRunner\Worker::create()`, a `protected` seam), `waitPayload()` once, `respond(new Payload('', (new GrpcResponseEncoder())->encodeStatus(StatusCode::UNAVAILABLE, message)))`, `return 1`; the message uses `$this->kernel->isDebug()` with the ADR-8 split. Guarded by `class_exists(StatusCode::class)` so `Runner` stays loadable without the package.

### 4.8 `Grpc\Debug\GrpcIntrospector` + `Command\GrpcDebugCommand` (`grpc:debug`)

`GrpcIntrospector` ctor: `GrpcServiceRegistry`, `RoadRunnerYamlConfigReader` (§4.17). `describe(): list<GrpcServiceDebugRow>` — reflect the **interface** only (no instantiation, B8): rows `(serviceName, interface, handlerClass, methodName, inputType, outputType, accessAttributes: list<string>, accessEnforced: bool)` — the `Access` column renders the attributes and appends `(unenforced — grpc.security.enabled: false)` when security is disabled; interface methods failing `Method::match()` → `invalid` rows with the `Method::parse()` message. `GrpcDebugCommand` output: a `Server` section (`listen`, `TLS: on|off`, `client_auth_type`, `proto` list — read from `.rr.yaml` at command time via `RoadRunnerYamlConfigReader::getSection('grpc')`; `not found in .rr.yaml` when absent), a `Security` section (`enabled`, `metadata_key`, `required`, handler service id), then one `Table` per service (`Method | Input | Output | Access`, header `Service <NAME> — <Interface> → <HandlerClass>`), invalid methods in a second table, zero services → `warning('No gRPC services registered. Implement a protoc-gen-php-grpc generated *Interface in an autoconfigured service, or tag it with fluffy_discord.roadrunner.grpc.service.')`; exit `FAILURE` when any invalid method exists.

### 4.9 Profiler — `Profiler\GrpcRequest`, `Profiler\GrpcDataCollector`, `Profiler\GrpcProfilerSubscriber` (`config/debug.php`, guarded by `class_exists(ServiceInterface::class)`)

`GrpcRequest extends Request` (B23 `CliRequest` idiom): constructed with attributes `['_virtual_type' => 'grpc', '_stopwatch_token' => bin2hex(random_bytes(3))]` and `server: ['REQUEST_TIME_FLOAT' => $startedAt]` (no fabricated client IP) — **no headers, no metadata**; `describeCall(string $serviceName, string $methodName, string $handlerClass)` sets `_controller => "$handlerClass::$methodName"` (skipped when `$handlerClass === ''`) and the names `getUri()` renders as `grpc://<NAME>/<method>` (`grpc://unknown` before a frame is decoded); `getMethod()` returns `GRPC`.

Subscriber ctor: `GrpcDataCollector`, `?Profiler` (`service('profiler')->nullOnInvalid()`), `?RequestStack $virtualRequestStack` (`service('.virtual_request_stack')->nullOnInvalid()`), `?Stopwatch $stopwatch` (`service('debug.stopwatch')->nullOnInvalid()`), `?TokenStorageInterface $tokenStorage` (`nullOnInvalid`), `EnvironmentInterface` (B14), `list<string> $redactedMetadataKeys` (`grpc.profiler.redacted_metadata_keys`, default `['authorization', 'proxy-authorization', 'cookie']`, plus `grpc.security.metadata_key` when security is enabled). **Every handler returns immediately unless `environment->getMode() === Mode::MODE_GRPC`** (the subscriber is loaded in every debug worker). `EventSubscriberInterface` + `ResetInterface` (`kernel.reset`):

- `WorkerRequestReceivedEvent` (PHP_INT_MAX): a still-pushed previous request → `finishAbandonedFrame()` (`finishFrame(new \RuntimeException('Call failed – no response was sent'))` unless a failed-event throwable is already held, status 500); then `$request = new GrpcRequest(microtime(true))`, `virtualRequestStack?->push($request)`, `stopwatch?->openSection()`.
- `GrpcCallReceivedEvent`: `request->describeCall(...)`; collector `service_name`, `method_name`, `handler_class`, `request_json`, `metadata` (key → values, redacted per the list, `*-bin` → `<binary, N bytes>`), `authenticated_user` (identifier from the token storage, `null` when none).
- `GrpcCallCompletedEvent`: `response_json`, success, `OK`.
- `GrpcCallFailedEvent`: names from the event (`describeCall` with `handler_class` `''` for unroutable frames), `worker_status_code` + name, throwable class + message, `request_json` when `request !== null`, `$throwable` kept for `collect()`.
- `WorkerResponseSentEvent(MODE_GRPC)` (PHP_INT_MIN): `finishFrame(...)`.
- `reset()`: a still-pushed request → `finishAbandonedFrame()`; clear.

`finishFrame(?\Throwable $throwable)`: `stopwatch?->stopSection($token)` (`\LogicException` swallowed, B23), `virtualRequestStack?->pop()` **before** collecting (B24 — keeps `dump()` output out of the goridge relay), `$response = new Response('', $httpStatus)` (ADR-6 mapping), `$profile = $profiler->collect($request, $response, $throwable)`; `null` → return; `$profiler->saveProfile($profile)`. The bundle's collector is populated before `collect()`; its `collect()` is a no-op (B6). Collector data: `service_name`, `method_name`, `handler_class`, `request_json`, `response_json` (`Message::serializeToJsonString()`, best-effort, `null` on failure — debug-only storage, OQ-3), `worker_status_code`, `worker_status_name` (reverse lookup of `StatusCode` constants; unknown → `UNKNOWN(<n>)`), `duration_ms`, `started_at`, `success`, `error`, `metadata` (redacted), `authenticated_user`. Template `src/Resources/views/Collector/grpc.html.twig` (B7): toolbar piece `gRPC <service>/<method>` + status name + duration; panel: metrics, request/response JSON in `<pre>`, metadata table (redacted values shown as `••••••`), security line, and the note "status as classified by the worker; for unhandled throwables RoadRunner maps `error()` to the client status". Tag `id: 'grpc'`, `priority: 255` (B5). Profile token and `virtualType` come from `Profiler::collect()`.

### 4.10 Tracing — `Grpc\Tracing\GrpcTracingListener` (opt-in, `grpc.tracing: true`)

`Extension::registerGrpcTracing()` (B9): programmatic `Definition` with `monolog.logger.grpc` and `SentryHubInterface` `NULL_ON_INVALID_REFERENCE`, three `kernel.event_listener` tags (`onCallReceived`, `onCallCompleted`, `onCallFailed`). Logs `info('gRPC call received', [service, method, metadata_keys])`, `info('gRPC call completed', [service, method, duration_ms])`, `warning('gRPC call failed', [service, method, worker_status_code, exception])`; Sentry breadcrumb (`category: grpc`) per event. `Extension::prepend()` prepends Monolog channel `grpc` when `class_exists(ServiceInterface::class)` and the `monolog` extension is present.

### 4.11 Configuration node — `grpc`

```
grpc:
    tracing: false                 # bool; same semantics as temporal.tracing (B9)
    profiler:
        redacted_metadata_keys: [authorization, proxy-authorization, cookie]   # values shown as •••••• in the profiler panel; security.metadata_key is always added
    security:
        enabled: false             # bool; requires symfony/security-bundle + an AccessTokenHandlerInterface service
        token_handler: null        # service id implementing AccessTokenHandlerInterface; required when enabled
        metadata_key: authorization # metadata key carrying the token
        token_prefix: 'Bearer '    # stripped case-insensitively from the metadata value; '' = raw token
        required: true             # true: missing token → UNAUTHENTICATED; false: missing token → anonymous (no token set), invalid token still UNAUTHENTICATED
        firewall_name: grpc        # PostAuthenticationToken firewall name (label only: shows in the Security profiler panel)
        user_provider: null        # service id of a UserProviderInterface; default = the autowired alias (exists only with exactly one provider)
```
`addGrpcNode()` runs only when `class_exists(ServiceInterface::class)` (B10); `info` lines per B10; `addDefaultsIfNotSet()` on both levels; `security.enabled: true` with `token_handler: null` → `InvalidConfigurationException` at compile time (validator on the node). Defaults' origin: `authorization`/`Bearer ` are the gRPC/HTTP convention the `access_token` authenticator's `HeaderAccessTokenExtractor` also defaults to (S1 package source); `firewall_name: grpc` is a label only. The Extension's `@var` shape gains `grpc?: array{tracing?: bool, security?: array{enabled?: bool, token_handler?: ?string, metadata_key?: string, token_prefix?: string, required?: bool, firewall_name?: string, user_provider?: ?string}, profiler?: array{redacted_metadata_keys?: list<string>}}`.

### 4.12 `Runtime::resolveRuntimeMode()` — add `'grpc' => 'grpc'`

The `$sectionKey` match gains a `'grpc' => 'grpc'` arm so `grpc.pool.debug: true` yields `worker=0` (B19, G12).

### 4.13 DI wiring — `config/grpc.php`, guarded `if (!class_exists(ServiceInterface::class)) { return; }`, imported from `config/services.php` (B4)

`GrpcServiceRegistry` (placeholder locator arg replaced by the pass); `GrpcInvoker` (public; dispatcher, `service(GrpcAuthorizationGuard::class)->nullOnInvalid()`); `GrpcFrameDecoder`; `GrpcResponseEncoder`; `RoadRunnerYamlConfigReader` (§4.17); `GrpcWorkerRuntimeFactory` (public; dispatcher, registry, invoker, `service(GrpcCallAuthenticatorInterface::class)->nullOnInvalid()`, `service('services_resetter')->nullOnInvalid()`); `GrpcWorker` (public; kernel, `service(RrWorkerInterface::class)`, decoder, encoder, `param('kernel.debug')`, Sentry `nullOnInvalid()`); `WorkerRegistry::registerWorker(Mode::MODE_GRPC, service(GrpcWorker))`; `GrpcIntrospector`; `GrpcDebugCommand` (autowire + autoconfigure). Security services (§4.15) are registered by the Extension (config-value gated, B4 rule).

### 4.14 `composer.json`, docs, live fixtures

- `composer require --dev spiral/roadrunner-grpc:^3.6 symfony/security-bundle:"^7.4 || ^8"` (G35); `suggest`: `"spiral/roadrunner-grpc": "Enables the RoadRunner gRPC worker: implement protoc-gen-php-grpc generated service interfaces as Symfony services and the bundle serves them (events, full profiler panel, tracing, grpc:debug, optional Symfony Security authentication)."`, `"symfony/security-bundle": "Enables gRPC call authentication (fluffy_discord_road_runner.grpc.security) through your AccessTokenHandlerInterface."`; the `spiral/roadrunner-jobs` suggest sentence's worker list gains gRPC.
- `docs/grpc.md` (guide mirroring `docs/temporal.md`): install, `.rr.yaml` (incl. `tls`), `rr download-protoc-binary` + `protoc --php_out --php-grpc_out`, implement a service, metadata via `GrpcMetadata`, headers/trailers, errors via `GRPCException`/`StatusCode`, security (`AccessTokenHandlerInterface` + `#[IsGranted]`), events, tracing, profiler, `grpc:debug`, limitations (unary only, no lazy boot, debug sends stack traces to clients — ADR-8, no firewalls, no peer-cert identity, `RoadRunnerMicroKernelTrait` needed for correct profiler timings — B25). README `## gRPC` section after `## Temporal`, Features bullet, `CLAUDE.md` Layout bullet, `UPGRADE.md` entry (additive).
- Live fixtures: `tests/Grpc/Live/proto/echo.proto` — `package bundle.test; service Echo { rpc Ping (PingRequest) returns (PingResponse); rpc Fail (FailRequest) returns (PingResponse); rpc Crash (CrashRequest) returns (PingResponse); rpc WhoAmI (WhoAmIRequest) returns (WhoAmIResponse); } message PingRequest { string message = 1; } message PingResponse { string message = 1; int64 pid = 2; } message FailRequest {} message CrashRequest {} message WhoAmIRequest {} message WhoAmIResponse { string user = 1; }` with `php_namespace = "FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Live\Generated"`; generated PHP checked in under `tests/Grpc/Live/Generated/` (`protoc` as installed locally — version recorded in the regeneration comment of `tests/docker-validate-grpc.sh` — with `protoc-gen-php-grpc v2025.1.15`, runtime `google/protobuf` as pulled by `spiral/roadrunner-grpc`; the generated interface shape was checked on 2026-08-20: `EchoInterface extends GRPC\ServiceInterface`, `public const NAME = "bundle.test.Echo"`, `Ping(GRPC\ContextInterface $ctx, PingRequest $in): PingResponse`); handler `tests/Grpc/Live/EchoService.php`: `Ping` echoes the message, fills `pid` with `getmypid()`, sets response header `x-echo: 1`; `Fail` throws `GRPCException::create('boom', StatusCode::INVALID_ARGUMENT)`; `Crash` throws `\RuntimeException('crash')`; `WhoAmI` is `#[IsGranted('ROLE_USER')]` and returns the current user identifier. The live PHPUnit test (`#[Group('grpc-live')]`, `RR_GRPC_LIVE=1`) drives `grpcurl -plaintext -import-path <proto dir> -proto echo.proto` via `Symfony\Component\Process\Process`. `tests/docker-validate-grpc.sh` (B16 shape; installs `grpcurl` from its GitHub release, A2) + a `grpc` pool in `tests/docker-validate-all.sh`. The live app enables `framework.profiler` (IT-07) and a `security` config with an in-memory provider + a test `AccessTokenHandlerInterface` accepting the token `live-token` for user `alice`, with `grpc.security: { enabled: true, required: false }` so IT-01..IT-07 run anonymously; a flag file `var/require-auth` (re-read per boot, the IT-05 mechanism) flips `required` to `true` for the IT-08 sub-case.

### 4.15 Security — `Grpc\Security\GrpcCallAuthenticatorInterface`, `GrpcAccessTokenAuthenticator`, `GrpcAuthorizationGuard` (Extension-registered when `grpc.security.enabled === true`; otherwise `InvalidConfigurationException('grpc.security.enabled requires symfony/security-bundle (the "security" extension) and symfony/security-http')` unless `$container->hasExtension('security') && interface_exists(AccessTokenHandlerInterface::class)`)

`GrpcCallAuthenticatorInterface { public function authenticate(GrpcMetadata $metadata): void; }` — throws `UnauthenticatedException` on failure; the bundle aliases it to `GrpcAccessTokenAuthenticator`; apps may alias their own (mTLS, API keys).

`GrpcAccessTokenAuthenticator` ctor: `AccessTokenHandlerInterface $tokenHandler` (reference to `grpc.security.token_handler`), `TokenStorageInterface $tokenStorage`, `?UserProviderInterface $userProvider` (reference to `grpc.security.user_provider` when set, else the `UserProviderInterface` alias, `NULL_ON_INVALID_REFERENCE`), `?UserCheckerInterface $userChecker` (`NULL_ON_INVALID_REFERENCE`), `string $metadataKey`, `string $tokenPrefix`, `bool $required`, `string $firewallName`.
`authenticate(GrpcMetadata $metadata): void`:
1. `$rawValue = $metadata->getFirst($metadataKey)`; `null` → `required` ? throw `UnauthenticatedException::create('Missing credentials')` : return (anonymous; token storage untouched).
2. Strip `$tokenPrefix` case-insensitively; a value not starting with the prefix (when non-empty) → `UnauthenticatedException::create('Invalid credentials')`.
3. `$badge = $tokenHandler->getUserBadgeFrom($token)` — any `AuthenticationException` → `UnauthenticatedException::create('Invalid credentials', previous: $e)` (the original message is never sent to the client; it is attached as `previous` for logging/Sentry breadcrumbs via tracing).
4. `$loader = $badge->getUserLoader()`; `$loader === null || $loader instanceof FallbackUserLoader` (S2, same condition as the HTTP authenticator) → `$userProvider === null` ? (`$loader === null` ? throw `GrpcSecurityConfigurationException::create('grpc.security needs a user provider: the token handler returned a UserBadge without a loader; set grpc.security.user_provider')` (a `GRPCException` with code `INTERNAL`, §4.16 — logged + Sentry-captured by the loop like `GrpcHandlerFaultException`, **no reboot**, client sees `INTERNAL`) : keep the fallback loader) : `$badge->setUserLoader($userProvider->loadUserByIdentifier(...))`.
5. `$user = $badge->getUser()` — `AuthenticationException` (incl. `UserNotFoundException`) → `UnauthenticatedException::create('Invalid credentials', previous: $e)`.
6. `$userChecker?->checkPreAuth($user)`; `$token = new PostAuthenticationToken($user, $firewallName, $user->getRoles())`; `$userChecker?->checkPostAuth($user, $token)` — `AccountStatusException` → `UnauthenticatedException::create('Invalid credentials', previous: $e)` (S2).
7. `$tokenStorage->setToken($token)`.
The token is cleared per frame by the existing `kernel.reset` → `setToken(null)` (S1) through `services_resetter->reset()` in the loop's `finally`.

`GrpcAuthorizationGuard` ctor: `AuthorizationCheckerInterface $authorizationChecker`, `TokenStorageInterface $tokenStorage`. `assertGranted(GrpcMethodRoute $methodRoute, Message $request): void`: `$methodRoute->accessAttributes === []` → return; no token / token without a user in storage → throw `UnauthenticatedException::create('Authentication required')` (an anonymous caller must refresh credentials, not be told "forbidden" — S2's `NullToken` would otherwise produce `PERMISSION_DENIED`); for each attribute (string attributes only, validated at build time §4.2): `$subject = $attribute->subject === 'request' ? $request : null`; `!isGranted($attribute->attribute, $subject)` → throw `GRPCException::create($attribute->message ?? 'Access denied', StatusCode::PERMISSION_DENIED)`. Handlers may also inject `Security`/`AuthorizationCheckerInterface` directly — the token is in storage during the call.

### 4.16 `Grpc\GrpcMetadata` (public) + `Exception\Grpc\*`

`readonly class GrpcMetadata` — ctor `array<string, list<string>> $values` (keys lower-cased at construction, values of keys that collide after lower-casing are merged in input order; gRPC metadata keys are case-insensitive per the gRPC spec); `getFirst(string $key): ?string`; `getAll(string $key): list<string>`; `has(string $key): bool`; `getKeys(): list<string>`; `getBearerToken(): ?string` (`authorization` with a case-insensitive `Bearer ` prefix stripped); `static fromContext(ContextInterface $context): self` (reads the `GrpcMetadata::class` entry the worker stores; throws `\LogicException` outside a bundle-served call). Binary metadata (`-bin` suffix) is passed through as RR delivers it (no base64 handling — documented).

Exceptions (`Exception\Grpc\`): `GrpcServiceInterfaceMissingException extends \LogicException` and `GrpcServiceDuplicateNameException extends \LogicException` (compile time); `GrpcServiceConfigurationException extends \LogicException` (boot time); `GrpcFrameDecodingException extends \RuntimeException`; `GrpcRequestDecodingException extends Spiral\RoadRunner\GRPC\Exception\InvokeException`; `GrpcHandlerFaultException extends Spiral\RoadRunner\GRPC\Exception\InvokeException`; `GrpcSecurityConfigurationException extends Spiral\RoadRunner\GRPC\Exception\GRPCException` (`CODE = StatusCode::INTERNAL`).

### 4.17 `Config\RoadRunnerYamlConfigReader` (runtime)

`readonly class RoadRunnerYamlConfigReader` ctor: `string $projectDir` (`%kernel.project_dir%`), `string $rrConfigPath` (the bundle's `rr_config_path` option, default `.rr.yaml`). `getSection(string $name): ?array` — reads and `Yaml::parse`s the file at call time (missing/unparsable → `null`, never throws) and expands RR's `${VAR}` / `${VAR:-default}` placeholders in string values from `getenv()` (named method `expandEnvironmentPlaceholders`, one regex) so `grpc:debug` prints the effective values. The Extension's existing `readRoadRunnerYaml()` delegates to the same class so YAML parsing exists once; `getRoadRunnerConfig()`'s compile-time RPC path is unchanged. `grpc:debug` reads `grpc.listen`, `grpc.tls.cert` (non-empty → TLS on), `grpc.tls.client_auth_type`, `grpc.proto` with `is_*` guards — no RPC, no compile-time snapshot (B8 "no server connection"; B22 explains why Temporal/KV differ).

## Assumptions

| Assumption | If wrong, then… |
|------------|-----------------|
| A1 — RR's gRPC plugin sends the frame header as JSON `{service, method, context}` with `context` = metadata map `string => list<string>` (derived from spiral's `CallContext::decode` + `Server::serve`, G3; the Go side was not read). | `GrpcFrameDecoder` rejects every frame → every call returns `INVALID_ARGUMENT`; IT-01 fails immediately; the decoder is the only place to fix. |
| A2 — `grpcurl` is downloadable as a static Linux binary in the validation Dockerfile (GitHub releases of `fullstorydev/grpcurl`). | Switch the live client to the `grpc/grpc` PHP client (ext-grpc is already installed in `docker-validate-all.sh`, B16). |
| A3 — The RR binary fetched by the existing validation Dockerfiles includes the `grpc` plugin (part of the default RR build per the official docs). | The live harness cannot run; unit/wiring tests are unaffected. |
| A4 — `google/protobuf` pure-PHP implementation is sufficient for the live messages and unit tests constructing `Google\Rpc\Status`. | Install `ext-protobuf` in the Dockerfile only. |
| A5 — RR accepts the outbound header keys `headers`, `trailers`, `error` (base64 `google.rpc.Status`) exactly as spiral's `Server` emits them (G3/G10). | IT-01 (`x-echo`) / IT-02 (`Code: InvalidArgument`) fail; fix confined to `GrpcResponseEncoder`. |
| A6 — RR treats a worker `error()` frame as a soft error and keeps the worker process (IT-03 same-`pid`). | Drop the pid assertion; document that unhandled throwables cost a worker respawn. |
| A7 — With the virtual request pushed for the frame's duration (B23/B24), the `logger`, `events`, `time` and `dump` collectors attribute the frame's work to the gRPC profile the same way they do for `CliRequest`; collectors not individually read (Doctrine bundle, Twig, Mailer, …) behave as for console commands. | IT-07 surfaces the failing collector; fix confined to `GrpcRequest` attributes / subscriber ordering. |
| A9 — `FallbackUserLoader` is `@internal`; if it is renamed, `instanceof` is simply `false`, the badge keeps its fallback loader and `getUser()` resolves through it — the provider path is skipped, not broken. | OIDC handlers resolve users through the handler's own loader instead of the configured provider. |
| A10 — `-bin` metadata values arrive as RR delivers them (the PHP package does no base64 handling; whether RR decodes them is unverified). | `<binary, N bytes>` shows the transported length, not the decoded one. |
| A8 — `.virtual_request_stack` and `debug.stopwatch` exist in every supported Symfony (7.4/8.x); when absent the subscriber degrades to collect-only profiles. | Panels other than `grpc` are empty on that Symfony version — documented, not a failure. |

## Open Questions

| Question | Why it matters | Blocks |
|----------|----------------|--------|
| OQ-1 — *decided:* `GrpcCallFailedEvent` is dispatched by `GrpcInvoker` before the exception reaches the loop, and the loop's Sentry capture is unconditional — a listener cannot veto capture. Reversible. | Listener authors may expect to veto Sentry. | Nothing. |
| OQ-2 — *resolved by Gate 3 (2026-08-20):* events carry decoded `Message` objects (ADR-7). | — | — |
| OQ-3 — The profiler stores request/response bodies as protobuf JSON and metadata values (redacted for `authorization`, the security key and `*-bin`), debug-only like the HTTP panel. Is a size cap wanted? Labeled default: debug-only, no cap; the redaction list is configurable (`grpc.profiler.redacted_metadata_keys`). | Developer machines only, but profiler storage is on disk. | Nothing — collector-only change. |
| OQ-4 — Does RR forward mTLS peer-certificate facts (subject/SAN) into the frame `context`? Not verifiable from the PHP package. The user selected "TLS/mTLS surfaced": this spec surfaces the **server** TLS facts from `.rr.yaml` in `grpc:debug` and documents termination; peer identity is not built. If RR does forward it, `GrpcMetadata` already exposes it as ordinary metadata. | A future "authenticate by client cert" feature depends on it. | Nothing. |

## N-3. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Use `Spiral\RoadRunner\GRPC\Internal\*` or `final Server` | Own the loop with the public contract types (ADR-1, §4.4) | `Internal` is not a contract; `Server` offers no per-frame hooks |
| Capture `GRPCExceptionInterface` to Sentry (except `GrpcHandlerFaultException`) | ADR-5 | Status errors are intentional responses, like HTTP 4xx; a wrong return type is a bug |
| Put Sentry/reset/logging in `GrpcInvoker` | Keep the invoker marshalling + events; the loop owns per-frame lifecycle (§4.6) | One place for the graceful-error invariants, same as the siblings |
| Reboot the kernel on a `GRPCExceptionInterface` or a malformed frame | Reboot only when `$hadUnhandledThrowable` | A `NOT_FOUND` raised on purpose is a response; a malformed frame means RR changed protocol, not that the kernel is unhealthy |
| Capture loop flags in an arrow function / by value | Instance properties read by a `function () { $this->… }` closure (§4.6) | The shutdown rescue must see live per-frame state |
| Reflect the handler **object** for routable methods | Reflect the generated interface (§4.2) | Only proto-declared RPCs are callable |
| Instantiate handlers in `grpc:debug` | Reflect the generated interface (§4.8) | "No server connection / no side effects" (B8) |
| Put metadata into the synthetic `Request` headers, or log metadata values | Profiler panel shows redacted values from the bundle collector only (§4.9); tracing logs keys only (§4.10) | `RequestDataCollector` does not redact; credentials travel as metadata |
| Collect the profile while the virtual request is still pushed | Pop first, then `collect()` (§4.9) | `DumpDataCollector` would write `dump()` output to STDOUT = the goridge relay (B24) |
| Build the profiler `Request` at persist time | Push a `GrpcRequest` on `WorkerRequestReceivedEvent` | Collectors bucket logs/events by the current request (B24) |
| Read `.rr.yaml` at compile time for `grpc:debug` | `RoadRunnerYamlConfigReader` at command time (§4.17) | No RPC at compile, no stale snapshot, B8 |
| Bypass `UserCheckerInterface` | `checkPreAuth`/`checkPostAuth` in the authenticator (§4.15) | Disabled/locked users must not authenticate over gRPC |
| Send the `AuthenticationException` message to the client | `Invalid credentials` / `Missing credentials` only; original as `previous` (§4.15) | Don't leak why a token failed |
| Add `grpc.lazy_boot` | Boot in `start()` (ADR-3) | `Runner` has already booted the kernel |
| `instanceof(ServiceInterface::class)` in `config/grpc.php` to auto-tag | `registerForAutoconfiguration` in the Extension (ADR-2) | `instanceof` conditionals are file-scoped |
| Hand-write the composer.json change | `composer require --dev …` | G35 |
| Serve `UNAVAILABLE` forever after a boot failure | Answer one payload, then return so RR respawns (ADR-4) | A transient DB-down at boot must heal without operator action |
| Re-decode `packHeaders()` JSON to embed headers | Use the iterable directly (§4.4) | One encoding step, no double JSON |

## N-2. Test Case Specifications

### Unit tests

| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-01 | `GrpcServicePass` | Autoconfigured class implementing the generated `EchoInterface`, tagged | Registry definition has `addService(EchoInterface, 'app.echo', EchoService)`; locator contains `app.echo` | Class implementing two generated interfaces → two calls; app interface extending `EchoInterface` (inherited `NAME`) → only `EchoInterface` recorded; two services with the same `NAME` → `GrpcServiceDuplicateNameException` naming both ids |
| TC-02 | `GrpcServicePass` | Tagged class implementing only the bare `ServiceInterface` | `GrpcServiceInterfaceMissingException` naming the class | Abstract/synthetic definitions skipped |
| TC-03 | `GrpcServiceRegistry` | Two descriptors | `getDescriptors()` returns both with `NAME` values; `getService()` resolves via the locator | Interface whose `NAME` is not a string → `GrpcServiceConfigurationException` |
| TC-04 | `GrpcRoutingTable` | Registry with `EchoService` | `getRoute('bundle.test.Echo')->methods` has `Ping`, `Fail`, `Crash`, `WhoAmI` with correct types | Handler not implementing the interface → `GrpcServiceConfigurationException`; interface with an invalid signature → exception carrying spiral's message |
| TC-05 | `GrpcInvoker` | Route + `Ping` + encoded `PingRequest{message:'hi'}` | Returns encoded `PingResponse{message:'hi'}`; dispatch order `GrpcCallReceivedEvent` (decoded request), `GrpcCallCompletedEvent` (response, `durationMs > 0`) | empty-string input → empty message (`Payload::$body` is never `null`); Completed-listener throwing → propagates, **no** Failed event |
| TC-06 | `GrpcInvoker` | Handler throws `GRPCException(INVALID_ARGUMENT)` | exactly one `GrpcCallFailedEvent` with `workerStatusCode === 3`, `request` set; rethrown | — |
| TC-07 | `GrpcInvoker` | Handler returns a `PingRequest` where `PingResponse` is declared / throws `\RuntimeException` / undecodable body | `GrpcHandlerFaultException` (`INTERNAL`) + one failed event / failed event `UNKNOWN` + rethrow / `GrpcRequestDecodingException` (`INTERNAL`) + one failed event with `request === null` | — |
| TC-08 | `GrpcFrameDecoder` | Valid header JSON | `GrpcFrame` with service/method/metadata | Missing key / non-string service / invalid JSON → `GrpcFrameDecodingException` |
| TC-09 | `GrpcResponseEncoder` | No headers / headers + trailers / `GRPCException` with details | `'{}'` / `{"headers":{…},"trailers":{…}}` / `{"error": base64}` decoding to `google.rpc.Status{code, message, details}` | `encodeStatus(14, 'x')` |
| TC-10 | `GrpcWorker` loop (harness: `waitPayload()` seam over a mocked `RrWorkerInterface`) | One `Ping` frame | `respond()` once with the encoded response + `x-echo` header; `kernel->boot()` called per frame **before** `WorkerRequestReceivedEvent`; events `WorkerRequestReceived`, `GrpcCallReceived`, `GrpcCallCompleted`, `WorkerResponseSent('grpc')`; `reset()` called; no `stop()`, no reboot | Two frames → two responses, one shutdown registration; the registered closure invoked while a frame is un-answered (respond stubbed) calls `error()` |
| TC-11 | `GrpcWorker` loop | `Fail` frame | `respond()` with `error` header `INVALID_ARGUMENT`; no Sentry, no `logError`, no reboot | `GrpcHandlerFaultException` from the invoker → `error` header `INTERNAL` **and** `logError` + Sentry + reboot |
| TC-12 | `GrpcWorker` loop | `Ping` frame then unknown-service frame; unknown-method frame | second frame → `NOT_FOUND` error header and a worker-dispatched `GrpcCallFailedEvent` with `context !== null` (metadata keys present), `request === null`; `WorkerResponseSentEvent` each | Malformed header → `INVALID_ARGUMENT` status response, failed event with `context === null`, `logError`, **no** Sentry, **no** reboot |
| TC-13 | `GrpcWorker` loop | `Crash` frame (`\RuntimeException`) | `error()` with message (`(string)` throwable when `debug`), Sentry captured, `logError`, `reboot(null)` on a rebootable kernel, runtime rebuilt from the **new** container (next frame dispatches into the new dispatcher **and** authenticates into the new token storage), **no** `stop()` | `\Error` → `stop()` additionally |
| TC-14 | `GrpcWorker` loop | `servicesResetter->reset()` throws / `reboot()` throws / `respond()` throws | `logError` + `stop()`; the loop never propagates | `WorkerResponseSentEvent` listener throws → exactly one `respond()`, no `error()`, no `stop()`, `logError`; `GrpcCallFailedEvent` listener throws in the `NOT_FOUND` path → `NOT_FOUND` still answered |
| TC-15 | `GrpcWorker::handleShutdown` | Handling + un-responded frame + `Allowed memory size` error | `error()` once with the fatal text and service/method; `memory_limit` lifted | Responded → no-op; not handling → no-op |
| TC-16 | `GrpcWorker::start` | Kernel `boot()` throws | `BOOT FAILURE` logged + Sentry; one payload answered `UNAVAILABLE` (debug message contains the throwable class); loop never entered | `GrpcRoutingTable` construction throwing takes the same path |
| TC-17 | `GrpcWorker::start` | `WorkerBootingEvent` listener throws (`FailingBootListener`) | `BOOT FAILURE` logged + Sentry **and** the loop still serves the next frame | — |
| TC-18 | `Runner::handleBootFailure` | mode `grpc`, kernel boot throws | fallback worker's `respond()` once with an `UNAVAILABLE` error header; returns 1 | mode `jobs` → returns 1 without responding (unchanged) |
| TC-19 | `GrpcIntrospector` / `GrpcDebugCommand` | Registry with `EchoInterface` + fixture `.rr.yaml` / empty / fixture with invalid method | Rows for the four methods (`WhoAmI` with `ROLE_USER` in `Access`), server section shows `listen` + `TLS: off` + SUCCESS / warning + SUCCESS / invalid table + FAILURE | No `grpc` section → `not found in .rr.yaml`; `RoadRunnerYamlConfigReader` with a missing file → `null` |
| TC-20 | `GrpcProfilerSubscriber` (mode `grpc`) | Request → received → completed → `WorkerResponseSent('grpc')` with a real `RequestStack` as virtual stack and a `Stopwatch` | stack pushed on request, **popped before** `Profiler::collect`; `collect` receives a `GrpcRequest` with `getUri() === 'grpc://bundle.test.Echo/Ping'`, `getMethod() === 'GRPC'`, `_virtual_type === 'grpc'`, `_controller === EchoService::class . '::Ping'`, no headers; `Response` 200; stopwatch section closed; `saveProfile` called; collector `workerStatusName === 'OK'`, `requestJson`/`responseJson` set, metadata `authorization` redacted | Received then `reset()` → 500 + "no response" and the stack popped; `Fail` → 400; unroutable failed event → `grpc://unknown`; `WorkerResponseSent('jobs')` ignored; **mode `http`**: nothing pushed, `collect` never called; null stack/stopwatch → still collects |
| TC-21 | `GrpcDataCollector` | `populate(...)` with status 3 | `getWorkerStatusName() === 'INVALID_ARGUMENT'`; `collect()` never clobbers data | Unknown code → `UNKNOWN(<n>)` |
| TC-22 | `GrpcTracingListener` | The three events | Logger receives info/info/warning with the documented context keys; Sentry breadcrumbs when the hub is present | Null logger → no error |
| TC-23 | Wiring (`config/services.php` → `config/grpc.php`, bare `ContainerBuilder`, B15) | — | `GrpcWorker` + `GrpcWorkerRuntimeFactory` defined and public, `registerWorker('grpc', …)` | `kernel.debug = true` → `config/debug.php` defines collector + subscriber |
| TC-24 | `Configuration` / `Extension` | `grpc.tracing: true` / omitted; `grpc.security.enabled: true` with/without `token_handler` | `tracing` true/false; tracing listener definition with three `kernel.event_listener` tags only when `true`; `prepend()` adds Monolog channel `grpc`; security services defined only when enabled; enabled without handler → `InvalidConfigurationException` | — |
| TC-25 | `Runtime::resolveRuntimeMode` | `.rr.yaml` `grpc.pool.debug: true` / `false` / missing | `worker=0` / `worker=1` / `worker=1` | — |
| TC-26 | `GrpcAccessTokenAuthenticator` | metadata `authorization: Bearer ok`, handler returns a badge with loader | `checkPreAuth` then `checkPostAuth` called, `setToken(PostAuthenticationToken)` with the loaded user, firewall `grpc`, roles from the user | prefix matched case-insensitively (`bearer ok`); `checkPreAuth` throwing `DisabledException` → `Invalid credentials`; badge with `FallbackUserLoader` + provider → provider used |
| TC-27 | `GrpcAccessTokenAuthenticator` | missing metadata with `required: true` / `false` | `UnauthenticatedException('Missing credentials')` / no `setToken` call | wrong prefix → `Invalid credentials` |
| TC-28 | `GrpcAccessTokenAuthenticator` | handler throws `BadCredentialsException('secret reason')` / badge without loader + provider / badge without loader + no provider | `UnauthenticatedException('Invalid credentials')` with `previous` set, message does **not** contain `secret reason` / user loaded via provider / `GrpcServiceConfigurationException` | `UserNotFoundException` from the loader → `Invalid credentials` |
| TC-29 | `GrpcAuthorizationGuard` | route with `#[IsGranted('ROLE_ADMIN')]`, authenticated token + checker false / true; no token in storage; `subject: 'request'` | `PERMISSION_DENIED` with the attribute message / passes; `UNAUTHENTICATED` for the anonymous caller; request passed as subject | `GrpcRoutingTable`: `Expression` attribute or unknown subject → `GrpcServiceConfigurationException`; handler attribute wins over interface attribute |
| TC-30 | `GrpcMetadata` | `['Authorization' => ['Bearer x'], 'x-a' => ['1','2']]` | `getFirst('authorization') === 'Bearer x'`, `getAll('x-a')` two values, `getBearerToken() === 'x'`, `getKeys()` lower-cased | `fromContext()` without the entry → `\LogicException` |
| TC-31 | `GrpcWorker` + profiler subscriber | Handler calls `dump()` during a frame with the subscriber active (real `DumpDataCollector`) | Nothing is written to STDOUT (captured with `ob_start`) — the dump lands in the profile | — |

### Integration tests (live, `tests/docker-validate-grpc.sh`, `#[Group('grpc-live')]`)

| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|
| IT-01 | Happy path | RR `grpc` pool on `tcp://127.0.0.1:9001` (RR docs' default, G12), `proto: [echo.proto]`, `pool.num_workers: 1`, `EchoService` autoconfigured | `grpcurl … bundle.test.Echo/Ping` `{"message":"hi"}` → JSON with `"message": "hi"` and a numeric `pid`, exit 0; `-v` output contains response header `x-echo: 1` | container exit |
| IT-02 | gRPC status error | same | `Echo/Fail` → exit ≠ 0, output contains `Code: InvalidArgument` and `boom` | — |
| IT-03 | Unhandled throwable, worker survives | same | `Ping` (record `pid`) → `Crash` → non-OK status → `Ping` returns the **same `pid`** (A6) | — |
| IT-04 | Events + metadata | listener appending event class + `GrpcMetadata::fromContext($event->context)->getKeys()` to a marker file | `Ping -H 'x-test: 1'` → marker contains `GrpcCallReceivedEvent`, `GrpcCallCompletedEvent`, `x-test` (A1) | rm marker |
| IT-05 | Boot failure answered at both sites, and retried | flag file `var/break-boot` makes the test kernel's overridden `boot()` throw `\RuntimeException('boot broken')` before `parent::boot()` (exercises `Runner::run()`'s `try` — a compiled container would not re-run `registerContainerConfiguration()`); flag file `var/break-routing` makes `EchoService::__construct()` throw (locator failure while building the routing table → `GrpcWorker::start()` site). Both files are checked on every boot | `Ping` → `Code: Unavailable` + `Worker boot failed` for each variant; after clearing the flag file, the next `Ping` → `OK` (proves a fresh process retried boot, not a frozen `UNAVAILABLE` loop) | restore flag file |
| IT-06 | Debug command | same image | `bin/console grpc:debug` exit 0; output contains `bundle.test.Echo`, `Ping`, `PingRequest`, `PingResponse`, `listen`, `tcp://127.0.0.1:9001` | — |
| IT-07 | Full profile per call | `framework.profiler.enabled: true`, `APP_DEBUG=1`; `EchoService::Ping` logs one `info` line and calls `dump()` | after `Ping`, the profiler storage contains a profile with url `grpc://bundle.test.Echo/Ping`, method `GRPC`, status 200, virtual type `grpc`, whose `grpc` collector has `request_json` containing `"message":"hi"`, whose `logger` collector contains the handler's log line, whose `time` collector has a non-empty timeline, and whose `dump` collector holds the dump — **and** the `Ping` response is still well-formed (no dump bytes on the relay) (A7) | — |
| IT-08 | Security | `grpc.security: {enabled: true, required: false}`, `token_handler` = test handler accepting `live-token` → `alice` (`ROLE_USER`) | `WhoAmI` without metadata → `Code: Unauthenticated` + `Authentication required` (guard); with `-H 'authorization: Bearer wrong'` → `Unauthenticated` + `Invalid credentials`; with `live-token` → `{"user":"alice"}`; `Ping` with `live-token` → OK; then with the `var/require-auth` flag file present (fresh worker) `Ping` without metadata → `Unauthenticated` + `Missing credentials` | remove flag file |

## N-1. Error Handling Matrix

### Internal / boot failures

| Error type | Detection | Response | Fallback | Logging | Alert |
|------------|-----------|----------|----------|---------|-------|
| Kernel boot throws in `Runner` (container/compile/env) | `Runner::run()` `try` | `MODE_GRPC` branch answers one payload `UNAVAILABLE`, returns 1 → RR respawns and retries (ADR-4, §4.7) | — | STDERR `BOOT FAILURE (mode=grpc): …` | Sentry |
| Runtime build throws in the worker (handler/interface mismatch, invalid signature, `Expression` `#[IsGranted]`, locator failure) | `start()` boot `try` | one payload `UNAVAILABLE`, return → RR respawns | `grpc:debug` shows signature problems pre-flight (TC-19) | STDERR `BOOT FAILURE: …` | Sentry |
| `WorkerBootingEvent` listener throws | second `try` in `start()` | reported, worker serves normally | — | STDERR | Sentry |
| Tagged service without a generated interface / duplicate `NAME` / `security.enabled` without handler | compile time | container build fails (`GrpcServiceInterfaceMissingException` / `GrpcServiceDuplicateNameException` / `InvalidConfigurationException`) | — | Symfony build error | — |
| Malformed frame header (RR protocol drift, A1) | `GrpcFrameDecoder` | `INVALID_ARGUMENT` status response | worker continues, no reboot | STDERR | Sentry + `GrpcCallFailedEvent` |
| Unknown service / method | routing in the loop | `NOT_FOUND` error header (spiral's message text) | — | none | `GrpcCallFailedEvent` (tracing `warning` when enabled) |
| Missing/invalid credentials | `GrpcAccessTokenAuthenticator` | `UNAUTHENTICATED` (`Missing credentials` / `Invalid credentials`) | — | none (tracing `warning` with the `previous` class when enabled) | `GrpcCallFailedEvent` |
| `#[IsGranted]` on a route, anonymous caller (`required: false`) | `GrpcAuthorizationGuard` | `UNAUTHENTICATED` (`Authentication required`) | — | none | `GrpcCallFailedEvent` |
| `#[IsGranted]` denied for an authenticated user | `GrpcAuthorizationGuard` (inside the invoker) | `PERMISSION_DENIED` with the attribute's message | — | none | `GrpcCallFailedEvent` |
| Disabled/locked/expired user (`AccountStatusException`) | `UserCheckerInterface` in the authenticator | `UNAUTHENTICATED` (`Invalid credentials`) | — | none | `GrpcCallFailedEvent` |
| Handler throws `GRPCExceptionInterface` | `catch` in the loop | `{"error": Status}` with the exception's code/details + any headers/trailers set | — | none (tracing `warning` when enabled) | no Sentry (ADR-5) |
| Handler returns the wrong type / response fails to serialize (`GrpcHandlerFaultException`) | invoker guard | `INTERNAL` error header | kernel reboot + runtime rebuilt | STDERR full throwable | Sentry |
| Undecodable request body (`GrpcRequestDecodingException`) | invoker | `INTERNAL` error header | — | none | `GrpcCallFailedEvent` |
| Handler throws other `\Throwable` | `catch (\Throwable)` in the loop | `error(message | full trace in debug)` → RR returns a non-OK status | kernel reboot + runtime rebuilt; `\Error` → `stop()` (RR re-allocates) | STDERR full throwable | Sentry |
| die/exit/fatal mid-frame | `register_shutdown_function` + instance flags | `error('Worker terminated during gRPC call …')` | — | STDERR `fatal: … in file:line` | Sentry `captureMessage` |
| `respond()`/`error()` throws, `reset()`/`reboot()` throws | `try` around each | `stop()`; the loop never propagates | RR re-allocates | STDERR | — |

### Client-facing statuses (what a gRPC client observes)

| Situation | gRPC status | Message |
|-----------|-------------|---------|
| Success | `OK` (0) | handler response (+ headers/trailers) |
| Boot failure (either site) | `UNAVAILABLE` (14) | `Worker boot failed` (+ throwable in debug) |
| Malformed frame | `INVALID_ARGUMENT` (3) | `Malformed gRPC frame` |
| Missing / invalid token / disabled user / anonymous on a guarded method | `UNAUTHENTICATED` (16) | `Missing credentials` / `Invalid credentials` / `Authentication required` |
| `#[IsGranted]` denied | `PERMISSION_DENIED` (7) | attribute message or `Access denied` |
| Handler `GRPCException(code)` | that code | handler message (+ details) |
| Handler fault / undecodable body | `INTERNAL` (13) | bundle message |
| Handler other throwable | RR-determined non-OK status from `worker->error()` (observed in IT-03; not asserted to a specific code) | `getMessage()` (`(string) $e` in debug, ADR-8) |
| Unknown service/method | `NOT_FOUND` (5) | spiral's message text |

## N. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Graceful error handling contract | [graceful-error-handling.md](graceful-error-handling.md#worker-loop) | worker loop |
| Jobs worker (sibling worker, same patterns) | [rr-jobs-worker.md](rr-jobs-worker.md#42-workerjobsworker-new--structure-mirrors-centrifugoworker) | §4.2 |
| Usage guide (public) | [../grpc.md](../grpc.md) | top |
| Temporal guide (structure mirrored) | [../temporal.md](../temporal.md#14-console-commands) | §14 |
| RR gRPC plugin | https://docs.roadrunner.dev/docs/grpc/grpc | — |
| Live validation harness shape | [../../tests/docker-validate-temporal.sh](../../tests/docker-validate-temporal.sh) | header comment |
