# RoadRunner Jobs (Queue Consumer) Worker (Implementation)

**Source pinned to:** branch `feature/rr-jobs` off `master` @ `d6b9c0f`, 2026-06-02.
**Component:** new `FluffyDiscord\RoadRunnerBundle\Worker\JobsWorker` + new `Event\Worker\Jobs\JobsRunEvent`, registered under `Spiral\RoadRunner\Environment\Mode::MODE_JOBS`.
**Dependency added:** `spiral/roadrunner-jobs` (installed `v4.7.0`; constraint `^4.7`) — compatible with the existing `spiral/roadrunner-worker:^v3` (both share `Spiral\RoadRunner\Worker`/`WorkerInterface`).
**Scope decision:** add a third worker type (queue consumer) that mirrors the existing HTTP/Centrifugo worker architecture and the graceful-error-handling pattern, dispatching one `JobsRunEvent` per task with ack-on-success / nack-with-requeue-on-failure semantics.

This is **brownfield delta** work: the worker-registration architecture, the graceful-error-handling pattern, and the test harness style already exist and are recorded below from the code (file+line). The Jobs worker is specified as a delta against them.

---

## 1. Reverse-engineered baseline (cited)

| # | Fact about the existing system | Evidence |
|---|--------------------------------|----------|
| B1 | Workers implement `WorkerInterface` with a single `start(): void`. | `src/Worker/WorkerInterface.php:5-8` |
| B2 | Workers register into `WorkerRegistry` keyed by RR mode string via `registerWorker(string $mode, WorkerInterface $worker)`. | `src/Worker/WorkerRegistry.php:10-13` |
| B3 | `Runner::run()` boots the kernel, fetches the `WorkerRegistry` service, looks up the worker by `$this->mode`, and calls `start()`; missing worker → `error_log` + return 1. | `src/Runtime/Runner.php:19-39` |
| B4 | DI wires each worker as a `public()` service, then `WorkerRegistry::registerWorker` is called with the `Environment\Mode::MODE_*` constant + the worker service. Centrifugo wiring is guarded by `class_exists(RoadRunnerCentrifugoWorker::class)`. | `config/services.php:85-91` (HTTP), `:94-138` (Centrifugo, guarded) |
| B5 | The graceful-error-handling pattern: a `private bool $shutdownRegistered` instance guard; per-iteration flags captured by reference into a `register_shutdown_function` closure; per-request `try/catch (\Throwable)`; `\Error` → `getWorker()->stop()` + `continue`; STDERR via `logError()`; best-effort Sentry `pushScope`/`captureException`/`flush`/`popScope`; `servicesResetter?->reset()` and kernel reboot in `finally`. | `src/Worker/CentrifugoWorker.php:42-292` |
| B6 | Test seams: `protected` overridable `waitRequest()`, `registerShutdown(callable)`, `logError(string)`; a `Testable*Worker` subclass in the test file exposes them + `callHandleShutdown(...)`. Final RR classes are not mocked; the loop is driven via the `waitRequest()` seam. | `tests/Worker/AbstractCentrifugoWorkerTestCase.php:23-100` |
| B7 | Config nodes live in `src/DependencyInjection/Configuration.php`; each worker section is an `arrayNode(...)->addDefaultsIfNotSet()` with a `lazy_boot` boolean (HTTP+Centrifugo). The Extension reads `processConfiguration` and `replaceArgument` on the worker definition when `hasDefinition`. | `src/DependencyInjection/Configuration.php:29-135`; `FluffyDiscordRoadRunnerExtension.php:73-86` |
| B8 | `WorkerBootingEvent`, `WorkerRequestReceivedEvent`, `WorkerResponseSentEvent(string $workerType)` are dispatched at boot / per-request-received / per-response-sent in every worker. | `src/Worker/CentrifugoWorker.php:65,90,123`; `src/Event/Worker/*` |

## 2. roadrunner-jobs API surface (reverse-engineered, cited — `v4.7.0`)

