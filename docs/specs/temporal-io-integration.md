# Temporal.io worker integration (Implementation)

**Source pinned to:** worktree branch `feature/temporal-io-integration`, base `d6b9c0f`,
feature code merged from `origin/temporal-io` (`63d3f0d`). Temporal SDK pinned to
`temporal/sdk` v2.17.1 (installed, vendored under `vendor/temporal/sdk`).

---

## 1. Baseline (reverse-engineered, with citations)

### 1.1 Current worker dispatch (mainline, HEAD)

- `src/Runtime/Runner.php:25-36` — boots kernel, fetches `WorkerRegistry`, calls
  `$registry->getWorker($this->mode)`, then `$worker->start()`. Unknown mode →
  `error_log(...)` + return 1.
- `src/Worker/WorkerRegistry.php:10-18` — `registerWorker(string $mode, WorkerInterface)`
  keyed by mode string; `getWorker()` returns `?WorkerInterface`.
- `src/Worker/WorkerInterface.php:5-8` — the bundle worker contract is a single
  `start(): void`.
- `config/services.php:85-91,132-138` — HTTP worker registered under
  `Environment\Mode::MODE_HTTP`, Centrifugo under `MODE_CENTRIFUGE`, each via
  `->call("registerWorker", [<mode>, service(<Worker>)])`.
- `Spiral\RoadRunner\Environment\Mode::MODE_TEMPORAL === 'temporal'`
  (`vendor/spiral/roadrunner-worker/src/Environment/Mode.php:20`) — the dispatch key
  for the Temporal worker. Verified present.

### 1.2 Current extension / configuration (mainline, HEAD)

- `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:25-108` — `load()` loads
  `services.php`, conditionally `debug.php`, registers Centrifugo attributes only when
  `RoadRunnerCentrifugoWorker` class exists, processes config, replaces HTTP/Centrifugo
  worker args, optionally registers KV cache adapters. It does **not** implement
  `CompilerPassInterface` or `PrependExtensionInterface`.
- `src/DependencyInjection/Configuration.php:9-140` — config tree
  `fluffy_discord_road_runner` with `rr_config_path`, `http`, `kv`, `centrifugo` nodes.
- Optional dependencies are activated by `class_exists(...)` guards, never hard `require`
  (Centrifugo, KV). Temporal must follow the same pattern.

### 1.3 Incoming Temporal feature code (`origin/temporal-io` @ `63d3f0d`)

All `src/Temporal/**`, `src/Worker/TemporalWorker.php`, `src/DataCollector/TemporalCollector.php`,
`src/Factory/RPCConnectionFactory.php`, the three `src/Exception/*NotAssigned*`/`*NotPristine*`
classes, and `src/Resources/views/Collector/temporal.html.twig` merged cleanly (git add status
`A`). The conflicting files (`HttpWorker.php`, `Configuration.php`, `config/services.php`,
`FluffyDiscordRoadRunnerExtension.php`, `composer.json`, `composer.lock`, `README.md`) were cut
from an **older base** (PHP 8.1, Symfony 6.4, PHPUnit 10.5, pre-graceful-error-handling
HttpWorker) and lose to mainline; only the Temporal-specific additions are grafted on.

### 1.4 The intended usage model (recovered from `origin/temporal-io:README.md` + code)

1. After `temporal/sdk` is installed, every service implementing
   `TemporalWorkerInterface` (the bundle ships `DefaultTemporalWorker`) is registered as a
   Temporal worker. Each such service's `create(WorkerFactoryInterface)` returns a
   `Temporal\Worker\WorkerInterface` bound to **one task queue** (its `getID()`).
2. Workflows (`#[WorkflowInterface]`) and activities (`#[ActivityInterface]`) are assigned
   to a worker's task queue with the repeatable class/interface attribute
   `#[TaskQueue(taskQueue: '...')]` (default `WorkerFactoryInterface::DEFAULT_TASK_QUEUE`).
3. At compile time the extension scans definitions, tags workers, and records
   `addActivity`/`addWorkflow(class, taskQueues)` method calls on `TemporalWorkerInitializer`.
