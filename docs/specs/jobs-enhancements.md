# Jobs Bus Enhancements (Implementation)

**Source pinned to:** branch `additional-features` @ `1664c96`, 2026-06-02.
**Component group:** extensions to the existing `Job\*` message bus (docs/specs/jobs-message-bus.md) and `JobsWorker` (docs/specs/rr-jobs-worker.md). Seven features, all additive — no breaking changes to the public API (`dispatch()`, `#[AsJob]`, `#[AsJobHandler]`, `JobsRunEvent`). Internal/`@internal` signatures change: `JobRoutingListener` constructor gains two arguments, routing table tuple grows from 3 to 4 elements.
**Scope decision:** seven features enhancing the production-readiness of the Jobs bus: (A) attribute caching, (B) autoAck on `#[AsJob]`, (C) batch dispatch, (D) retry/dead-letter, (E) console commands, (F) profiler integration, (G) expose requeue-with-headers to handlers. Messenger integration is explicitly out of scope (see §Excluded).

This is **brownfield delta** work. The bus architecture, wire contract, worker loop, compiler pass, and routing listener are all established and cited below.

---

## 1. Reverse-engineered baseline (cited @ `1664c96`)

| # | Fact | Evidence |
|---|------|----------|
| B1 | `JobDispatcher::dispatch()` reads `#[AsJob]` via `new \ReflectionClass($message)->getAttributes(AsJob::class)` on **every call** — no caching. | `src/Job/JobDispatcher.php:62-69` |
| B2 | `JobDispatcher` calls `$this->jobs->connect($queue)->dispatch($task)` — single-task dispatch. SDK offers `QueueInterface::dispatchMany(PreparedTaskInterface ...$tasks): iterable` for atomic batch push via `jobs.PushBatch` RPC. | `src/Job/JobDispatcher.php:59`; `vendor/spiral/roadrunner-jobs/src/Queue.php:105-108` |
| B3 | The `Options` class supports `autoAck` (bool, default `false`): `new Options(delay, priority, autoAck)`. When `true`, RR acks the task server-side without waiting for worker response — fire-and-forget. | `vendor/spiral/roadrunner-jobs/src/Options.php:22,62,101` |
| B4 | `ReceivedTask::nack()` sends `{message, requeue, delay_seconds}` — **no `headers` field**. Headers set via `withHeader()` are NOT preserved through `nack()`. | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php:101-108` |
| B5 | `ReceivedTask::requeue()` sends `{message, delay_seconds, headers}` — headers ARE preserved. This is the only response type that carries modified headers through redelivery. | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php:110-122` |
| B5a | `ReceivedTask::withDelay(int): self` and `WritableHeadersTrait::withHeader(string, string\|iterable): self` are **immutable** — they return clones (`$self = clone $this`). Callers must capture the return value; the original task is unmodified. | `ReceivedTask.php:147-155` (withDelay); `WritableHeadersTrait.php:47-58` (withHeader) |
| B6 | `JobsInterface` (extends `\IteratorAggregate, \Countable`) — `getIterator()` yields `non-empty-string => QueueInterface` pairs via `jobs.List` RPC + `connect()`. `Jobs::pause(string\|QueueInterface ...$queues)` and `resume(...)` pause/resume via `jobs.Pause`/`jobs.Resume` RPC. **`QueueInterface` has NO `getDriver()` method** — only `getName()`, `getDefaultOptions()`, `pause()`, `resume()`, `isPaused()`, `create()`, `dispatch()`, `dispatchMany()`. | `vendor/spiral/roadrunner-jobs/src/Jobs.php:93-106`; `QueueInterface.php:14-81` |
| B7 | `JobRoutingListener::onJobsRun()` invokes all handlers for a message, then returns. Any thrown exception propagates to the worker, which calls `nack($throwable, redelivery: true)` — unconditional requeue, no retry count, no backoff, no dead-letter. | `src/Job/EventListener/JobRoutingListener.php:38-71`; `src/Worker/JobsWorker.php:88-99` |
| B8 | `JobsRunEvent` exposes `getTask(): ReceivedTaskInterface`. A listener can call `$event->getTask()->ack()/nack()/requeue()` directly; the worker checks `isCompleted()` before its own ack. | `src/Event/Worker/Jobs/JobsRunEvent.php:28-30`; `src/Worker/JobsWorker.php:93` |
| B9 | The `#[AsJob]` attribute has three fields: `?string $queue`, `?int $delay`, `?int $priority`. No `autoAck`. | `src/Job/Attribute/AsJob.php:22-25` |
| B10 | Symfony profiler collects data via `DataCollectorInterface` implementations. The web debug toolbar shows collectors registered via `data_collector` DI tag. Requires `symfony/http-kernel` (already a hard dep) and `symfony/stopwatch` (part of `symfony/framework-bundle`). | Symfony docs; this bundle's `composer.json` requires `symfony/http-kernel` |
| B11 | No Symfony console commands exist in `src/`. No `Command/` directory. | `find src/ -name '*Command*'` → empty |
| B12 | `JobEnvelope::toHeaders()` builds `['x-job-class' => [$this->messageClass], 'x-job-serializer' => [$this->serializerName]]`. No retry-count or attempt-number headers exist in the wire contract. | `src/Job/JobEnvelope.php:30-36` |

## 2. The 7 Questions (brownfield — existing answers preserved)