| # | Fact | Evidence |
|---|------|----------|
| J1 | `Consumer` is `final`, implements `ConsumerInterface`, ctor `(?WorkerInterface $worker = null, ?ReceivedTaskFactoryInterface $receivedTaskFactory = null)`. `waitTask(): ?ReceivedTaskInterface` returns `null` when the worker payload is `null` (stop signal). | `vendor/spiral/roadrunner-jobs/src/Consumer.php:35-60` |
| J2 | `ConsumerInterface::waitTask(): ?ReceivedTaskInterface` is a plain interface — **mockable**. | `vendor/spiral/roadrunner-jobs/src/ConsumerInterface.php` |
| J3 | `ack()`, `nack(string\|\Stringable\|\Throwable $message, bool $redelivery=false)`, `requeue(...)` are declared on `ReceivedTaskInterface` **only as `@method` PHPDoc tags**, NOT as real interface methods; they are real concrete methods on `ReceivedTask`. The interface's *real* methods include `isCompleted(): bool`, `complete()`, `fail($error,$requeue)`, `getQueue()`, `getDriver()`. **Consequence: a PHPUnit interface mock CANNOT configure `ack`/`nack`/`requeue`** (magic methods) — tests use a real spy double instead (§N-2). PHPStan recognizes the `@method` tags, so production calls type-check at level max. | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTaskInterface.php:11-13` (`@method`); `Task/ReceivedTask.php:107-160` (concrete) |
| J4 | From `TaskInterface`: `getName(): non-empty-string` (job name), `getPayload(): string`. From `ProvidesHeadersInterface`: `getHeaders(): array<non-empty-string, array<string>>`. | `Task/TaskInterface.php`; `Task/ProvidesHeadersInterface.php` |
| J5 | `nack($message, redelivery: true)` sends a NACK with requeue; `ack()` sends ACK. `ReceivedTask::respond()` is idempotent (guards on `$this->completed === null`), so a double ack/nack on the *same* task object is a silent no-op; `isCompleted()` reports whether the task already responded. | `Task/ReceivedTask.php:118-160` |
| J6 | `Mode::MODE_JOBS === 'jobs'`. | `vendor/spiral/roadrunner-worker/src/Environment/Mode.php` |
| J7 | The bundle already builds `Spiral\RoadRunner\WorkerInterface` as a non-shared DI service via `RoadRunnerWorker::createFromEnvironment`. A `Consumer` can be built from it. | `config/services.php:40-47` |

## 3. The 7 Questions (brownfield — answers settled by the existing system are recorded as-is)

1. **Exact problem:** A Symfony app served by RoadRunner can run HTTP and Centrifugo workers through this bundle, but has **no supported way to consume RoadRunner `jobs` (queue) tasks** — `Runner` looks the mode up in `WorkerRegistry` (B3) and there is no `MODE_JOBS` entry, so `rr serve` with a `jobs` plugin pool exits with "Missing RR worker implementation for jobs mode". Goal: a developer adds the bundle, configures a `jobs` pool in `.rr.yaml`, and listens to a single `JobsRunEvent` to process tasks, with the same graceful error handling the other workers have.
2. **Success metrics:** (a) `rr serve` with a `jobs` pool dispatches one `JobsRunEvent` per task; (b) success → `ack()`, listener-thrown `\Throwable` → `nack(..., redelivery: true)` (job not lost); (c) PHPStan level max → 0 errors; (d) `phpunit tests` → all green, ≥5 unit + the loop/integration coverage in §N-2 added, existing 196 tests still pass.
3. **Why it fits:** Reuses the existing `WorkerInterface`/`WorkerRegistry`/`Runner` seam (B1-B3) and the proven graceful-error-handling pattern (B5) — no new architecture, no new runtime entrypoint.
4. **Core architecture decision:** Mirror `CentrifugoWorker` (the closest analog: a non-HTTP, no-human-page worker). Consume via `ConsumerInterface` injected through a `waitTask()` seam (J2). Dispatch exactly one `JobsRunEvent`, then ack/nack based on whether the loop body threw. See ADR-1/2/3 below.
5. **Tech-stack rationale:** `spiral/roadrunner-jobs` is the official spiral package for the RR `jobs` plugin and shares the `Spiral\RoadRunner\Worker` core already required (J1, J7) — no competing/duplicate dependency.
6. **MVP features:** consume loop; one `JobsRunEvent` (queue=pipeline name, payload, headers — plus name/id for richer listeners); ack-on-success; nack-with-requeue-on-throwable; graceful shutdown rescue (best-effort requeue); `lazy_boot` config; worker lifecycle events (B8).
7. **NOT building (explicit exclusions):**
   - **No per-job-name routing / handler attributes** (no `#[AsJobListener]`). One generic `JobsRunEvent`; listeners branch on `getName()`/`getQueue()` themselves. Rationale: matches the reference's single-event shape; attribute routing is a separable future feature and would be over-engineering for the stated ask.
   - **No symfony/messenger transport.** Out of scope; this is a raw RR consumer.
   - **No automatic task serialization/deserialization.** Payload is handed to the listener as the raw string (J4); the app owns its format.
   - **No live Centrifugo-style "real RR" integration test committed to the suite.** A live test requires a provisioned RR binary + a `jobs` pool; documented and `@group jobs-live` / skipped (see §N-2), not run in CI by default — mirrors the Centrifugo decision (graceful-error-handling.md "Testing reality").
   - **No manual garbage collection.** Mirrors `CentrifugoWorker`, which performs no manual
     `gc_collect_cycles()`; the worker relies on PHP's own cycle collector (ADR-4).