4. At worker boot `TemporalWorker::start()` boots the kernel, creates the factory, runs
   `TemporalWorkerInitializer::initialize()` (registers each worker's workflows + activities
   keyed by task queue, sets an activity finalizer that resets services), then
   `$factory->run($hostConnection)`.
5. Interceptors translate each Temporal SDK interceptor callback into a Symfony event that
   carries the SDK input object and is mutable via `setInput()`, so listeners can rewrite it.

---

## 2. Design decisions for the integration

| # | Decision | Rationale / evidence |
|---|----------|----------------------|
| D-1 | HTTP worker, Runner, WorkerRegistry, services wiring for HTTP/Centrifugo: **mainline wins** verbatim. | Incoming versions predate graceful error handling (`src/Worker/HttpWorker.php:104-228`). Prompt: "modern HttpWorker/Runner/WorkerRegistry win." |
| D-2 | Temporal services live in `config/services.php` inside a single `if (class_exists(WorkflowInterface::class))` block, mirroring the Centrifugo block. | Optional-dependency pattern (`config/services.php:94`). No new file; single source. |
| D-3 | Temporal config node added to `Configuration.php` **guarded by `class_exists(Temporal\Worker\WorkerFactoryInterface::class)`** so the tree builds without `temporal/sdk`. | `Configuration` is always loaded; the `default_worker_options` validator calls `get_class_vars(WorkerOptions::class)` which warns/voids if the class is absent. |
| D-4 | Extension gains `CompilerPassInterface` (scan + tag workflows/activities/workers) and `PrependExtensionInterface` (register a `temporal` Monolog channel when monolog is present), gated by `class_exists(WorkflowInterface::class)`. | Matches incoming design; needed for attribute → worker assignment. |
| D-5 | `temporal/sdk` is a **`require-dev` + `suggest`** dependency, not `require`. | Activation is `class_exists`-gated like Centrifugo/KV (`composer.json` require-dev). Forcing it on every consumer is wrong. |
| D-6 | Worker assignment key is the **task-queue name**. `DefaultTemporalWorker` creates the `DEFAULT_TASK_QUEUE` worker; the initializer/collector look up workflows & activities by `WorkerInterface::getID()`. | SDK: `newWorker($taskQueue=DEFAULT_TASK_QUEUE,...)` (`vendor/temporal/sdk/src/Worker/WorkerFactoryInterface.php:40`). `getID()` returns the queue name (`vendor/temporal/sdk/src/Worker/Worker.php:65-68`). |
| D-7 | The graceful-error-handling per-request frame pattern of `HttpWorker` **does not transfer** to `TemporalWorker`. Temporal's `$factory->run()` owns its own request loop and RoadRunner frame protocol; the bundle cannot interpose per-message framing. The bundle's seam is the **activity finalizer** (`services_resetter->reset()`) + Sentry scope at the worker boundary. | `vendor/temporal/sdk/src/WorkerFactory.php` `run()` is a blocking loop; there is no `waitRequest()/respond()` seam analogous to PSR7Worker. Recorded as a deliberate scope limit, see Open Question OQ-1. |

---

## 3. Type / correctness fixes required in the incoming code (PHPStan level-max)

The incoming code was written before the level-max cleanup. The following are **defects**
(not analyser appeasement) and must be fixed at the type level — no `@phpstan-ignore`,
`assert()` for typing, inline `@var`, or casts.