1. **Exact problem:** The Jobs bus is functional but lacks production hardening that power users need: (a) no batch dispatch for high-throughput producers (one RPC per message), (b) no PHP-side retry policy (only RR's binary requeue), (c) reflection on every dispatch call, (d) no Symfony profiler integration, (e) no console commands for queue operations, (f) SDK features (autoAck, requeue-with-headers) not surfaced.
2. **Success metrics:** (a) `#[AsJob]` reflection performed at most once per message class per worker process (cached); (b) `autoAck` exposed on `#[AsJob]` and `dispatch()`; (c) `JobDispatcher::dispatchBatch()` pushes N tasks in one `jobs.PushBatch` RPC call; (d) `#[RetryPolicy]` controls max attempts, delay, multiplier, dead-letter queue per message class; (e) `jobs:list`, `jobs:pause`, `jobs:resume` console commands; (f) Symfony profiler panel shows dispatched/consumed messages, handlers invoked, serializer used, timing (following the existing Centrifugo profiler pattern); (g) `#[AsJobHandler]` handlers can optionally receive `ReceivedTaskInterface` as a second parameter for `requeue()` with modified headers. PHPStan max → 0 errors; all existing tests green; new features covered.
3. **Why it fits:** All six features extend the existing bus architecture (§B1-B12) without replacing any component. Batch uses the same `PreparedTask` building. Retry wraps the existing nack/ack logic. Profiler is a pure read-only collector. Commands wrap existing `JobsInterface`. Attribute cache is internal to `JobDispatcher`.
4. **Core architecture decision:** Each feature is a self-contained addition with no new hard dependencies. Retry state travels in RR task headers (the wire contract already supports arbitrary headers); retry uses `requeue()` (not `nack()`) because only `requeue()` preserves headers through redelivery (B4/B5). Profiler collector is a tagged service. Commands are tagged `console.command`. Attribute cache uses a static `array<class-string, AsJob|false>` (not `WeakMap` — class-strings are not objects).
5. **Tech-stack rationale:** Pure PHP + Symfony DI. No new composer dependencies. Profiler timing uses `hrtime()` (PHP 8.2+ built-in). Console commands use `symfony/console` (already a hard dep via `symfony/framework-bundle`).
6. **MVP features:** All seven items from the scope (A-G). No feature is deferrable — each was explicitly selected.
7. **NOT building (explicit exclusions):**
   - **No Symfony Messenger transport.** The loop-ownership conflict (RR push vs. Messenger pull) makes a clean transport impossible. Confirmed by user. See Phase 1 analysis.
   - **No Messenger middleware pipeline.** No `MessageBusInterface` bridge. The bus remains a thin dispatch layer.
   - **No queue declaration from PHP.** `Jobs::create()` (`jobs.Declare` RPC) is not exposed — pipelines must exist in `.rr.yaml`.
   - **No driver-specific options (Kafka partition/topic, SQS attributes).** Only generic options (delay, priority, autoAck) are exposed through the attribute.
   - **No full SDK passthrough.** Advanced users who need the full `ReceivedTaskInterface` API use raw `JobsRunEvent` listeners.

## 3. ADRs

- **ADR-E1 — Retry state travels in RR task headers, not in the payload.** The retry attempt counter (`x-job-attempt`) and max attempts (`x-job-max-attempts`) are stored as task headers alongside the existing `x-job-class` / `x-job-serializer`. Headers are readable without deserializing the payload (existing pattern) and survive requeue. The alternative — embedding retry state in the serialized payload — would couple the retry mechanism to the serializer and require re-serialization on every retry, breaking the payload-is-opaque invariant. *Trade-off:* adds two headers to the wire contract (forward-compatible: old consumers ignore unknown headers).

- **ADR-E2 — Dead-letter is a re-dispatch to a configurable queue, not a separate transport.** When max attempts are exhausted, the routing listener dispatches the original payload to a dead-letter queue (`x-job-dead-letter-queue` header, or config default) via `JobsInterface::connect($dlq)->dispatch($task)`. The DLQ is a regular RR Jobs pipeline — no separate transport mechanism, no database table. *Trade-off:* requires the DLQ pipeline to exist in `.rr.yaml`; operationally simpler than a Doctrine/Redis failure store; no `messenger:failed:retry`-style CLI for replaying (the message sits in the DLQ pipeline and can be consumed by a handler or inspected via RR's admin API).

- **ADR-E3 — Retry uses `requeue()` with exponential backoff via `withDelay()`.** `nack()` does NOT preserve headers (B4) — retry state (`x-job-attempt`) would be lost. `requeue()` preserves headers (B5). Both `withDelay()` and `withHeader()` are immutable (B5a) — they return clones, so calls must be chained or captured. The routing listener computes `delay = min(baseDelay * multiplier^(attempt-1), maxDelay)`, calls `$event->getTask()->withDelay($computed)->withHeader('x-job-attempt', [...])->requeue($e)`. No timer, no scheduler — RR's own delay mechanism handles the wait. *Trade-off:* delay granularity is seconds (RR's unit); sub-second backoff is not possible. `requeue()` sends `Type::REQUEUE` (4) vs `nack(redelivery: true)` sending `Type::NACK` (3) — both result in the task being redelivered, but only `requeue()` carries modified headers.

- **ADR-E4 — Attribute cache uses a static `array<class-string, ?AsJob>` on `JobDispatcher`.** A static array keyed by class-string avoids repeated `ReflectionClass` allocation. The cache is per-process (RR workers are long-lived), persists across dispatches, and is GC'd when the worker restarts. `WeakMap` was considered but requires object keys — class-strings are not objects. *Trade-off:* unbounded growth if dispatching unbounded distinct message classes (unrealistic in practice — message classes are finite and known at compile time).

- **ADR-E5 — Profiler collector records events via Symfony's `EventSubscriberInterface`, not by wrapping services.** A `JobsDataCollector` subscribes to `JobsRunEvent` and `WorkerResponseSentEvent` to record consumed tasks, and wraps `JobDispatcher` in a `TraceableJobDispatcher` decorator for dispatch recording. The decorator is only wired when the profiler is active (`kernel.debug` container parameter). *Trade-off:* decorator adds one method call of overhead in debug mode; zero overhead in production.

- **ADR-E6 — Console commands wrap `JobsInterface` directly.** `jobs:list` iterates `JobsInterface` (which implements `\IteratorAggregate`). `jobs:pause` and `jobs:resume` call `JobsInterface::pause()/resume()`. Commands receive `JobsInterface` via DI. *Trade-off:* requires the RR RPC connection to be available when running commands (i.e., RR must be running); commands fail with a clear error if RPC is unreachable.

- **ADR-E7 — Batch dispatch returns `void`, consistent with `dispatch()`.** RR task IDs are opaque UUIDs with no SDK method to query, cancel, or check status by ID — returning them adds API surface with no use case. Tasks targeting different queues are grouped and dispatched as separate batches (one `dispatchMany()` per queue). Multi-queue batches are sequential — if the second group fails, the first is already dispatched (irrevocable). *Trade-off:* no partial-result reporting on failure; callers of multi-queue batches must handle partial dispatch.

- **ADR-E8 — `autoAck` is exposed on `#[AsJob]` but NOT on `dispatchBatch()`'s per-message level.** `autoAck` is a fire-and-forget flag set at dispatch time via `Options`. It is most naturally a property of the message class (via `#[AsJob(autoAck: true)]`) rather than a per-dispatch decision. The `dispatch()` method gains an `?bool $autoAck` parameter for explicit override. *Trade-off:* autoAck tasks cannot be nacked/requeued by the consumer — this is the point (fire-and-forget), but users must understand the implication.

---

## 4. Design

### 4.1 Feature A: Attribute cache

**Change to:** `src/Job/JobDispatcher.php`

Add a static cache for `#[AsJob]` lookups:

```php
final class JobDispatcher
{
    /** @var array<class-string, AsJob|false> */
    private static array $attributeCache = [];

    private function readAsJob(object $message): ?AsJob
    {
        $class = $message::class;
        if (!isset(self::$attributeCache[$class])) {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsJob::class);
            self::$attributeCache[$class] = $attributes === [] ? false : $attributes[0]->newInstance();
        }

        $cached = self::$attributeCache[$class];
        return $cached === false ? null : $cached;
    }
}
```

**Why `false` sentinel:** `null` would be ambiguous with "not cached yet"; `false` means "cached, no attribute found."

**Files changed:** `src/Job/JobDispatcher.php` only.

### 4.2 Feature B: `autoAck` on `#[AsJob]`

**Change to:** `src/Job/Attribute/AsJob.php`, `src/Job/JobDispatcher.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsJob
{
    /**
     * @param non-empty-string|null $queue
     * @param int<0, max>|null      $delay
     * @param int<0, max>|null      $priority
     * @param bool|null             $autoAck  When true, RR acks server-side without
     *                                         waiting for the worker. Fire-and-forget.
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly ?int $delay = null,
        public readonly ?int $priority = null,
        public readonly ?bool $autoAck = null,
    ) {}
}
```

In `JobDispatcher::dispatch()`, add `?bool $autoAck = null` parameter. Resolution: explicit arg > attribute > `false` (default).

```php
public function dispatch(
    object $message,
    ?string $queue = null,
    ?int $delay = null,
    ?int $priority = null,
    ?bool $autoAck = null,
): void {
    $attribute = $this->readAsJob($message);
    // ... existing queue/delay/priority resolution ...
    if ($autoAck === null && $attribute !== null) {
        $autoAck = $attribute->autoAck;
    }

    // ... envelope building ...
    $options = new Options(
        $delay ?? Options::DEFAULT_DELAY,
        $priority ?? Options::DEFAULT_PRIORITY,
        $autoAck ?? false,
    );
    // ... dispatch ...
}
```

**Files changed:** `src/Job/Attribute/AsJob.php`, `src/Job/JobDispatcher.php`.

### 4.3 Feature C: Batch dispatch

**New method on:** `src/Job/JobDispatcher.php`

```php
/**
 * Dispatch multiple messages in a single RPC call per queue.
 *
 * Messages targeting different queues are grouped and sent as separate batches
 * (one dispatchMany() per queue). Each message resolves its own Options
 * (delay, priority, autoAck) from its #[AsJob] attribute independently.
 *
 * @param list<object> $messages
 */
public function dispatchBatch(array $messages, ?string $queue = null): void
```

**Return type is `void`** — consistent with `dispatch()`. RR task IDs are opaque server-assigned UUIDs with no SDK method to query, cancel, or check status by ID. Returning them adds API surface with no use case. If IDs are needed in the future, a return type change is additive (non-breaking).

**Algorithm:**
1. For each message: resolve queue (explicit `$queue` arg > attribute > default), resolve delay/priority/autoAck from `#[AsJob]`, serialize, build `PreparedTask` with envelope headers and per-message `Options`.
2. Group tasks by resolved queue name, preserving input order within each group.
3. For each queue group: `$this->jobs->connect($queue)->dispatchMany(...$tasks)`.

**Per-message delay/priority/autoAck:** read from `#[AsJob]` attribute per message. No per-message override args in batch — use `#[AsJob]` defaults. The `$queue` override applies to ALL messages in the batch (convenience for single-queue batches); per-message queue routing uses `#[AsJob(queue: ...)]`.

**Error semantics:** `dispatchMany()` sends all tasks in one `jobs.PushBatch` RPC call (SDK: `Queue::dispatchMany()` → `Pipeline::sendMany()`). If the RPC throws, the exception propagates. For multi-queue batches: queue groups are dispatched sequentially. If the second queue's `dispatchMany()` fails, the first queue's tasks are already dispatched (irrevocable). The exception propagates with the first queue's tasks already sent. This is documented — callers of multi-queue batches must handle partial dispatch (the safe default is single-queue batches).

**Files changed:** `src/Job/JobDispatcher.php` only.

### 4.4 Feature D: Retry / dead-letter

This is the most complex feature. Three new components.

#### 4.4.1 `#[RetryPolicy]` attribute

**New file:** `src/Job/Attribute/RetryPolicy.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RetryPolicy
{
    /**
     * @param int<1, max>          $maxAttempts  Total attempts (including the first).
     * @param int<0, max>          $delaySeconds Base delay before first retry.
     * @param float                $multiplier   Exponential multiplier (1.0 = constant, 2.0 = doubling).
     * @param int<0, max>          $maxDelay     Cap on computed delay. 0 = no cap.
     * @param non-empty-string|null $deadLetterQueue  Pipeline for exhausted messages. Null = drop after max.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $delaySeconds = 5,
        public readonly float $multiplier = 2.0,
        public readonly int $maxDelay = 300,
        public readonly ?string $deadLetterQueue = null,
    ) {}
}
```

**Why a separate attribute (not on `#[AsJob]`):** `AsJob` controls dispatch-time routing. `RetryPolicy` controls consume-time failure behavior. Separating them keeps each attribute focused and allows retry policy without dispatch defaults (and vice versa). Also, `RetryPolicy` is read at compile time by the pass (for the routing table) and at consume time by the listener — it has a different lifecycle than `AsJob`.

#### 4.4.2 Wire contract extension (headers)

| Header | Type | Set by | Purpose |
|--------|------|--------|---------|
| `x-job-attempt` | `list<string>` (single element, numeric string) | Routing listener on retry | Current attempt number (1-indexed; first delivery = `1`). |
| `x-job-max-attempts` | `list<string>` | Routing listener on retry | Max attempts from `#[RetryPolicy]`. Informational (consumer reads policy from compile-time map). |

On first dispatch, these headers are absent (attempt = 1 is implicit). On retry, the routing listener sets them via `withHeader()` before calling `requeue()`.

**Forward-compatible:** old consumers without retry support ignore unknown headers and process the task normally (current behavior — unconditional ack on success, nack-requeue on failure).

**⚠ Irreversibility:** once deployed, the header names `x-job-attempt`, `x-job-max-attempts`, and `x-job-dead-lettered` become a public wire contract. Renaming them after deployment requires a migration period where both old and new names are read.

#### 4.4.3 Changes to `JobRoutingListener`

**Retry uses `requeue()`, not `nack()`.** SDK fact: `ReceivedTask::nack()` does not include headers in its response payload (B4: sends `{message, requeue, delay_seconds}`), so the `x-job-attempt` counter would be lost on redelivery. `ReceivedTask::requeue()` includes `$this->headers` (B5: sends `{message, delay_seconds, headers}`), preserving all headers through redelivery.

**Immutability contract:** `withHeader()` and `withDelay()` return new `ReceivedTask` clones (B5a). Every call must capture the return value — calling them without capturing silently discards the modification.

**Delay formula:** after attempt N fails (N=1,2,...), the delay before attempt N+1 is `min(baseDelay * multiplier^(N-1), maxDelay > 0 ? maxDelay : PHP_INT_MAX)`.

| Attempt that failed | Formula (base=5, mult=2.0, maxDelay=300) | Delay before next attempt |
|---------------------|------------------------------------------|---------------------------|
| 1 | `min(5 * 2^0, 300)` = 5 | 5s |
| 2 | `min(5 * 2^1, 300)` = 10 | 10s |
| 3 | `min(5 * 2^2, 300)` = 20 | 20s |
| 7 | `min(5 * 2^6, 300)` = 300 (capped) | 300s |

The routing listener gains retry logic — pseudocode with correct immutable chaining:

```
onJobsRun(event):
  envelope = JobEnvelope::fromTask(payload, headers)
  if envelope is null → return (raw listener)

  deserialize message
  attempt = (int)($headers['x-job-attempt'][0] ?? '1')
  retryPolicy = $this->retryPolicies[$envelope->messageClass] ?? null

  try:
    invoke all handlers (existing logic, with isCompleted() check after each)
  catch (\Throwable $e):
    if retryPolicy is null:
      throw $e  // existing behavior: worker nacks with requeue

    if attempt < retryPolicy.maxAttempts:
      delay = (int)min(
          retryPolicy.delaySeconds * retryPolicy.multiplier ** (attempt - 1),
          retryPolicy.maxDelay > 0 ? retryPolicy.maxDelay : PHP_INT_MAX,
      )
      // Immutable chaining — each call returns a new clone:
      $task = $event->getTask()
          ->withHeader('x-job-attempt', [(string)($attempt + 1)])
          ->withHeader('x-job-max-attempts', [(string)$retryPolicy->maxAttempts])
          ->withDelay($delay)
      try:
        $task->requeue($e)  // NOT nack() — only requeue() preserves headers (B4/B5)
      catch (\Throwable $requeueError):
        // requeue() failed (RPC/relay error). Retry state is lost — fall through
        // to worker's nack() which does NOT preserve headers. Log the degradation.
        $this->logToStderr("requeue() failed for {$envelope->messageClass}: {$requeueError->getMessage()}")
        throw $e  // re-throw original to let worker nack (retry state lost — known degradation)
      return  // task requeued with incremented attempt — don't re-throw

    if retryPolicy.deadLetterQueue is not null:
      // DLQ task: original dispatch headers ONLY, strip retry-tracking headers.
      // Payload comes from the raw task body (NOT re-serialized from the message object).
      $rawPayload = $event->getTask()->getPayload()
      $dlqHeaders = $envelope->toHeaders()  // x-job-class + x-job-serializer
      $dlqHeaders['x-job-dead-lettered'] = ['1']
      // NO x-job-attempt, NO x-job-max-attempts (stale retry state — anti-pattern row 8)
      // PreparedTask ctor: (string $name, string $payload, OptionsInterface $options, array $headers)
      // Cited: vendor/spiral/roadrunner-jobs/src/Task/PreparedTask.php:25-33
      // DLQ messages use default Options (no delay, default priority) — they are terminal
      // failures awaiting inspection, not retries.
      $dlqTask = new PreparedTask($envelope->messageClass, $rawPayload, new Options(), $dlqHeaders)

      // Dispatch to DLQ FIRST, ack original SECOND (safe ordering):
      $this->jobs->connect($retryPolicy->deadLetterQueue)->dispatch($dlqTask)
      $event->getTask()->ack()  // original task done — only after DLQ dispatch succeeds
      return

    // Max attempts exhausted, no DLQ: drop the task (⚠ irreversible data loss).
    $event->getTask()->nack($e, redelivery: false)
    $this->logToStderr("Max attempts ({$retryPolicy->maxAttempts}) for {$envelope->messageClass}, no DLQ — task dropped")
    return
```

**Attempt header validation:** `$attempt = max(1, (int)($headers['x-job-attempt'][0] ?? '1'))` — clamped to minimum 1 for defensive correctness against empty or non-numeric header values. The header is internal (set by the routing listener itself), but defensive parsing prevents delay formula anomalies.

**Decision on final failure with no DLQ:** when `#[RetryPolicy]` is present and max attempts are exhausted with no DLQ, the listener calls `$task->nack($e, redelivery: false)` — the task is dropped. The explicit `RetryPolicy` attribute is an opt-in to controlled failure semantics. If the user wants infinite requeue, they simply don't use `#[RetryPolicy]`. Log the drop to STDERR for observability.

**⚠ Irreversibility:** `nack(redelivery: false)` is destructive — the task is gone. Operators must configure a DLQ if data loss is unacceptable.

**`requeue()` failure is a known degradation:** if `requeue()` throws (RPC error, relay failure), the retry counter is lost because the fallback path is the worker's `nack()` which does not preserve headers. The task re-enters the queue at attempt 1 and restarts the retry cycle. This is documented, logged, and acceptable — the alternative (swallowing the error and losing the task) is worse. The degradation is transient (the RPC must fail specifically at the `requeue()` moment) and self-healing (the task is reprocessed, just from attempt 1).

**DLQ dispatch ordering is critical:** dispatch to DLQ first, ack original second. If DLQ dispatch fails (e.g., pipeline not in `.rr.yaml`), the exception propagates, the original task is NOT acked, and the worker nacks it — the task stays in the source queue (safe). The error handling matrix reflects this ordering.

**DLQ delivery is at-least-once.** If the worker dies between DLQ dispatch succeeding and original ack executing, the message appears in both the DLQ and the source queue (redelivered). DLQ consumers must be idempotent. This is inherent to the two-step dispatch-then-ack pattern and cannot be solved without distributed transactions.

**`JobRoutingListener` constructor changes.** The listener gains two new dependencies:
- `array $retryPolicies` — compile-time map `array<class-string, RetryPolicy>`, injected by `JobHandlerPass`.
- `JobsInterface $jobs` — for DLQ dispatch. This creates a coupling between the consumer-side listener and the producer API. This is a conscious trade-off: the alternative (emitting a `JobDeadLetterEvent` handled by a separate listener) adds complexity for no practical benefit. The coupling is pragmatic and documented.

A `#[RetryPolicy]` on a message class with no registered `#[AsJobHandler]` is silently ignored — the routing listener is never invoked for that class, so the policy is never read. No compile-time error for this case (it is harmless).

**Compile-time integration:** `JobHandlerPass` reads `#[RetryPolicy]` from each message class in the routing table and injects the `retryPolicies` map into `JobRoutingListener`. The pass also validates the `autoAck + RetryPolicy` conflict at compile time (TC-E09).

**Files changed:** `src/Job/Attribute/RetryPolicy.php` (new), `src/Job/EventListener/JobRoutingListener.php`, `src/Job/DependencyInjection/Compiler/JobHandlerPass.php`, `config/services.php`.

### 4.5 Feature E: Console commands

**New directory:** `src/Command/`

#### `jobs:list`

```php
#[AsCommand(name: 'jobs:list', description: 'List RoadRunner Jobs pipelines')]
final class JobsListCommand extends Command
{
    public function __construct(private readonly JobsInterface $jobs) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Pipeline', 'Status']);

        foreach ($this->jobs as $name => $queue) {
            $table->addRow([
                $name,
                $queue->isPaused() ? 'paused' : 'active',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}
```

**Driver column omitted.** `QueueInterface` does not expose `getDriver()` — that method exists only on `ReceivedTaskInterface` (consumed tasks, not admin-listed pipelines). `Jobs::getIterator()` yields `$queueName => QueueInterface` pairs via `connect()` (B6), which constructs `Queue` objects with no driver metadata. Driver info is visible in `.rr.yaml` and via RR's admin API — not worth coupling to the concrete `Queue` class for a `getPipelineStat()` call that returns a protobuf `Stat` object not on `QueueInterface`.

#### `jobs:pause`

```php
#[AsCommand(name: 'jobs:pause', description: 'Pause one or more RoadRunner Jobs pipelines')]
final class JobsPauseCommand extends Command
{
    public function __construct(private readonly JobsInterface $jobs) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('pipelines', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Pipeline names');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $pipelines — guaranteed by IS_ARRAY | REQUIRED */
        $pipelines = $input->getArgument('pipelines');
        \assert(\is_array($pipelines) && $pipelines !== []);
        $this->jobs->pause(...$pipelines);
        $output->writeln(\sprintf('Paused: %s', implode(', ', $pipelines)));
        return Command::SUCCESS;
    }
}
```

#### `jobs:resume`

Identical to `jobs:pause` but calls `$this->jobs->resume(...$pipelines)`.

**DI wiring:** All three commands are tagged `console.command`. Guarded by `class_exists(Consumer::class)` (same guard as Jobs services).

**Error handling:** `JobsException` from the SDK propagates naturally — Symfony console displays the exception message. No wrapping needed.

**Files changed:** `src/Command/JobsListCommand.php` (new), `src/Command/JobsPauseCommand.php` (new), `src/Command/JobsResumeCommand.php` (new), `config/services.php`.

### 4.6 Feature F: Profiler / data collector

**Architecture decision:** follow the existing Centrifugo profiler pattern (`src/Profiler/CentrifugoDataCollector` + `CentrifugoProfilerSubscriber`, wired in `config/debug.php`), NOT the Symfony HTTP-request `DataCollectorInterface::collect(Request, Response)` pattern. The Jobs worker has no HTTP request/response lifecycle — the Centrifugo sibling solved this by building `Profile` objects manually and calling `$profiler->saveProfile()`.

**New files in `src/Profiler/`:**

1. **`JobsDataCollector`** — implements `DataCollectorInterface`. Collects dispatched/consumed messages, handlers, timing, errors. Reset via `reset()` after each profile save.

2. **`JobsProfilerSubscriber`** — implements `EventSubscriberInterface`. Subscribes to `WorkerRequestReceivedEvent`, `JobsRunEvent`, `WorkerResponseSentEvent`. On each task: creates a `Profile`, populates the collector, calls `$profiler->saveProfile($profile)`. This mirrors `CentrifugoProfilerSubscriber` exactly.

3. **`TraceableJobDispatcher`** — decorator for dispatch-side recording. Wraps `JobDispatcher` via Symfony DI `service_decorator` pattern. Since `JobDispatcher` is `final`, the decorator does NOT extend it. Instead, it is registered as a DI decoration: the container replaces the `JobDispatcher` service with the decorator, which holds the original as `$inner`. Users who type-hint `JobDispatcher` in their services get the decorator transparently. No interface is needed — Symfony's service decoration replaces the service ID, and since the bundle wires `JobDispatcher` as a public service, all consumers (including the user's code) receive the decorator via the container. Direct `new JobDispatcher(...)` outside the container would bypass decoration, but this is not a supported use case.