## ADRs

- **ADR-1 — Consume via `ConsumerInterface` injected through a `waitTask()` seam.** The bundle wires a `Consumer` service from the existing `Spiral\RoadRunner\WorkerInterface` (J7) and injects it. `Consumer` is `final` (J1) so it cannot be mocked, but `ConsumerInterface` (J2) is the typed dependency and the worker calls it through a `protected waitTask(): ?ReceivedTaskInterface` seam (mirrors `CentrifugoWorker::waitRequest()`, B6). Tests inject a mock `ConsumerInterface` **or** override the seam. *Trade-off:* one extra DI service vs. the worker calling `Consumer::create()` lazily; the DI route is testable and matches B4.
- **ADR-2 — One `JobsRunEvent` carrying queue + payload + headers + name + id + the task itself.** The reference event carries `(queue, payload, headers)`. We additionally expose `getName()` (job name) and `getId()`, and the underlying `ReceivedTaskInterface` via `getTask()`, so a listener can branch and, if it wants, control delay. The event does **not** let listeners ack/nack — the worker owns the ack/nack decision based on whether a listener threw (keeps the one-frame invariant analogous to the other workers). *Trade-off:* exposing the task object leaks the spiral type into userland, but it is the only way to give listeners pipeline/header/delay access without re-wrapping the whole API.
- **ADR-3 — Failure = `nack(message, redelivery: true)`; success = `ack()`.** A listener throwing means the job did not process; requeue so it is retried rather than silently dropped (the reference hardcoded `requeue: false` with a TODO — we choose `true` as the safe default for an unhandled failure). *Irreversible-ish caveat:* requeue-on-failure can cause an infinite redelivery loop for a poison message — see Open Question OQ-1; this is the documented, deliberate default and is overridable by the app catching its own exceptions inside the listener (then the worker sees success → ack).
- **ADR-4 — No manual garbage collection.** `CentrifugoWorker` performs no manual `gc_collect_cycles()`, and the Jobs worker mirrors it: PHP's own cycle collector runs between tasks. A manual periodic GC (the reference used every 20 tasks) adds cost without a measured benefit here, and diverges from the sibling workers — so it is omitted.
- **ADR-5 — No `MODE_JOBS` `boot()` per task.** `CentrifugoWorker` calls `$this->kernel->boot()` once per request (`CentrifugoWorker.php:92`); `boot()` is idempotent (re-boot is a no-op once booted). Jobs mirrors this for parity, so lazy_boot=true still boots before the first task is handled.

---

## 4. Design

### 4.1 `Event\Worker\Jobs\JobsRunEvent` (new)

```php
namespace FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Contracts\EventDispatcher\Event;

class JobsRunEvent extends Event
{
    public function __construct(
        private readonly ReceivedTaskInterface $task,
    ) {}

    public function getTask(): ReceivedTaskInterface { return $this->task; }
    public function getQueue(): string   { return $this->task->getQueue(); }      // broker queue name
    public function getPipeline(): string{ return $this->task->getPipeline(); }   // RR pipeline name
    public function getName(): string    { return $this->task->getName(); }       // job name
    public function getId(): string      { return $this->task->getId(); }
    public function getPayload(): string { return $this->task->getPayload(); }
    /** @return array<non-empty-string, array<string>> */
    public function getHeaders(): array  { return $this->task->getHeaders(); }
}
```