| # | File | Defect | Fix |
|---|------|--------|-----|
| F-1 | `src/Temporal/DefaultTemporalWorker.php` | Constructor declares required params (`$exceptionInterceptor`, `$simplePipelineProvider`) **after** params with defaults — PHP fatal `ParseError`/deprecation; also property doc on `?array $workerOptions` is untyped. | Reorder: required params first, optional last. Type `$workerOptions` as `array<string, mixed>`. |
| F-2 | `src/Temporal/DefaultTemporalWorker.php:create()` | Leftover `\Symfony\Component\VarDumper\VarDumper::dump($this->simplePipelineProvider);` debug call. | Delete. |
| F-3 | `src/Temporal/TemporalWorkerInitializer.php` | `addActivity`/`addWorkflow` params untyped; `$activities`/`$workflows` docblock says `array<int, class-string>` but used as `array<string, list<class-string>>` (keyed by task queue). | Type params `string $activity, array<int,string> $taskQueues`; correct property docblocks to `array<string, list<class-string>>`. |
| F-4 | `src/Temporal/TemporalWorkerInitializer.php:ensureWorkerIsPristine()` | `getActivities()` returns `iterable<ActivityPrototype>` (`WorkerInterface.php:79-81`), not necessarily `ActivityCollection`; `count()` on an `iterable` is a type error; `getFinalizer()` only exists on `ActivityCollection`. | Normalise via `iterator_to_array`/`is_countable` guard; check finalizer only when `$workerActivities instanceof ActivityCollection`. |
| F-5 | `src/Temporal/TemporalWorkerInitializer.php` | `$worker->create()` returns `WorkerInterface` which has **no `getID()`**; `getID()` is on the concrete `Worker` (`Worker.php:65`). | The bundle controls worker creation through its own `TemporalWorkerInterface`; document that the returned worker must expose `getID()`. Resolve by reading the task queue from the prototype set, or guard with `method_exists`/`instanceof Worker`. **Chosen:** narrow to `Temporal\Worker\Worker` where `getID()` is needed, with an `instanceof` guard that throws `WorkerNotPristineException` otherwise. |
| F-6 | `src/DataCollector/TemporalCollector.php` | Same `getId()`/`getID()` on `WorkerInterface`; `$this->data` is `mixed` (untyped `AbstractDataCollector::$data`); getters return `array` without shape; `array_unique([...$x ?? [], ...])` spreads a non-iterable when prior value is a shaped array. | Type `$this->data` via local typed arrays; `@return` shapes on getters; fix the `taskQueues` accumulation to merge the `taskQueues` sub-array, not the whole row. |
| F-7 | `config/services.php` (incoming) | Three interceptor aliases all point to `WorkflowClientCallsInterceptor` (copy-paste): `WorkflowInboundCallsInterceptor` and `WorkflowOutboundCallsInterceptor` SDK aliases are wrong. | Point each SDK interceptor alias to its matching bundle interceptor. |
| F-8 | `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php` (incoming) | `getDefinitionClassInterfaces`/`getClassFromDefinition`/`getRoadRunnerConfig`/`registerKVCache` return `array` untyped; `registerTemporal` uses `in_array(...)` without strict; reflection on every definition is `mixed`-heavy. | Add array shapes / `class-string` types; `in_array(..., true)`; guard `class_exists` before `new \ReflectionClass`. Keep mainline's existing typed `getRoadRunnerConfig`/KV code — do **not** regress it to the incoming untyped version. |
| F-9 | `src/Worker/TemporalWorker.php` | Unused `?SentryHubInterface` (never referenced in `start()`), and `WorkerBootingEvent` dispatched but no scope handling. | Keep Sentry param (wired in services), push/flush a Sentry scope around `run()` best-effort, mirroring the other workers' Sentry usage; or drop the param if unused. **Chosen:** wrap `run()` in a try/finally that flushes Sentry, so the param is used. |

---

## 4. Files added / changed (grouped)

**Added (from temporal-io, mode A — kept):**
- `src/Worker/TemporalWorker.php`
- `src/Temporal/{TemporalWorkerInterface,TemporalWorkerFactoryInterface,DefaultTemporalWorker,DefaultTemporalWorkerFactory,TemporalWorkerInitializer,TemporalCredentialsFactory}.php`
- `src/Temporal/Attribute/TaskQueue.php`
- `src/Temporal/Interceptor/{ActivityInbound,WorkflowClientCalls,WorkflowInboundCalls,WorkflowOutboundCalls}Interceptor.php`
- `src/Temporal/Interceptor/Event/**` (31 event classes)
- `src/DataCollector/TemporalCollector.php`
- `src/Factory/RPCConnectionFactory.php`
- `src/Exception/{ActivityNotAssigned,WorkflowNotAssigned,WorkerNotPristine}Exception.php`
- `src/Resources/views/Collector/{temporal.html.twig,icon.svg}`