```php
final class TraceableJobDispatcher
{
    public function __construct(
        private readonly JobDispatcher $inner,
        private readonly JobsDataCollector $collector,
    ) {}

    public function dispatch(object $message, ?string $queue = null, ?int $delay = null, ?int $priority = null, ?bool $autoAck = null): void
    {
        $start = hrtime(true);
        $this->inner->dispatch($message, $queue, $delay, $priority, $autoAck);
        $this->collector->recordDispatch($message::class, $queue ?? 'default', (hrtime(true) - $start) / 1e6);
    }

    public function dispatchBatch(array $messages, ?string $queue = null): void
    {
        $start = hrtime(true);
        $this->inner->dispatchBatch($messages, $queue);
        $this->collector->recordBatchDispatch(\count($messages), $queue ?? 'default', (hrtime(true) - $start) / 1e6);
    }
}
```

**Wiring:** All profiler services are registered in `config/debug.php` (loaded by the extension only when `kernel.debug` is true — see `FluffyDiscordRoadRunnerExtension.php:72-76`). NOT in `config/services.php`. This matches the existing Centrifugo debug wiring.

**Memory growth in long-lived workers:** The collector's `reset()` is called after each profile save by `JobsProfilerSubscriber`. In pure Jobs mode (no HTTP), profiles are saved per-task, and the collector is reset after each. No unbounded memory growth.