Derives all accessors from the task (single source) rather than copying strings, so it cannot drift from the task.

### 4.2 `Worker\JobsWorker` (new) — structure mirrors `CentrifugoWorker`

Constructor (DI order fixed by `config/services.php`, §4.4):

```php
public function __construct(
    private readonly bool                       $lazyBoot,
    private readonly KernelInterface            $kernel,
    private readonly ConsumerInterface          $consumer,
    private readonly RrWorkerInterface          $rrWorker,   // goridge worker for stop()/error(); SAME instance the Consumer wraps (A-2)
    private readonly EventDispatcherInterface   $eventDispatcher,
    private readonly ?ServicesResetterInterface $servicesResetter,
    private readonly ?SentryHubInterface        $sentryHubInterface = null,
) {}
```

`RrWorkerInterface` = `Spiral\RoadRunner\WorkerInterface`. It is injected explicitly (rather than fished out of the `Consumer`, which exposes no getter — verified) so `stop()`/`error()` have a target. The DI wiring (§4.4) feeds **one shared** goridge worker into both the `Consumer` and this arg, so they act on the same relay.

No `debug` flag: a job has no human-visible page and no client-facing message to redact (unlike HTTP/Centrifugo), so `debug` is not a worker input. (Diagnostics go to STDERR/Sentry regardless.)

`start()` algorithm:

1. `if (!$this->lazyBoot) { $this->kernel->boot(); }`
2. `dispatch(new WorkerBootingEvent())`.
3. Declare loop-local flags `bool $handlingTask=false; bool $responded=false; ?ReceivedTaskInterface $currentTask=null;`.
4. Once-guard: `if (!$this->shutdownRegistered) { $this->shutdownRegistered = true; $this->registerShutdown(fn() => $this->handleShutdown($handlingTask, $responded, $currentTask, error_get_last())); }` (captured by reference). (Invariant I-3, B5.)
5. `$processed = 0;`
6. `while ($task = $this->waitTask()) {` — `waitTask()` returns `null` on the RR stop signal (J1), ending the loop.
   1. reset per-iteration: `$handlingTask=true; $responded=false; $currentTask=$task; $hadException=false;`
   2. `try {`
      - `$this->sentryHubInterface?->pushScope();`
      - `dispatch(new WorkerRequestReceivedEvent());`
      - `$this->kernel->boot();` (idempotent; ADR-5)
      - `dispatch(new JobsRunEvent($task));`
      - `if (!$task->isCompleted()) { $task->ack(); }` then `$responded=true;` — a listener *may* have already acked/nacked via `getTask()`; respect it (J5).
      - `dispatch(new WorkerResponseSentEvent(Mode::MODE_JOBS));`
   3. `} catch (\Throwable $throwable) {`
      - `$hadException=true;`
      - best-effort `sentryHubInterface?->captureException($throwable)` in nested try/catch.
      - **single response frame:** `if (!$responded && !$task->isCompleted()) { $responded=true; $this->sendThrowableResponse($task, $throwable); }`
      - `$this->logError((string)$throwable);` (STDERR, never a second relay frame — Invariant I-2.)
      - `if ($throwable instanceof \Error) { $this->rrWorker->stop(); continue; }`
   4. `} finally {`
      - reboot on exception: nested try/catch `if ($hadException && $kernel instanceof RebootableInterface) $kernel->reboot(null);` → on cleanup throwable `logError(...)` + `stop()`.
      - inner finally: `try { $servicesResetter?->reset(); } catch (\Throwable $t) { logError; stop(); }`.
      - best-effort Sentry `getClient()?->flush()` + `popScope()` each in try/catch.
      - `$handlingTask=false; $currentTask=null;`
   5. `}`