**Changed (mainline base + grafted Temporal delta):**
- `config/services.php` — add `if (class_exists(WorkflowInterface::class)) { … }` Temporal block.
- `src/DependencyInjection/Configuration.php` — add `temporal` node guarded by `class_exists`.
- `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php` — `implements CompilerPassInterface, PrependExtensionInterface`; `prepend()`, `process()`, `registerTemporal()`, `replaceTemporalParameters()`.
- `composer.json` — `temporal/sdk` in `require-dev` + `suggest`.
- `README.md` — append Temporal usage section (corrected attribute name).

**Tests added:** see §6.

---

## 5. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| `require` `temporal/sdk` in composer `require`. | `require-dev` + `suggest`, `class_exists`-gate all wiring. | Temporal is optional; forcing it breaks HTTP-only installs. |
| Reference `WorkerOptions::class`/`get_class_vars` in the config tree unconditionally. | Guard the whole `temporal` node behind `class_exists`. | `Configuration` is always loaded even without the SDK; bare reference warns/voids the tree. |
| Mock the final SDK classes (`WorkerFactory`, `Worker`, input objects) or the bundle's interceptors. | Build real SDK fixtures + mock only the interfaces (`WorkerFactoryInterface`, `EventDispatcherInterface`); test interceptors by dispatching into a real `EventDispatcher`. | Final classes can't be mocked (CLAUDE.md); matches HTTP/Centrifugo worker test style. |
| Call `getID()` on the `WorkerInterface` type. | Narrow to `Temporal\Worker\Worker` (instanceof guard) before reading the task-queue id. | `getID()` is not on the interface (`WorkerInterface.php`), only the concrete class. |
| Append a second response/error frame from the Temporal worker loop like `HttpWorker` does. | Let `$factory->run()` own framing; use the activity finalizer + Sentry boundary as the bundle's seam. | Temporal owns the RR message protocol; interposing corrupts it (D-7). |
| Silence PHPStan with ignores/asserts/casts on the incoming code. | Fix the real types (§3). | CLAUDE.md hard rule. |

---

## 6. Test Case Specifications

Tests run under `php vendor/bin/phpunit tests`. Live tests are tagged
`#[Group('temporal-live')]` and **skip** unless `TEMPORAL_LIVE=1` and a reachable
Temporal server + RR `temporal` plugin are provisioned (§7) — the default run stays green.

### 6.1 Unit tests

| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-01 | `WorkerRegistry` + `MODE_TEMPORAL` | register a worker under `Mode::MODE_TEMPORAL` | `getWorker('temporal')` returns it | unknown mode → null (covered by existing `WorkerRegistryTest`) |
| TC-02 | `TaskQueue` attribute | `new TaskQueue('q1')`; default ctor | `->taskQueue === 'q1'`; default `=== DEFAULT_TASK_QUEUE`; attribute is `TARGET_CLASS\|IS_REPEATABLE` | repeated attribute on one class yields multiple instances |
| TC-03 | extension `registerTemporal()` | container with a `#[WorkflowInterface]#[TaskQueue('q')]` workflow + `#[ActivityInterface]#[TaskQueue('q')]` activity + a `TemporalWorkerInterface` service | workflow tagged `…temporal.workflow`, activity tagged `…temporal.activity`, worker tagged `…temporal.worker`; `addWorkflow/addActivity` method calls recorded on initializer | activity/workflow missing `#[TaskQueue]` → `Activity/WorkflowNotAssignedException` |
| TC-04 | `TemporalWorkerInitializer::initialize()` | mocked `WorkerFactoryInterface` whose `newWorker()` returns a real pristine `Worker`; initializer pre-loaded with one workflow + one activity for that queue | returns `[{factory, worker}]`; the `Worker` has the workflow + activity registered; finalizer set | duplicate registration; empty queue → no-op |
| TC-05 | `TemporalWorkerInitializer::ensureWorkerIsPristine()` | a `Worker` that already has an activity/workflow/finalizer | throws `WorkerNotPristineException` | clean worker → no throw |
| TC-06 | `DefaultTemporalWorkerFactory::create()` | mocked `RPCConnectionInterface`, `DataConverterInterface`, real `ServiceCredentials` | returns a `WorkerFactoryInterface` instance (`WorkerFactory`) | — |
| TC-07 | `DefaultTemporalWorker::create()` | `WorkerOptions` array, real `ExceptionInterceptor`, `SimplePipelineProvider`, mocked factory | calls `factory->newWorker(...)` once with options applied; returns the worker; no `VarDumper` output | empty options; unknown option key is the config's job, not here |
| TC-08 | `TemporalCredentialsFactory::create()` | `null` / `''` / `'key'` | base credentials for null/empty; `withApiKey('key')` for a key | — |
| TC-09 | `RPCConnectionFactory::fromEnvironment()` | `RR_RPC` unset | throws `InvalidRPCConfigurationException` | `RR_RPC` set → returns an `RPCConnectionInterface` (Goridge) |
| TC-10 | `ActivityInboundInterceptor` | real `EventDispatcher` with a listener that swaps the input; `handleActivityInbound($input, $next)` | the listener's `ActivityEvent` is dispatched; `$next` receives the (possibly swapped) input; return value propagates | listener that does nothing → input unchanged |
| TC-11 | `WorkflowClientCallsInterceptor` (10 methods) | dispatch each method with its real SDK input | each emits the matching `WorkflowClient\*Event`; `$next` receives `event->getInput()`; return type preserved | — |
| TC-12 | `WorkflowInboundCallsInterceptor` (5 methods) | `execute/handleQuery/handleSignal/handleUpdate/validateUpdate` | matching event emitted; `handleUpdate`→`UpdateEvent(validation:false)`, `validateUpdate`→`UpdateEvent(validation:true)` | — |
| TC-13 | `WorkflowOutboundCallsInterceptor` (16 methods) | dispatch each with its SDK input | each emits the matching `WorkflowOutboundCalls\*Event`; `await` emits `WorkflowClientCalls\AwaitEvent` | — |
| TC-14 | Event objects | construct each event with an input; `getInput()/setInput()` | round-trips; `WorkflowInboundCalls\UpdateEvent::isValidation()` reflects ctor flag | — |
| TC-15 | `TemporalCollector::collect()` | initializer returning one worker with one workflow + one activity | `getWorkers()/getWorkflows()/getActivities()` shaped arrays; `taskQueues` lists the queue once | empty → all empty arrays |
| TC-16 | `Configuration` temporal node | process `['temporal' => ['api_key' => 'k', 'retryable_errors' => [LogicException::class], 'default_worker_options' => ['maxConcurrentActivityExecutionSize' => 5]]]` | values pass through; unknown worker option key → `InvalidArgumentException` | defaults: `retryable_errors === [\Error::class]` |
| TC-17 | extension Temporal service wiring | build a container, load extension with `temporal/sdk` present | `TemporalWorker` registered under `MODE_TEMPORAL`; interceptor aliases point to the **correct** bundle interceptor (regression guard for F-7) | without SDK class → no Temporal services defined |

**Components with fewer than 5 unit tests:** the three exception classes (`*NotAssigned`,
`*NotPristine`) are empty `RuntimeException` subclasses — their behavior is exercised
transitively in TC-03/TC-05; no dedicated tests (recorded reason: no logic to test).

### 6.2 Integration / live tests

Marked `#[Group('temporal-live')]`, skipped unless provisioned (§7).

| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|
| IT-01 | Workflow execution | running Temporal server + RR `temporal` worker started against the test kernel; a `GreetingWorkflow` registered on the default queue | client starts the workflow, gets the expected result | terminate workflow, stop worker |
| IT-02 | Activity invocation | as IT-01 with a `GreetingActivity` | the workflow's activity call returns the activity's output | — |
| IT-03 | Interceptor event firing live | as IT-01 with a listener counting `ExecuteActivityEvent` | the listener observed ≥1 event during a real run | — |
| IT-04 | Signal / query | a workflow exposing a signal + query | sending the signal mutates state observable via the query | — |

---

## 7. Live test environment (exact requirements)

Live tests skip by default. To run them:

1. A reachable **Temporal server** (e.g. `temporal server start-dev` or docker
   `temporalio/auto-setup`) on its default frontend `127.0.0.1:7233`.