**Twig template:** `src/Resources/views/Collector/jobs.html.twig` — matching the existing `centrifugo.html.twig` and `temporal.html.twig` in the same directory. Referenced as `@FluffyDiscordRoadRunner/Collector/jobs.html.twig` in the `data_collector` tag.

**Files changed:** `src/Profiler/JobsDataCollector.php` (new), `src/Profiler/JobsProfilerSubscriber.php` (new), `src/Profiler/TraceableJobDispatcher.php` (new), `src/Resources/views/Collector/jobs.html.twig` (new), `config/debug.php`.

### 4.7 Feature G: Expose `requeue()` with headers

**No new code needed** — the `ReceivedTaskInterface` already supports `requeue()` and `withHeader()` (B4, B8). The task is accessible via `$event->getTask()` in raw `JobsRunEvent` listeners, and via `#[AsJobHandler]` handlers that type-hint `JobsRunEvent` alongside the message.

**However,** `#[AsJobHandler]` handlers currently receive only the deserialized message object — they have no access to the task. To expose requeue, two options:

**Option A (chosen): Pass `ReceivedTaskInterface` as an optional second parameter.**

The routing listener checks if the handler method accepts a second parameter typed as `ReceivedTaskInterface` and passes the task if so:

```php
// In JobRoutingListener::onJobsRun()
foreach ($this->routingTable[$envelope->messageClass] ?? [] as [$serviceId, $method, , $wantsTask]) {
    $handler = $this->locator->get($serviceId);
    if ($wantsTask) {
        $handler->$method($message, $event->getTask());
    } else {
        $handler->$method($message);
    }
    // If the handler called ack/nack/requeue, skip remaining handlers:
    if ($event->getTask()->isCompleted()) {
        return;
    }
}
```