`sendThrowableResponse(ReceivedTaskInterface $task, \Throwable $throwable): void` (Bucket A):
```
try { $task->nack($throwable, redelivery: true); }     // ADR-3: requeue on failure
catch (\Throwable) { try { $this->rrWorker->error((string)$throwable); } catch (\Throwable) {} }
```
`nack()` accepts a `\Throwable` directly (J3) — its `(string)` cast is the message; no trace is sent to any client because there is no client (only the broker's failure record), so unlike Centrifugo there is no `clientMessage()` redaction needed.

`handleShutdown(bool $handlingTask, bool $responded, ?ReceivedTaskInterface $task, ?array $error): void` (Bucket B):
```
if (!$handlingTask || $responded || $task === null) return;            // Invariants I-1/I-2
if ($error OOM 'Allowed memory size') @ini_set('memory_limit','-1');
try { if (!$task->isCompleted()) $task->nack('Worker terminated during task', redelivery: true); } catch (\Throwable) {}   // best-effort requeue so the job is retried
logError(fatal-or-generic message);                                    // STDERR (the point)
try { sentryHubInterface?->captureMessage('RoadRunner Jobs worker fatal: '...); flush(); } catch (\Throwable) {}
```
No `MinimalErrorPage` (no page — same rationale as Centrifugo). The rescue **requeues** rather than dropping, because a half-processed task that died should be retried (ADR-3); B′ (mid-final-response) does not exist for Jobs — a task produces exactly one ack/nack frame, never a stream, so the only guard needed is `!$responded && !isCompleted()`.

Seams (B6): `protected waitTask(): ?ReceivedTaskInterface` (default `return $this->consumer->waitTask();`), `protected registerShutdown(callable $h)`, `protected logError(string $m)`. `stop()`/`error()` go through `$this->rrWorker` directly (the explicitly-injected goridge worker); no `getRrWorker()` seam is needed because `rrWorker` is already a constructor dependency a test supplies as a mock.

Constants: none.

`class JobsWorker implements WorkerInterface` (plain class, `private readonly` promoted props + one mutable `private bool $shutdownRegistered = false;`).

### 4.3 Configuration node (new) — `jobs.lazy_boot`

In `Configuration.php`, after the `centrifugo` node, add an `arrayNode("jobs")->addDefaultsIfNotSet()` with a single `booleanNode("lazy_boot")->defaultFalse()` and an `info()` block ("Jobs / queue consumer worker. Will activate only when "spiral/roadrunner-jobs" is installed. See http section for lazy_boot."). The `$config` PHPDoc type in the Extension gains `jobs: array{lazy_boot: bool}`.

### 4.4 DI wiring (new) — `config/services.php`, guarded

After the Centrifugo block, guard on `class_exists(\Spiral\RoadRunner\Jobs\Consumer::class)` (mirrors B4):

```php
if (class_exists(Consumer::class)) {
    // ONE shared goridge worker (NOT the ->share(false) default service) so the Consumer
    // and the JobsWorker's stop()/error() act on the SAME relay (A-2).
    $services->set("fluffy_discord.jobs.rr_worker", RoadRunnerWorker::class)
        ->factory([RoadRunnerWorker::class, "createFromEnvironment"])
        ->args([ service(EnvironmentInterface::class) ]);   // shared by default (no ->share(false))

    $services->set(ConsumerInterface::class, Consumer::class)
        ->args([ service("fluffy_discord.jobs.rr_worker") ]);   // 2nd ctor arg defaults (J1)

    $services->set(JobsWorker::class)->public()->args([
        false,                                   // lazy_boot — replaced by Extension
        service(KernelInterface::class),
        service(ConsumerInterface::class),
        service("fluffy_discord.jobs.rr_worker"),
        service(EventDispatcherInterface::class),
        service("services_resetter")->nullOnInvalid(),
        service(SentryHubInterface::class)->nullOnInvalid(),
    ]);

    $services->get(WorkerRegistry::class)
        ->call("registerWorker", [ Environment\Mode::MODE_JOBS, service(JobsWorker::class) ]);
}
```

Extension: add `if ($container->hasDefinition(JobsWorker::class)) { $def->replaceArgument(0, $config["jobs"]["lazy_boot"]); }` (mirrors `FluffyDiscordRoadRunnerExtension.php:77-86`).

---

## Assumptions

| # | Assumption | If wrong, then… |
|---|------------|-----------------|
| A-1 | `nack($throwable, redelivery: true)` is the correct "retry this job" signal across RR queue drivers (J3/J5). | If a driver ignores `redelivery`, the job is dropped not retried; app must catch internally. Reversible (change the flag). |
| A-2 (resolves OQ-2) | A single shared goridge `Spiral\RoadRunner\WorkerInterface` instance must back both the `Consumer` and the worker's `stop()` path. `Consumer` exposes **no** getter for its worker (verified `Consumer.php:37` `private readonly`), so the worker takes an explicit `RrWorkerInterface $rrWorker` ctor arg. Wiring: a dedicated **shared** service `fluffy_discord.jobs.rr_worker` (its own `createFromEnvironment` factory, NOT the `->share(false)` default `RoadRunnerWorkerInterface`) is passed to **both** the `Consumer` arg and the `$rrWorker` arg (§4.4). | If two different instances were used, `stop()` would act on a worker not in `waitPayload()`. In practice RR runs one worker per process and `stop()` sets a flag the next loop reads, so even distinct wrappers over the same STDIN/STDOUT relay would behave — but the dedicated shared service removes the question entirely. |
| A-4 | `kernel->boot()` is idempotent per the existing Centrifugo usage (B5/ADR-5). | If not, a second boot per task would error — but Centrifugo already relies on this in production. |
| A-5 | A listener may legitimately call `ack()`/`nack()` itself via `getTask()`; the worker must not double-respond. Handled by the `isCompleted()` guard (J5). | If `isCompleted()` semantics differ, a double-respond is a silent no-op anyway (J5). |

## Open Questions

| # | Question | Why it matters | Blocks | Status |
|---|----------|----------------|--------|--------|
| OQ-1 | Should failure default to requeue (`true`) or drop (`false`)? Requeue risks a poison-message redelivery loop. | Determines data-loss vs. infinite-retry trade-off. | Nothing (a default must be chosen; app can override by catching internally). | **Resolved (ADR-3): default `redelivery: true`** — not losing the job is the safer default; documented in README with the poison-message caveat. Reversible. |
| OQ-2 | Same goridge worker instance for `Consumer` + `stop()`? (`->share(false)`, J7.) | Correct `stop()` target. | Wiring shape. | **Resolved (A-2): dedicated shared `jobs.worker` service injected into both.** |
| OQ-3 | Live RR `jobs` integration test in the committed suite? | CI needs a provisioned RR binary + jobs pool. | Nothing. | **Resolved (exclusion #7): write it, mark `@group jobs-live`, skip by default** — mirrors Centrifugo. |

| OQ-4 | Should `waitTask()` throwing (`ReceivedTaskException`/`SerializationException` on a malformed payload, J1) be caught in the loop? | A throw there ends `start()` → process dies → RR respawns. | Nothing. | **Resolved: match the sibling `CentrifugoWorker`, which does NOT wrap `waitRequest()` (B5).** A deserialize failure crashing+respawning the worker is acceptable and consistent; wrapping it would be a divergence from the established pattern for no stated requirement. Flagged for parity review if Centrifugo later adds a guard. |

*No user-blocking unknown remains. OQ-1 is a deliberate, reversible default, flagged for the user in the README. OQ-4 documents a deliberate parity choice with the existing worker.*

---

## N-3. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Ack a task **and** also `$this->rrWorker->error()` in the same cycle | One ack/nack frame; `error()` only if the ack/nack itself throws | Two goridge frames desync the worker (Invariant I-2, B5) |
| Drop a failed task silently (`nack(..., false)` as the default, or no nack) | `nack($throwable, redelivery: true)` by default (ADR-3) | An unhandled failure should be retried, not lost |
| Ack/nack a task the listener already completed | Guard with `$task->isCompleted()` before `ack()`/`nack()` (J5, A-5) | Avoids fighting a listener that took ownership |
| Build the shutdown-path response via the container/kernel | `handleShutdown` touches only the task + STDERR/Sentry (bounded) | After die/exit/fatal the kernel may be half-destroyed (Invariant I-4) |
| Register the shutdown function more than once | `private bool $shutdownRegistered` guard (Invariant I-3) | `register_shutdown_function` is append-only; stacked closures multiply nacks |
| `echo`/`print`/dump in the worker loop | Write diagnostics to STDERR via `logError()` | In `pipes` mode STDOUT is the goridge channel (graceful-error-handling.md O4) |
| Re-fatal in the shutdown handler (kernel access, big allocations) | Bounded ops only; wrap the nack in try/catch | A fatal inside a shutdown fn is terminal (Invariant I-4) |
| Send a stream / multiple frames per task | Exactly one ack/nack per task | Jobs has no streamed-response (B′) case; one frame only |
| Manual `gc_collect_cycles()` in the loop | Rely on PHP's cycle collector, like `CentrifugoWorker` (ADR-4) | Diverges from the sibling workers; no measured benefit |

## N-2. Test Case Specifications

Harness: new `tests/Worker/AbstractJobsWorkerTestCase.php` + `TestableJobsWorker` (mirrors `AbstractCentrifugoWorkerTestCase`, B6). `ConsumerInterface` is mockable (J2). `ReceivedTaskInterface`'s `ack`/`nack`/`requeue` are `@method` magic (J3) so the interface **cannot be mocked for those** — tests use a real **`SpyReceivedTask`** double implementing `ReceivedTaskInterface` with concrete `ack()`/`nack()`/`requeue()` that record their calls (`$ackCount`, `$nackCalls`) and an `isCompleted()` flag. The goridge `Spiral\RoadRunner\WorkerInterface` is mockable and is injected as `$rrWorker` for `stop()`/`error()`.

### Unit tests
| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-01 | loop, success | one task, listener does nothing | `ack()` called once; `nack()`/`error()` never; `JobsRunEvent` dispatched once; `WorkerResponseSentEvent` with `'jobs'` | empty queue → `waitTask()` null → loop skipped, no ack |
| TC-02 | loop, soft failure | listener throws `\RuntimeException` | `nack($t, redelivery:true)` once; worker **not** stopped; `(string)$t` logged to STDERR; `error()` never | — |
| TC-03 | loop, hard failure | listener throws `\Error` | `nack` once; `$this->rrWorker->stop()` called; `continue` (no further tasks acked); error logged | — |
| TC-04 | `JobsRunEvent` getters | task with name/queue/pipeline/id/payload/headers | each getter returns the task's value | empty headers → `[]` |
| TC-05 | listener pre-acks | listener calls `getTask()->ack()` (mock `isCompleted()` → true after) | worker does **not** call `ack()` again | listener nacks → worker doesn't ack |
| TC-06 | `handleShutdown` | `handlingTask=true, responded=false, task` set, error array | best-effort `nack(...,redelivery:true)` once; fatal message logged; no exception escapes | `isCompleted()` true → no nack |
| TC-07 | `handleShutdown` bare die/exit | `error=null` | generic `die/exit` message logged; best-effort nack | — |
| TC-08 | `handleShutdown` no-op | `responded=true` OR `handlingTask=false` OR `task=null` | nothing logged, no nack | all three branches |
| TC-09 | `handleShutdown` nack throws | task `nack()` throws | swallowed (no escape); still logs | — |
| TC-10 | shutdown registration | `start()` twice on same instance (empty queue) | registers exactly once; fresh instance registers again | spy via `registerShutdown` seam |
| TC-11 | loop integrity, many tasks | run 25 tasks back-to-back | loop completes without error; every task acked exactly once; no errors logged | fewer tasks → still fine |

### Integration tests
| Test ID | Flow | Setup | Verification | Type |
|---------|------|-------|--------------|------|
| IT-01 | multi-task loop | 3 tasks, mixed success/throw | each acked or nacked exactly once; order preserved; events per task | mock |
| IT-02 | reset + reboot | listener throws `\RuntimeException` | `servicesResetter->reset()` called; kernel `reboot(null)` called (kernel is `RebootableInterface` mock) | mock |
| IT-03 | registry wiring | build `WorkerRegistry`, register `JobsWorker` under `'jobs'` | `getWorker('jobs')` returns the worker | mock |
| IT-LIVE | real RR jobs pool | provisioned `rr` binary + `.rr.yaml` with a `memory` jobs pool + a test consumer | a pushed task is acked; a throwing task is requeued | **`@group jobs-live`, skipped by default** — see below |

*Floors: ≥5 unit (11) and ≥3 integration (4) — met.*

**Live test environment (IT-LIVE):** requires the RoadRunner server binary on `PATH`, a `.rr.yaml` declaring `jobs: { pipelines: { test: { driver: memory } }, consume: [test] }` and a worker command running this bundle's runtime in `MODE_JOBS`, plus a jobs producer (`spiral/roadrunner-jobs` `Jobs`/`Queue` API or `rr jobs`) to push a task. It is marked `@group jobs-live` and short-circuits with `markTestSkipped()` unless `RR_JOBS_LIVE=1` and the `rr` binary are present, so the default `phpunit tests` run stays green without a provisioned RR.

## N-1. Error Handling Matrix

### Internal failures
| Error type | Detection | Response | Fallback | Logging | Worker action |
|------------|-----------|----------|----------|---------|---------------|
| Listener `\Exception` | `catch`, not `\Error` | `nack($t, redelivery:true)` (1 frame) | `$this->rrWorker->error()` if nack throws | `(string)$t`→STDERR; Sentry | reboot+reset; keep alive |
| Listener `\Error` | `catch`, `instanceof \Error` | `nack($t, redelivery:true)` | `error()` if nack throws | STDERR; Sentry | reboot+reset; `stop()`; leave loop |
| `nack()` throws (relay corrupt) | inner `try` | — | `$this->rrWorker->error(...)` once | STDERR | continue cleanup |
| die/exit/fatal, task in flight, not responded | shutdown fn + `handlingTask && !responded && task!==null && !isCompleted()` | best-effort `nack(...,redelivery:true)` | none | fatal/generic→STDERR; best-effort Sentry | process exits; RR respawns |
| OOM in shutdown | `message ~ 'Allowed memory size'` | `memory_limit=-1`, then best-effort nack | give up | STDERR | exits |
| fatal during boot (no task) | shutdown fn + `handlingTask===false` | none | — | STDERR if reachable | exits; RR respawns |
| Cleanup (`reset`/`reboot`) throws | `finally` nested try/catch | — | — | STDERR | `stop()` |
| `waitTask()` returns null | loop condition | clean loop exit | — | — | process ends normally |

### "User-facing" (broker-facing)
| Outcome | Effect on the job | Notes |
|---------|-------------------|-------|
| success | `ack()` — removed from queue | — |
| listener threw / worker died mid-task | `nack(..., redelivery:true)` — requeued for retry | poison-message caveat (OQ-1/ADR-3): an always-throwing job loops; app should catch internally to ack-and-record |

## N. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Worker interface | [`src/Worker/WorkerInterface.php`](../../src/Worker/WorkerInterface.php) | `start()` |
| Pattern to mirror | [`src/Worker/CentrifugoWorker.php`](../../src/Worker/CentrifugoWorker.php) | `start()` `:59-173`, `handleShutdown` `:181` |
| Graceful-error pattern spec | [`docs/specs/graceful-error-handling.md`](graceful-error-handling.md) | "Centrifugo worker (delta)" |
| Registry | [`src/Worker/WorkerRegistry.php`](../../src/Worker/WorkerRegistry.php) | `registerWorker` `:10` |
| Runner lookup | [`src/Runtime/Runner.php`](../../src/Runtime/Runner.php) | `run()` `:19-39` |
| DI wiring to extend | [`config/services.php`](../../config/services.php) | Centrifugo block `:94-138` |
| Config to extend | [`src/DependencyInjection/Configuration.php`](../../src/DependencyInjection/Configuration.php) | `centrifugo` node `:119-135` |
| Extension arg replace | [`src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php`](../../src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php) | `:77-86` |
| Test harness to mirror | [`tests/Worker/AbstractCentrifugoWorkerTestCase.php`](../../tests/Worker/AbstractCentrifugoWorkerTestCase.php) | `TestableCentrifugoWorker` |
| Consumer API | `vendor/spiral/roadrunner-jobs/src/Consumer.php` | `waitTask()` |
| Task API (ack/nack/requeue) | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php` | `:107-160` |