2. The **RoadRunner binary** with the `temporal` plugin and an `.rr.yaml` enabling
   `temporal: { address: "127.0.0.1:7233" }` and an RPC plugin (`RR_RPC` env set).
3. Environment: `TEMPORAL_LIVE=1` (gate), `RR_RPC=tcp://127.0.0.1:6001` (or as configured).
4. Run: `TEMPORAL_LIVE=1 php vendor/bin/phpunit tests --group temporal-live`.

Without `TEMPORAL_LIVE=1` every `temporal-live` test calls `markTestSkipped()` in `setUp()`.

---

## 8. Error Handling Matrix

### 8.1 Worker / wiring errors

| Error type | Detection | Response | Fallback | Logging | Alert |
|------------|-----------|----------|----------|---------|-------|
| `RR_RPC` not set | `RPCConnectionFactory::fromEnvironment` | throw `InvalidRPCConfigurationException` | none (boot-time fail-fast) | exception bubbles | — |
| Activity missing `#[TaskQueue]` | compile-time scan in `registerTemporal` | throw `ActivityNotAssignedException` | none | container build fails loudly | — |
| Workflow missing `#[TaskQueue]` | compile-time scan | throw `WorkflowNotAssignedException` | none | container build fails loudly | — |
| Worker not pristine (activities/workflows/finalizer pre-set) | `ensureWorkerIsPristine` | throw `WorkerNotPristineException` | none | — | — |
| Temporal server unreachable at run | `$factory->run()` / RR plugin | RR restarts the worker per its policy | bundle does not interpose | STDERR via RR | — |
| Unhandled throwable inside `run()` | `TemporalWorker::start()` try/finally | Sentry capture + flush (best-effort), rethrow | RR restarts worker | Sentry | Sentry |

### 8.2 Config errors

| Error type | User message | Code | Recovery |
|------------|--------------|------|----------|
| Unknown `default_worker_options` key | `Unknown worker option "X". Available options are: …` | `InvalidArgumentException` | fix config key |

---

## 9. Assumptions

| Assumption | If wrong, then… |
|------------|-----------------|
| `temporal/sdk` v2.16+ API (`newWorker`, `WorkerFactory::create`, `Worker::getID`, interceptor traits/inputs) is stable across 2.16–2.17. | Signatures shift → interceptor/initializer type errors; pin a narrower constraint. |
| Each `TemporalWorkerInterface::create()` returns a `Temporal\Worker\Worker` (the only concrete impl the SDK provides) exposing `getID()`. | A custom impl returning a non-`Worker` `WorkerInterface` → `getID()` guard throws; documented in F-5. |
| The bundle cannot/should not interpose per-message framing in the Temporal loop (D-7). | If a graceful-error seam is later required, `TemporalWorker` needs a custom run loop. |

## 10. Open Questions

| Question | Why it matters | Blocks | Status |
|----------|----------------|--------|--------|
| OQ-1: Should `TemporalWorker` apply graceful per-message error handling like HTTP/Centrifugo? | Prompt asks to apply the pattern "where applicable". | Worker loop shape | **Resolved (reversible):** Not applicable — Temporal owns framing via `$factory->run()`; bundle seam is the activity finalizer + Sentry boundary (D-7). Reversible: a future custom loop could add it. |
| OQ-2: Public attribute name. | Irreversible public API (Clarity check 7). | attribute name | **Resolved.** The attribute is `#[TaskQueue]` (the earlier `#[AssignToWorker]` name was removed before release). |
| OQ-3: Temporal config node shape (`api_key`, `retryable_errors`, `default_worker_options`) — public config API. | Irreversible config surface. | config keys | **Flagged.** Kept incoming shape; documented. |

## 11. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Graceful error handling pattern | [docs/specs/graceful-error-handling.md](graceful-error-handling.md) | §4 |
| Worker dispatch | [src/Runtime/Runner.php](../../src/Runtime/Runner.php) | `run()` |
| Worker registration | [config/services.php](../../config/services.php) | `registerWorker` calls |
| Mode constants | vendor `Spiral\RoadRunner\Environment\Mode` | `MODE_TEMPORAL` |
| Temporal worker SDK | vendor `Temporal\Worker\WorkerFactoryInterface` | `newWorker` |