The compile-time pass (`JobHandlerPass`) reflects on the handler method's second parameter. If it exists and its type is exactly `ReceivedTaskInterface` (checked via `\ReflectionNamedType::getName() === ReceivedTaskInterface::class`), the pass sets a fourth element in the routing table tuple: `[serviceId, method, priority, wantsTask: bool]`. The existing tuple `[serviceId, method, priority]` becomes `[serviceId, method, priority, false]` for handlers without the second parameter. Union types, supertypes, and nullable types are NOT matched — only the exact interface. This keeps the detection simple and explicit.

The `JobRoutingListener` PHPStan type updates from `array{0: string, 1: string, 2: int}` to `array{0: string, 1: string, 2: int, 3: bool}`.

**Option B (rejected): Inject via a context object.** A `JobContext` wrapper around the task — adds a new class for no clear benefit over passing the task directly.

**Handler signature examples:**

```php
// Simple handler — no task access
#[AsJobHandler]
final class SendEmailHandler {
    public function __invoke(SendEmail $message): void { /* ... */ }
}

// Handler with task access — can requeue with modified headers
#[AsJobHandler]
final class ProcessOrderHandler {
    public function __invoke(
        ProcessOrder $message,
        ReceivedTaskInterface $task,
    ): void {
        try {
            // process...
        } catch (TemporaryFailure $e) {
            // withHeader() and withDelay() are immutable — chain or capture (B5a):
            $task->withHeader('x-retry-reason', [$e->getMessage()])
                 ->withDelay(30)
                 ->requeue($e);
            // Task is now requeued — handler returns, listener skips remaining handlers,
            // worker sees isCompleted() = true, doesn't re-ack.
        }
    }
}
```

**Interaction with retry policy:** If a handler calls `$task->requeue()` or `$task->nack()` directly, `isCompleted()` becomes `true`. The routing listener must check this after each handler invocation — if the task is already completed, skip remaining handlers and don't apply retry policy. This is the existing pattern from B8.

**Files changed:** `src/Job/DependencyInjection/Compiler/JobHandlerPass.php`, `src/Job/EventListener/JobRoutingListener.php`.

---

## 5. Configuration changes

Extend the `jobs` config node:

```yaml
fluffy_discord_road_runner:
  jobs:
    lazy_boot: false
    serializer: native
    default_queue: default
    # NEW:
    default_dead_letter_queue: null  # Global DLQ fallback when #[RetryPolicy] has no deadLetterQueue
```

The `default_dead_letter_queue` is optional. If `null`, messages that exhaust their retry policy with no per-class DLQ are nacked without requeue (dropped, with STDERR log).

**Files changed:** `src/DependencyInjection/Configuration.php`, `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php`.

---

## Assumptions

| # | Assumption | If wrong, then... |
|---|-----------|-------------------|
| A-1 | `QueueInterface::dispatchMany()` is atomic per RPC call — the PHP SDK sends all tasks in one `jobs.PushBatch` RPC. Atomicity at the RR server level (all-or-nothing vs partial) depends on the server implementation. | If the server allows partial success, batch dispatch may need per-task error reporting. |
| A-F1 | Profiler is useful primarily in HTTP mode (toolbar renders in HTTP responses). In pure `jobs` mode, the collector records data but the toolbar panel is not visible. | Pure-queue profiler would need a CLI dashboard or log-based approach. |
| A-F2 | `hrtime(true)` available. Verified: PHP 8.2+ required by bundle; `hrtime` available since PHP 7.3. | N/A (verified). |

**Resolved (no longer assumptions):**
- ~~A-2~~ `withDelay()` returns a clone (immutable). Verified at `ReceivedTask.php:147-155`. Design uses immutable chaining throughout.
- ~~A-3~~ `nack()` does NOT preserve headers; only `requeue()` does. Verified at `ReceivedTask.php:101-108` (no `headers` key) vs `ReceivedTask.php:110-122` (`headers` included). Design uses `requeue()` for retry.
- ~~A-E1~~ `QueueInterface` exposes `getName()` and `isPaused()` but NOT `getDriver()`. Verified at `QueueInterface.php:14-81`. Console command omits driver column.

## Open Questions

| # | Question | Why it matters | Blocks |
|---|----------|----------------|--------|
| OQ-E3 | Should `dispatchBatch()` accept per-message delay/priority overrides, or only read from `#[AsJob]`? Current decision: read from `#[AsJob]` only (the `$queue` override applies to all messages in the batch). | API surface complexity vs. flexibility. Per-message overrides would require an array-of-options parameter, complicating the signature. | Batch dispatch API (§4.3). Non-blocking — the current design is usable; this is a future-enhancement question. |

**Resolved:**
- ~~OQ-E1~~ Headers persist through `requeue()` but NOT through `nack()`. Verified from SDK source. Design updated to use `requeue()`.
- ~~OQ-E2~~ Twig template placement: `templates/data_collector/jobs.html.twig` (Symfony 7+ `AbstractBundle` convention). Resolved in §4.6.

---

## Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Add `symfony/messenger` as a dependency | Keep the custom bus | Loop ownership conflict; Messenger transport would be half-dead-code |
| Cache `#[AsJob]` in a `WeakMap` | Use `static array<class-string, AsJob\|false>` | `WeakMap` requires object keys; class-strings are not objects |
| Embed retry state in the serialized payload | Use RR task headers (`x-job-attempt`, etc.) | Payload is opaque to the retry mechanism; re-serialization on retry breaks the invariant |
| Requeue infinitely when `#[RetryPolicy]` is present and max attempts exhausted | `nack(msg, redelivery: false)` or send to DLQ | Infinite requeue loop defeats the purpose of max attempts |
| Register profiler services in production | Guard with `kernel.debug` parameter | Zero overhead in production; profiler is dev-only |
| Make console commands depend on `ConsumerInterface` | Use `JobsInterface` (producer/admin API) | Commands are admin tools, not consumers; they use RPC, not the goridge relay |
| Read `#[RetryPolicy]` at runtime via reflection | Read at compile time in `JobHandlerPass` | Compile-time validation + zero runtime reflection cost |
| Dispatch to DLQ with retry headers still set | Strip `x-job-attempt` / `x-job-max-attempts` from DLQ task | DLQ messages are terminal; stale retry headers would confuse consumers |
| Allow `autoAck: true` with `#[RetryPolicy]` | Throw at compile time in `JobHandlerPass` | autoAck means RR acks server-side — the worker never processes the task, so retry is impossible |

---

## Test Case Specifications

### Unit tests

| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-E01 | `AsJob` attribute | `#[AsJob(autoAck: true)]` | `$attr->autoAck === true` | `null` default, explicit `false` |
| TC-E02 | Attribute cache | Dispatch same message class twice | `ReflectionClass` called once (spy) | No attribute → `false` cached |
| TC-E03 | `RetryPolicy` attribute | `#[RetryPolicy(maxAttempts: 5, delaySeconds: 10, multiplier: 3.0)]` | Fields match | Default values (3, 5, 2.0, 300, null) |
| TC-E04 | Retry delay computation (maxAttempts=10 for this test) | After attempt 1 fails: base=5, mult=2.0 → delay=5 | After attempt 2: delay=10. After attempt 3: delay=20. After attempt 7: delay=300 (capped at maxDelay) | maxDelay=0 → no cap. multiplier=1.0 → constant delay. |
| TC-E05 | `JobDispatcher::dispatchBatch()` | 3 messages, same queue | `dispatchMany` called once with 3 tasks; returns void | Empty array → no RPC call. Mixed queues → grouped batches. |
| TC-E06 | `JobDispatcher::dispatchBatch()` mixed queues | 2 messages queue A, 1 message queue B | `dispatchMany` called twice (one per queue); each task has per-message Options from `#[AsJob]` | Second queue failure → first queue already dispatched (irrevocable) |
| TC-E07 | `JobDispatcher::dispatch()` with `autoAck: true` | Message with `#[AsJob(autoAck: true)]` | `Options::getAutoAck() === true` on dispatched task | Explicit `autoAck: false` override |
| TC-E08 | `JobHandlerPass` with `#[RetryPolicy]` | Handler for message with `#[RetryPolicy]` | `retryPolicies` map injected into listener | Message with no policy → absent from map |
| TC-E09 | `JobHandlerPass` rejects `autoAck` + `#[RetryPolicy]` | Message with both `#[AsJob(autoAck: true)]` and `#[RetryPolicy]` | `InvalidArgumentException` at compile time | Only triggered when both are present |
| TC-E10 | `JobRoutingListener` retry on first failure | Handler throws, attempt=1, maxAttempts=3 | Task requeued via `requeue()` (not nack), delay=5, `x-job-attempt` header = `['2']` preserved through requeue | Task `isCompleted()` = true after requeue |
| TC-E11 | `JobRoutingListener` max attempts exhausted, with DLQ | Attempt=3, maxAttempts=3, DLQ configured | DLQ task dispatched first (with `x-job-class`, `x-job-serializer`, `x-job-dead-lettered: ['1']`, NO `x-job-attempt`/`x-job-max-attempts`); original task acked second | DLQ dispatch failure → original NOT acked (safe) |
| TC-E12 | `JobRoutingListener` max attempts exhausted, no DLQ | Attempt=3, maxAttempts=3, no DLQ | Task nacked with `redelivery: false` | STDERR log emitted |
| TC-E13 | `JobRoutingListener` handler calls `$task->requeue()` directly | Handler requeues before retry logic runs | Remaining handlers skipped, no retry logic applied | `isCompleted()` = true |
| TC-E14 | Handler with `ReceivedTaskInterface` second param | Handler typed `(Message $msg, ReceivedTaskInterface $task)` | Task passed as second argument | Handler without second param → called with message only |
| TC-E15 | `JobsDataCollector::recordDispatch()` | Dispatch 2 messages | Collector has 2 entries with class, queue, timing; `reset()` clears all data | Batch dispatch records as one entry with count |

### Integration tests

| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|
| IT-E01 | Batch dispatch round-trip | Register 3 messages, mock `QueueInterface` | `dispatchMany` called with 3 `PreparedTask`s; each has correct envelope headers and per-message Options | None |
| IT-E02 | Retry flow through listener | Build container with handler that fails twice then succeeds; feed 3 `JobsRunEvent`s with incrementing `x-job-attempt` | First two: task requeued via `requeue()` with computed delay and incremented `x-job-attempt`; third: handler succeeds, task acked by worker | None |
| IT-E03 | DLQ dispatch on exhaustion | Build container with handler that always fails; feed event with attempt=maxAttempts | DLQ queue receives the task; original acked | None |
| IT-E04 | Profiler data collection | Build container with `TraceableJobDispatcher` + `JobsProfilerSubscriber`; dispatch + consume via `JobsRunEvent` | Collector has dispatch and consume entries with timing; profile saved via `$profiler->saveProfile()` | None |
| IT-E05 | Console `jobs:list` | Mock `JobsInterface::getIterator()` yielding 2 `$name => QueueInterface` pairs | Command outputs table with pipeline names and paused/active status (no driver column) | None |
| IT-E06 | Console `jobs:pause` | Mock `JobsInterface::pause()` | Called with correct pipeline names | None |
| IT-E07 | Service wiring with profiler | Boot debug kernel (loads `config/debug.php`) | `JobDispatcher` service is decorated with `TraceableJobDispatcher`; `JobsDataCollector` + `JobsProfilerSubscriber` registered | None |
| IT-E08 | Handler `wantsTask` detection | Register handler with `ReceivedTaskInterface` 2nd param | `JobHandlerPass` sets 4th tuple element to `true`: `[serviceId, method, priority, true]` | Handler without 2nd param → `[..., false]`. Nullable/union types → `false` (exact match only). |

---

## Error Handling Matrix

### Dispatch errors

| Error type | Detection | Response | Logging |
|------------|-----------|----------|---------|
| Batch dispatch partial failure | `dispatchMany()` throws `JobsException` | Entire batch for that queue fails; exception propagates to caller | Caller handles |
| RPC unreachable (console commands) | `JobsException` from RPC call | Command exits with error code 1; exception message displayed | Symfony console |
| `autoAck` + `#[RetryPolicy]` conflict | `JobHandlerPass::process()` | `InvalidArgumentException` at compile time | Container build fails |
| DLQ pipeline not in `.rr.yaml` | `JobsException` when dispatching to DLQ | Exception propagates; original task NOT acked (still in source queue) | STDERR via worker |

### Consumer errors (retry)

| Error type | Detection | Response | Fallback | Logging |
|------------|-----------|----------|----------|---------|
| Handler throws, retries remaining | Exception in handler + attempt < max | `requeue($e)` with computed delay via `withDelay()` and incremented `x-job-attempt` header | Task requeued with headers preserved | STDERR: "Retrying {class} (attempt {n}/{max}, delay {d}s)" |
| Handler throws, max attempts exhausted + DLQ | Exception + attempt >= max + DLQ set | Dispatch to DLQ first, then `ack()` original (safe ordering) | Task in DLQ | STDERR: "Max attempts for {class}, moved to DLQ {queue}" |
| Handler throws, max attempts exhausted + no DLQ | Exception + attempt >= max + no DLQ | `nack(msg, redelivery: false)` — task dropped (⚠ irreversible) | Data loss (by design — configure DLQ to prevent) | STDERR: "Max attempts for {class}, no DLQ — task dropped" |
| `requeue()` fails during retry | `\Throwable` from `requeue()` (RPC/relay error) | Original exception re-thrown → worker `nack()` (retry state lost) | Task redelivered at attempt 1 (known degradation) | STDERR: "requeue() failed for {class}: {error}" |
| DLQ dispatch fails | `JobsException` from DLQ push | Original task NOT acked → stays in source queue (safe) | Task retried via source queue's native redelivery | STDERR: "Failed to dispatch to DLQ: {error}" |
| DLQ dispatch succeeds + worker dies before ack | Process death between DLQ push and `ack()` | Message in both DLQ and source queue (at-least-once) | DLQ consumers must be idempotent | N/A (process death) |

---

## References

| Topic | Location | Anchor |
|-------|----------|--------|
| Jobs message bus (wire contract, ADRs, dispatcher, routing) | [jobs-message-bus.md](jobs-message-bus.md#3-adrs) | §3 ADRs, §4 Design |
| Jobs worker (loop, ack/nack, shutdown) | [rr-jobs-worker.md](rr-jobs-worker.md#4-design) | §4 Design |
| Graceful error handling (shutdown rescue pattern) | [graceful-error-handling.md](graceful-error-handling.md) | Bucket B (mid-task death) |
| SDK `QueueInterface::dispatchMany()` | `vendor/spiral/roadrunner-jobs/src/Queue.php:105-108` | Batch dispatch via `Pipeline::sendMany` |
| SDK `Options` (`autoAck` field) | `vendor/spiral/roadrunner-jobs/src/Options.php:22` | `public bool $autoAck` |
| SDK `ReceivedTask::nack()` (no headers) | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php:101-108` | Response: `{message, requeue, delay_seconds}` |
| SDK `ReceivedTask::requeue()` (preserves headers) | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php:110-122` | Response: `{message, delay_seconds, headers}` |
| SDK `ReceivedTask::withDelay()` (immutable clone) | `vendor/spiral/roadrunner-jobs/src/Task/ReceivedTask.php:147-155` | `$self = clone $this` |
| SDK `WritableHeadersTrait::withHeader()` (immutable clone) | `vendor/spiral/roadrunner-jobs/src/Task/WritableHeadersTrait.php:47-58` | `$self = clone $this` |
| SDK `Jobs::getIterator()` (yields `name => QueueInterface`) | `vendor/spiral/roadrunner-jobs/src/Jobs.php:93-106` | `yield $queue => $this->connect($queue)` |
| SDK `QueueInterface` (no `getDriver()`) | `vendor/spiral/roadrunner-jobs/src/QueueInterface.php:14-81` | Full interface |
| Symfony `DataCollectorInterface` | [symfony.com/doc/current/profiler.html](https://symfony.com/doc/current/profiler.html) | Creating a custom data collector |
