# Jobs Message Bus over RoadRunner Jobs (Implementation)

**Source pinned to:** branch `feature/jobs-message-bus` off `fork-merge` @ `448bbc9`, 2026-06-02.
**Component group:** new `Job\*` (attributes, serializer, dispatcher, envelope, router listener) + a compiler pass + DI/Configuration wiring, layered **additively** on top of the existing `JobsWorker` / `JobsRunEvent` (docs/specs/rr-jobs-worker.md).
**Scope decision:** add a Messenger-like DX layer — dispatch a plain PHP object, have it serialized + pushed to an RR Jobs queue, then rehydrated on the consumer side and routed to a dedicated `#[AsJobHandler]` class — **without** replacing the raw `JobsRunEvent` / RR Jobs services, which remain fully usable.

This is **brownfield delta** work. The Jobs worker, the `JobsRunEvent`, the RR Jobs producer API, the RPC plumbing, the Centrifugo routing-pass pattern, and the Temporal compile-time scan all already exist and are recorded below from the code (file+line). The message bus is specified as a delta against them.

---

## 1. Reverse-engineered baseline (cited @ `448bbc9`)

| # | Fact about the existing system | Evidence |
|---|--------------------------------|----------|
| B1 | `spiral/roadrunner-jobs` is a **hard `require`** (`^4.7`), NOT optional. The Jobs consumer (`JobsWorker`) is wired inside a `class_exists(Consumer::class)` block that is always true in practice. | `composer.json:26`; `config/services.php` Jobs block |
| B2 | `JobsRunEvent` is dispatched once per consumed task; exposes `getTask(): ReceivedTaskInterface`, `getName()`, `getQueue()`, `getPipeline()`, `getId()`, `getPayload(): string`, `getHeaders(): array<non-empty-string, array<string>>`. A listener that throws → worker `nack(..., redelivery:true)`; a listener that calls `getTask()->ack()/nack()` is respected via `isCompleted()`. | `src/Event/Worker/Jobs/JobsRunEvent.php:20-82`; `src/Worker/JobsWorker.php:78-99` |
| B3 | Producer API: `Jobs` (final) ctor takes a single `RPCInterface` (`$rpc->withCodec(ProtobufCodec)`). `Jobs::connect(non-empty-string $queue): QueueInterface`. `JobsInterface extends \IteratorAggregate, \Countable`. | `vendor/spiral/roadrunner-jobs/src/Jobs.php:17-50`; `JobsInterface.php:15-23` |
| B4 | `QueueInterface::create(non-empty-string $name, string\|\Stringable $payload, ?OptionsInterface $options=null): PreparedTaskInterface`, then `dispatch(PreparedTaskInterface): QueuedTaskInterface`. | `vendor/spiral/roadrunner-jobs/src/Queue.php:72-103`; `QueueInterface.php:46-57` |
| B5 | `PreparedTask` (the concrete type `Queue::create()` returns) has immutable withers: `withDelay(int): self`, `withPriority(int): self`, `withHeader(non-empty-string, string\|iterable): self`. NONE of these are declared on `PreparedTaskInterface`; `withHeader` comes from `WritableHeadersInterface`, `withDelay/withPriority` are concrete on `PreparedTask` only. | `vendor/spiral/roadrunner-jobs/src/Task/PreparedTask.php:46-118`; `Task/PreparedTaskInterface.php`; `Task/WritableHeadersInterface.php` |
| B6 | Headers are `array<non-empty-string, array<string>>` (a **list of strings per header key**, never a bare string). Task `name` is `non-empty-string`. | `vendor/spiral/roadrunner-jobs/src/Task/ProvidesHeadersInterface.php`; `Task/TaskInterface.php` |
| B7 | The bundle wires `RPCInterface` from the RR environment via `RPCFactory::fromEnvironment(EnvironmentInterface)`; throws `InvalidRPCConfigurationException` if `RR_RPC` is unset. | `config/services.php` RPC block; `src/Factory/RPCFactory.php:12-25` |
| B8 | Centrifugo routing-pass model: `findTaggedServiceIds(tag)` → build `[key => [[serviceId, method, priority], …]]` + a `serviceMap` of `Reference`s → `ServiceLocatorTagPass::register($container, $serviceMap)` → `replaceArgument(0, $locatorRef)->replaceArgument(1, $table)` on the listener. Sorted priority-desc at compile time. | `src/DependencyInjection/Compiler/CentrifugoRouterPass.php:30-127` |
| B9 | The router listener takes `(ServiceLocator $locator, array $routingTable)`, invokes `($this->locator->get($serviceId))->$method($event)`; tagged `kernel.event_listener` at priority `-100` (after default listeners). | `src/EventListener/CentrifugoEventRouter.php:37-153`; `config/services.php` Centrifugo router tag |
| B10 | Attribute autoconfiguration: `registerAttributeForAutoconfiguration(AttrClass, fn(ChildDefinition, $attr, \Reflector))` adds a tag with the attribute fields; on a method target fills `method` / infers the type from the first parameter. The pass is added in the bundle (`addCompilerPass`, `TYPE_BEFORE_REMOVING`) guarded by `class_exists`. | `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php:66-103`; `src/FluffyDiscordRoadRunnerBundle.php:18-22` |
| B11 | PHP native `serialize()`/`unserialize()` round-trips arbitrary objects including private + nested state, with **zero** dependencies. Decode with `unserialize($payload, ['allowed_classes' => true])` (objects are trusted — the broker is the trust boundary, identical to the raw `getPayload()` path). | PHP core; §Verification log |
| B12 | Symfony Serializer fallback: `new Serializer([new PropertyNormalizer()], [new JsonEncoder()])`. `serialize($obj,'json')`→JSON; `deserialize($json,$classFqn,'json')`→object. **Verified** round-trip of private + nested. Needs the class FQN at decode (carried in `x-job-class`). | `vendor/symfony/serializer/Serializer.php`; §Verification log |
| B13 | Worker `lazy_boot` config flow: `Configuration.php` `jobs.lazy_boot` boolean → `Extension::load` `replaceArgument(0, $config["jobs"]["lazy_boot"])` when `hasDefinition(JobsWorker::class)`. `$config` PHPDoc already has `jobs: array{lazy_boot: bool}`. | `src/DependencyInjection/Configuration.php` jobs node; `FluffyDiscordRoadRunnerExtension.php:106,120-123` |

## 2. The 7 Questions (brownfield — settled answers recorded as-is)

1. **Exact problem:** Using RR Jobs today means hand-serializing a string payload at dispatch and branching on `JobsRunEvent::getName()`/`getPayload()` in a generic listener — no typed-message DX. Goal: let a developer (a) `dispatch($plainObject)` to a queue with no manual serialization, and (b) receive that same object rehydrated in a dedicated `#[AsJobHandler]` handler — Messenger-style — while the raw `JobsRunEvent` + RR Jobs services keep working unchanged.
2. **Success metrics:** (a) `JobDispatcher::dispatch($msg)` serializes, builds an RR `PreparedTask`, pushes it via the producer; (b) on consume, an enveloped task is rehydrated to the original class and routed to every matching handler; (c) a **non-enveloped** task (no `x-job-class` header) is left untouched so raw `JobsRunEvent` listeners still own it; (d) PHPStan level max → 0 errors; (e) `phpunit tests` → all green with **both** serializer paths exercised (Native by default; Symfony forced via an explicit serializer); existing tests still pass.
3. **Why it fits:** Reuses the existing `JobsRunEvent` seam (B2), the existing `RPCInterface` wiring (B7), the Centrifugo routing-pass + ServiceLocator pattern (B8/B9/B10), and the attribute-autoconfiguration idiom (B10). No new runtime entrypoint, no new RPC plumbing.
4. **Core architecture decision:** Producer = `JobDispatcher` over the RR `Jobs`/`Queue` producer (built from the existing `RPCInterface`). Serialization = a `JobSerializerInterface` chosen by the `jobs.serializer` config (Native `serialize()` default, optional Symfony Serializer), recording strategy + class FQN in RR task headers. Consumer = a compile-time `message-class → handler[]` map (built exactly like `CentrifugoRouterPass`) and a low-priority `JobsRunEvent` listener that detects the envelope, rehydrates, routes. See ADR-1..6.
5. **Tech-stack rationale:** the default `NativeJobSerializer` uses PHP `serialize()` — zero dependencies, works out of the box, handles any serializable object graph (incl. private props). `symfony/serializer` is an **optional** alternative for interoperable JSON payloads, kept `require-dev` + `suggest` (resolves OQ-1).
6. **MVP features:** `#[AsJob]` (queue/delay/priority defaults), `#[AsJobHandler]` (target message class), `JobSerializerInterface` + `NativeJobSerializer` (default) + `SymfonyJobSerializer` (optional, selected via `jobs.serializer`), `JobEnvelope` (payload + header contract), `JobDispatcher::dispatch(object, ?queue, ?delay, ?priority)`, `JobHandlerPass`, `JobRoutingListener`.
7. **NOT building (explicit exclusions):**
   - **No replacement of `JobsRunEvent` / raw RR Jobs services.** Additive only (metric c). Non-enveloped tasks are ignored by the router.
   - **No middleware stack / Messenger transport / `MessageBusInterface`.** Thin bus, not a Messenger re-implementation. Stamps, retry-with-backoff, failure transport, `HandledStamp` out of scope.
   - **No automatic queue *declaration*.** The pipeline must exist in `.rr.yaml`; `connect()` (B3) is used, not `create()` (which `jobs.Declare`s).
   - **No handler return-value handling.** Handlers are `void`; a thrown exception nacks (B2). No result bus.
   - **No multi-bus configuration.** One implicit bus.
   - **No live RR end-to-end test in the default suite.** `@group jobs-live`, skipped unless provisioned (mirrors the existing `JobsWorkerLiveTest`).

## 3. ADRs

- **ADR-1 — Serializer chosen by the `jobs.serializer` config, recorded per-envelope.** `config/services.php` aliases `JobSerializerInterface` → `NativeJobSerializer`; the Extension re-aliases it to `SymfonyJobSerializer` when `jobs.serializer: symfony`. Each serializer declares `name()` (`'native'`|`'symfony'`), written to the `x-job-serializer` header. The **consumer** decodes using the serializer named in the header, so a payload produced by one strategy decodes with the same strategy — as long as it is available (OQ-2). *Trade-off:* both ends must have the named strategy; recording the name (vs. assuming homogeneity) is the safe choice and costs one header.
- **ADR-2 — Transport string = the strategy's encoded form.** Native: `serialize($msg)`; decode `unserialize($payload, ['allowed_classes' => true])`. Symfony: the JSON from `Serializer::serialize($msg,'json')` IS the payload; decode `deserialize($payload,$class,'json')`. Both are plain `string` payloads accepted by `Queue::create()` (B4). *Trade-off:* Native uses PHP `serialize()` (not cross-language) — acceptable default since both ends are this PHP bundle; pick `jobs.serializer: symfony` for interoperable JSON.
- **ADR-3 — Envelope = payload string + two headers (`x-job-class`, `x-job-serializer`).** Class FQN + strategy travel as RR task headers (B6: single-element `list<string>`). Routing reads the header **without deserializing**. A task missing `x-job-class` is not ours → the router returns immediately, leaving it for raw listeners (metric c). *Irreversible-ish:* header names + payload encoding are a wire contract → **OQ-3**, flagged for sign-off.
- **ADR-4 — Job *name* = the message class FQN.** `Queue::create($name, …)` needs a `non-empty-string` name (B4/B6). The FQN makes RR-side logs readable and needs no registry. The routing key is the `x-job-class` header regardless.
- **ADR-5 — Consumer routing mirrors `CentrifugoRouterPass` exactly (B8/B9).** `JobHandlerPass` collects `fluffy_discord.job_handler` tags into `[messageClass => [[serviceId, method, priority], …]]`, registers a `ServiceLocator`, injects `(locator, table, …)` into `JobRoutingListener`, tagged `kernel.event_listener` on `JobsRunEvent` at priority **`-100`** (B9 parity).
- **ADR-6 — Default handler method `__invoke`; overridable via attribute `method`.** Mirrors `CentrifugoRouterPass::resolveMethod`. The pass validates `method_exists`. A message with **no** registered handler is a no-op (no throw, no ack/nack) — the worker acks it as success (B2).

---

## 4. Design

### 4.1 `Job\Attribute\AsJob` (new) — message-class metadata

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsJob {
    /**
     * @param non-empty-string|null $queue    RR queue/pipeline (must exist in .rr.yaml). Null → dispatcher default.
     * @param int<0, max>|null      $delay    Seconds to delay; null → none.
     * @param int<0, max>|null      $priority RR priority; null → queue default.
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly ?int $delay = null,
        public readonly ?int $priority = null,
    ) {}
}
```

Read at **runtime** by the dispatcher via reflection (the *message* class is not a service → no DI autoconfiguration). No tag, no pass.

### 4.2 `Job\Attribute\AsJobHandler` (new) — handler-service metadata

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsJobHandler {
    /**
     * @param class-string|null $message  Message class to handle. Optional on a method — inferred from the
     *                                     first parameter type (mirrors AsCentrifugoChannelListener).
     * @param int               $priority Higher = called first.
     * @param string|null       $method   Handler method; auto-detected on a method target; defaults to __invoke.
     */
    public function __construct(
        public readonly ?string $message = null,
        public readonly int $priority = 0,
        public readonly ?string $method = null,
    ) {}
}
```

Autoconfigured (B10) in `Extension::load` to add the `fluffy_discord.job_handler` tag with `message`/`priority`/`method`; on a method target fills `method` and infers `message` from the first parameter type when absent.

### 4.3 `Job\Serializer\*` (new)

```php
interface JobSerializerInterface {
    /** @return non-empty-string */                public function name(): string;
    public function serialize(object $message): string;
    /** @param class-string $class */              public function deserialize(string $payload, string $class): object;
}
```

- **`NativeJobSerializer`** (default) — `name()='native'`. `serialize`: `serialize($message)`. `deserialize`: `$obj = unserialize($payload, ['allowed_classes' => true])`; guard `is_object($obj) && $obj instanceof $class` else `JobSerializationException`. Zero dependencies. (B11)
- **`SymfonyJobSerializer`** (optional) — `name()='symfony'`. Wraps a nullable injected `Symfony\Component\Serializer\SerializerInterface`. `serialize`/`deserialize` throw `JobSerializationException` if the wrapped serializer is null; otherwise `serialize($message,'json')` / `deserialize($payload,$class,'json')` (guard `is_object`). (B12)
- **Strategy selection** — no factory. `config/services.php` aliases `JobSerializerInterface` → `NativeJobSerializer`; the Extension re-aliases it to `SymfonyJobSerializer` when `jobs.serializer: symfony` (§4.9). Dispatch-time selection is this alias; consume-time selection is by the `x-job-serializer` header (ADR-1).

`JobSerializationException extends \RuntimeException` under `Job\Exception\`.

### 4.4 `Job\JobEnvelope` (new) — wire contract (pure value object, no I/O)

```php
final class JobEnvelope {
    public const HEADER_CLASS = 'x-job-class';
    public const HEADER_SERIALIZER = 'x-job-serializer';

    /** @param class-string $messageClass @param non-empty-string $serializerName */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $serializerName,
        public readonly string $payload,
    ) {}

    /** @return array<non-empty-string, list<non-empty-string>> */
    public function toHeaders(): array {
        return [self::HEADER_CLASS => [$this->messageClass], self::HEADER_SERIALIZER => [$this->serializerName]];
    }

    /**
     * @param array<non-empty-string, array<string>> $headers
     * @return self|null  null when NOT a bundle envelope (no x-job-class) → leave for raw listeners.
     */
    public static function fromTask(string $payload, array $headers): ?self {
        $class = $headers[self::HEADER_CLASS][0] ?? null;
        $name  = $headers[self::HEADER_SERIALIZER][0] ?? null;
        if (!is_string($class) || $class === '' || !is_string($name) || $name === '') return null;
        if (!class_exists($class)) throw new JobSerializationException(sprintf('Job message class "%s" does not exist.', $class));
        return new self($class, $name, $payload);
    }
}
```

### 4.5 `Job\JobDispatcher` (new) — producer

```php
final class JobDispatcher {
    /** @param non-empty-string $defaultQueue */
    public function __construct(
        private readonly JobsInterface $jobs,             // Spiral\RoadRunner\Jobs\JobsInterface (B3)
        private readonly JobSerializerInterface $serializer,
        private readonly string $defaultQueue,
    ) {}

    /** @param int<0, max>|null $delay @param int<0, max>|null $priority */
    public function dispatch(object $message, ?string $queue = null, ?int $delay = null, ?int $priority = null): void {
        $attr = $this->readAsJob($message);                 // reflection (4.1)
        $queue ??= $attr?->queue ?? $this->defaultQueue;    // explicit > attribute > default
        if ($queue === '') throw new \InvalidArgumentException('Job queue name must not be empty.');
        $delay ??= $attr?->delay;
        $priority ??= $attr?->priority;

        $envelope = new JobEnvelope($message::class, $this->serializer->name(), $this->serializer->serialize($message));

        // Build a concrete PreparedTask directly (Gate-3 refinement): Queue::create() would merge
        // queue-default delay/priority — exactly what the dispatcher args are meant to OVERRIDE — and
        // would force us onto withers that are NOT on PreparedTaskInterface (B5/OQ-4). Constructing
        // PreparedTask(name, payload, Options, headers) sets everything in one shot, type-checks at
        // PHPStan max with no casts/@phpstan-ignore, and skips a wasted create() call.
        $options = new Options($delay ?? Options::DEFAULT_DELAY, $priority ?? Options::DEFAULT_PRIORITY);
        $task = new PreparedTask($message::class, $envelope->payload, $options, $envelope->toHeaders());

        $this->jobs->connect($queue)->dispatch($task);       // B4 (single connect call)
    }
}
```

`PreparedTask` (concrete) `implements PreparedTaskInterface`; its ctor `(non-empty-string $name, string|\Stringable $payload, ?OptionsInterface, array<non-empty-string, array<string>> $headers)` (B5) takes name + payload + options + headers directly — no withers, no casts. `$message::class` is `class-string` (non-empty); `Options::DEFAULT_DELAY`/`DEFAULT_PRIORITY` come from `OptionsInterface`.

`readAsJob(object): ?AsJob` = `(new \ReflectionClass($message))->getAttributes(AsJob::class)`, first `->newInstance()` or null.

### 4.6 `Job\EventListener\JobRoutingListener` (new) — consumer router

Mirrors `CentrifugoEventRouter` (B9). Ctor `(ServiceLocator $locator, array $routingTable, array $serializers)` where `$serializers` is `array<non-empty-string, JobSerializerInterface>` keyed by `name()`.

```php
public function onJobsRun(JobsRunEvent $event): void {
    $envelope = JobEnvelope::fromTask($event->getPayload(), $event->getHeaders());  // null → not ours
    if ($envelope === null) return;                          // raw listener owns it (metric c)

    $serializer = $this->serializers[$envelope->serializerName] ?? null;
    if ($serializer === null) {
        throw new JobSerializationException(sprintf(
            'No serializer "%s" available to decode job "%s".', $envelope->serializerName, $envelope->messageClass));
    }                                                        // → worker nacks/requeues (B2)

    $message = $serializer->deserialize($envelope->payload, $envelope->messageClass);
    foreach ($this->routingTable[$envelope->messageClass] ?? [] as [$serviceId, $method]) {
        ($this->locator->get($serviceId))->$method($message);
    }                                                        // no handler → no-op (ADR-6); worker acks
}
```

`@phpstan-type JobHandler array{0:string,1:string,2:int}` / `JobRoutingTable array<class-string, list<JobHandler>>` (mirrors `CentrifugoEventRouter`). Registered priority `-100` (B9).

### 4.7 `Job\DependencyInjection\Compiler\JobHandlerPass` (new) — compile-time map

Mirrors `CentrifugoRouterPass` (B8):
1. `if (!$container->hasDefinition(JobRoutingListener::class)) return;`
2. For each `findTaggedServiceIds('fluffy_discord.job_handler')`, each tag (skip non-array): read `message` (string|null), `priority` (numeric→int, default 0), `method` (string|null). Resolve `method` (`?? '__invoke'`, validate `method_exists`). Resolve `message`: if null, reflect the handler method's first parameter `\ReflectionNamedType` → its name; throw `InvalidArgumentException` if unresolvable (mirrors `resolveChannelEvent`). Validate `class_exists($message)` → else `InvalidArgumentException`.
3. `$serviceMap[$serviceId] = new Reference($serviceId)`; `$table[$message][] = [$serviceId, $method, $priority];`
4. `usort` each list priority-desc.
5. `$locatorRef = ServiceLocatorTagPass::register($container, $serviceMap);` → `replaceArgument(0, $locatorRef)->replaceArgument(1, $table)`.

Registered in the bundle `build()` (B10) at `TYPE_BEFORE_REMOVING`, unconditionally (Jobs is a hard require, B1).

### 4.8 DI wiring (new) — `config/services.php` (inside the existing `class_exists(Consumer::class)` block)

```php
$services->set(JobsInterface::class, Jobs::class)->args([ service(RPCInterface::class) ]);  // B3/B7

$services->set(NativeJobSerializer::class);
$services->set(SymfonyJobSerializer::class)->args([ service(SerializerInterface::class)->nullOnInvalid() ]);
$services->alias(JobSerializerInterface::class, NativeJobSerializer::class);  // Extension re-aliases to Symfony when jobs.serializer=symfony

$services->set(JobDispatcher::class)->public()->args([
    service(JobsInterface::class), service(JobSerializerInterface::class), 'default',   // defaultQueue (OQ-5)
]);

$services->set(JobRoutingListener::class)->args([
    abstract_arg('ServiceLocator — set by JobHandlerPass'),
    abstract_arg('routing table — set by JobHandlerPass'),
    [ 'native' => service(NativeJobSerializer::class), 'symfony' => service(SymfonyJobSerializer::class) ],
])->tag('kernel.event_listener', ['event' => JobsRunEvent::class, 'method' => 'onJobsRun', 'priority' => -100]);
```

Listing both serializers in the registry is harmless — the header decides which is used, and `SymfonyJobSerializer` throws cleanly if its wrapped serializer is null and it is selected.

**OQ-1 (resolved):** the default `NativeJobSerializer` needs **no** external dependency, so the bus works out of the box on a fresh install. `symfony/serializer` stays `require-dev` + `suggest`; selecting `jobs.serializer: symfony` without it installed yields a clear `JobSerializationException`.

### 4.9 Configuration (new) — `jobs.serializer` + `jobs.default_queue`

Add an `enumNode("serializer")->values(["native","symfony"])->defaultValue("native")` and a `scalarNode("default_queue")->defaultValue("default")` under the `jobs` node. Extension: when `hasAlias(JobSerializerInterface::class)`, `setAlias(JobSerializerInterface::class, $config["jobs"]["serializer"] === 'symfony' ? SymfonyJobSerializer::class : NativeJobSerializer::class)`; and `replaceArgument(2, $config["jobs"]["default_queue"])` on `JobDispatcher` when `hasDefinition`. `$config` `jobs` PHPDoc gains `serializer: 'native'|'symfony', default_queue: string`. (OQ-5)

---

## Assumptions

| # | Assumption | If wrong, then… |
|---|------------|-----------------|
| A-1 | PHP `serialize()`/`unserialize()` is lossless for app messages (no closures/resources). | A message with a closure throws at `serialize()` (dispatch-time, surfaced immediately). Document "messages must be serializable". |
| A-2 | A consumer worker has the same strategy available as the producer used (header-named); a single deployment runs the same config. | Mixed deployment (one side `native`, other configured `symfony`) → named strategy missing on decode → `JobSerializationException` → nack/requeue. Documented (OQ-2). |
| A-3 | `PreparedTask`'s ctor accepts `(name, payload, Options, headers)` directly (B5), letting the dispatcher avoid the non-interface withers. | If the ctor shape differs, fall back to building from `$message::class`/`$envelope->payload` (both in scope). Verified at code time. |
| A-4 | Inferring the message class from a handler method's first param type when `message` omitted is acceptable (mirrors `AsCentrifugoChannelListener`, B10). | A union/no-type first param → the pass throws a clear `InvalidArgumentException` at compile time (fail-fast). |

## Open Questions

| # | Question | Why it matters | Blocks | Status |
|---|----------|----------------|--------|--------|
| OQ-1 | **Dependency strategy:** does the bus work out of the box without an extra `composer require`? | Fresh-install usability. | Production behavior, not tests. | **RESOLVED.** The default `NativeJobSerializer` is zero-dep, so a fresh install dispatches immediately. `symfony/serializer` is an optional `require-dev`+`suggest` alternative (`jobs.serializer: symfony`), matching the centrifugo/temporal optional-dep convention. |
| OQ-2 | Cross-worker serializer mismatch (producer `native`, consumer configured `symfony`). | Decode fails. | Nothing (deliberate fail-loud). | **Resolved (ADR-1/A-2): record strategy in `x-job-serializer`; decode with the named one; throw+nack if unavailable.** Documented deployment requirement. |
| OQ-3 | **Wire contract (irreversible-ish):** header names `x-job-class`/`x-job-serializer`, FQN-as-job-name, payload encoding. | Changing them later breaks in-flight queued tasks across an upgrade. | Public API. | **ESCALATED for sign-off.** Alternatives weighed: (a) JSON envelope-in-payload `{class,serializer,data}` — rejected: forces deserialize-to-route, brief wants header routing; (b) single packed `x-job` header — rejected: less readable, no gain. |
| OQ-4 | `withDelay`/`withPriority`/`withHeader` are NOT on `PreparedTaskInterface` (B5) — how to set them at PHPStan max without `@phpstan-ignore`? | 0-error gate, no silencing. | 0-error gate. | **Resolved (4.5): construct a concrete `PreparedTask(name, payload, new Options(delay,priority), headers)` directly** — no withers on the interface, no casts. |
| OQ-5 | Default queue name when neither arg nor `#[AsJob(queue:)]` given. | Dispatch must target some pipeline. | Dispatch usability. | **Resolved: `"default"`, overridable via `jobs.default_queue` (§4.9).** A misconfigured queue surfaces as an RR `JobsException` at dispatch. |

*No user-blocking unknown forces a stop. OQ-1 and OQ-3 are flagged for maintainer sign-off (irreversibility list: public API + dependency shape) with a chosen, reversible default so this PR is reviewable; called out in the final report.*

---

## N-3. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Deserialize the payload before deciding whether a task is ours | Read `x-job-class` from the **headers** first; `null` → return | Header routing is the requirement; deserializing a non-envelope could throw on foreign payloads and steal raw-listener tasks |
| Ack/nack inside `JobRoutingListener` | Let the handler run; throw to nack, return to let the worker ack (B2) | The worker owns the single ack/nack frame (rr-jobs-worker.md Invariant I-2) |
| Hard-`require` `symfony/serializer` silently | `require-dev`+`suggest`; the default Native serializer needs no deps (OQ-1) | Native works out of the box; selecting `symfony` without it throws a clear error |
| Assume the consumer's locally-selected serializer matches the payload | Decode with the strategy named in `x-job-serializer` (ADR-1) | A homogeneity assumption breaks on mixed deployments and silently corrupts |
| Store a header value as a bare string | Store `[$value]` (list per key, B6) | RR headers are `array<non-empty-string, array<string>>` |
| Call the `PreparedTask` withers through `PreparedTaskInterface` | Construct a concrete `PreparedTask(...)` with `Options`+headers (4.5) | The withers are not on the interface; casting/narrowing to quiet PHPStan is forbidden |
| Use `Queue::create()`/`Jobs::create()` to declare a pipeline at dispatch | `Jobs::connect($queue)` only (B3) | `create()` issues `jobs.Declare`; declaring is out of scope |
| `serialize()` a message with closures/resources | Document "messages must be plain serializable objects" (A-1) | `serialize()` throws on closures; fail-fast at dispatch is correct |
| Throw when a message has no registered handler | No-op (ADR-6); worker acks | An unhandled-but-valid envelope should not nack-loop |

## N-2. Test Case Specifications

New tests under `tests/Job/`. Listener tests reuse the `SpyReceivedTask` style (`tests/Worker/AbstractJobsWorkerTestCase.php`). The compiler-pass test mirrors `CentrifugoRouterPassTest`. Fixtures (message + handler classes) under `tests/Job/Fixtures/`.

### Unit tests
| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-01 | `NativeJobSerializer` round-trip | object w/ nested object + private prop | `deserialize(serialize($m))` restores class + private + nested state | corrupt payload → `JobSerializationException`; wrong class → `JobSerializationException` |
| TC-02 | `SymfonyJobSerializer` round-trip | same object via real `Serializer`+`PropertyNormalizer`+`JsonEncoder` | round-trips; class + private props restored | null wrapped serializer → `JobSerializationException` on serialize/deserialize |
| TC-03 | serializer `name()` identifiers | `NativeJobSerializer`, `SymfonyJobSerializer` | `'native'` / `'symfony'` (the values written to `x-job-serializer`) | `SymfonyJobSerializer(null)` → `JobSerializationException` on serialize |
| TC-04 | `JobEnvelope` header contract | envelope(class,'native',payload) | `toHeaders()` == `['x-job-class'=>[class],'x-job-serializer'=>['native']]`; `fromTask` round-trips | missing `x-job-class` → `null`; unknown class → `JobSerializationException`; header value is a list |
| TC-05 | `JobDispatcher` queue resolution | `#[AsJob(queue:'a')]` msg; dispatch no-queue / `queue:'b'` | uses `'a'`; explicit `'b'` overrides; no attr + no arg → default queue | empty-string explicit queue → `\InvalidArgumentException` |
| TC-06 | `JobDispatcher` delay/priority resolution | `#[AsJob(delay:5,priority:2)]`; override `delay:0` | attr defaults applied; explicit `0` overrides (not coalesced away); both null + no attr → defaults | — |
| TC-07 | `JobDispatcher` task push | mock `JobsInterface`+`QueueInterface` (interfaces, mockable) | `connect(queue)`; `create(FQN, payload)`; `dispatch($task)` with headers carrying serializer name + class | payload decodes back to an equal object |
| TC-08 | `JobHandlerPass` map build | tagged handler (class+method+priority) | `routingTable[messageClass]` == `[[serviceId,method,priority]]`; locator registered | message inferred from method param when omitted; missing+un-inferrable → `InvalidArgumentException`; bad method → `InvalidArgumentException` |
| TC-09 | `JobHandlerPass` early exit | container w/o `JobRoutingListener` | no-op, no throw | — |
| TC-10 | `JobHandlerPass` priority sort | two handlers, prio 5 and 10 | sorted `[10,5]` | equal priorities preserve insertion order |

### Integration tests
| Test ID | Flow | Setup | Verification | Type |
|---------|------|-------|--------------|------|
| IT-01 | enveloped task → handler | `JobRoutingListener` + real handler spy + Native serializer; `SpyReceivedTask` whose payload/headers come from a real `JobEnvelope` | handler invoked once with a rehydrated message `==` the original; listener does not ack/nack | real double |
| IT-02 | non-enveloped task ignored | `SpyReceivedTask` with no `x-job-class` header | listener returns; handler never invoked; no exception | real double |
| IT-03 | round-trip dispatcher↔listener | capture the dispatcher-built task payload+headers, feed into `JobRoutingListener` | handler receives an object equal to the dispatched one | parametrized over **both** serializer strategies |
| IT-04 | service wiring | boot a container with the Jobs `services.php` block + a tagged handler; run `JobHandlerPass` | `JobDispatcher` public & instantiable; `JobRoutingListener` table non-empty | real container |
| IT-LIVE | real RR jobs pool | provisioned `rr`+`.rr.yaml` `memory` pipeline + bundle `MODE_JOBS` worker; dispatch via `JobDispatcher`, consume | handler runs and the task is acked | **`@group jobs-live`, skipped** unless `RR_JOBS_LIVE=1`+`rr` present |

*Floors: ≥5 unit (10) and ≥3 integration (5) — met. Both serializer paths exercised: TC-01/IT-03 (native, the default) + TC-02/IT-03 (symfony, forced).*

**Live test environment (IT-LIVE):** `rr` on PATH; `.rr.yaml` with `rpc:` + `jobs: { pipelines: { default: { driver: memory } }, consume: [default] }`; a worker command running the bundle in `MODE_JOBS`; `RR_RPC`/`RR_JOBS_LIVE=1`. `markTestSkipped()` otherwise. Mirrors `tests/Worker/JobsWorkerLiveTest.php`.

## N-1. Error Handling Matrix

### Internal failures
| Error type | Detection | Response | Logging | Worker action |
|------------|-----------|----------|---------|---------------|
| Message not serializable (closure/resource) | `serialize()` throws at dispatch | propagate `\Throwable` | caller's | dispatch fails synchronously |
| `jobs.serializer: symfony` but `symfony/serializer` absent | `SymfonyJobSerializer` (null wrapped) | `JobSerializationException` w/ remediation | caller's | dispatch fails synchronously |
| Enveloped task, named serializer unavailable (consume) | `$serializers[name] ?? null` null | `JobSerializationException` | worker STDERR/Sentry (B2) | `nack(..,redelivery:true)` |
| Enveloped task, class no longer exists | `JobEnvelope::fromTask` `!class_exists` | `JobSerializationException` | worker STDERR/Sentry | nack/requeue |
| Deserialize fails (corrupt payload) | serializer throws / type guard | `JobSerializationException` | worker STDERR/Sentry | nack/requeue |
| Handler throws (or unresolvable from locator) | try-catch around `locator->get()->$method()` | wrapped in `JobHandlerException` (original as `previous`) | worker STDERR/Sentry (B2) | nack/requeue |
| No handler for a valid envelope | `routingTable[class] ?? []` empty | no-op | none | worker acks |
| Compile-time: bad method / un-inferrable message | `JobHandlerPass` | `InvalidArgumentException` (build fails) | build error | n/a |

### Producer/consumer outcomes
| Outcome | Effect on the job |
|---------|-------------------|
| dispatch success | task pushed with `x-job-class`/`x-job-serializer` headers |
| handler success | worker `ack()`s |
| handler/deserialize failure | worker `nack(..,redelivery:true)` (poison-message caveat per rr-jobs-worker.md ADR-3) |
| non-enveloped task | router ignores; raw `JobsRunEvent` listeners handle it (additive guarantee) |

## N. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Jobs worker + ack/nack semantics | [`docs/specs/rr-jobs-worker.md`](rr-jobs-worker.md) | §4.2, ADR-3 |
| Event to listen on | [`src/Event/Worker/Jobs/JobsRunEvent.php`](../../src/Event/Worker/Jobs/JobsRunEvent.php) | `getPayload`/`getHeaders` `:71-82` |
| Routing-pass to mirror | [`src/DependencyInjection/Compiler/CentrifugoRouterPass.php`](../../src/DependencyInjection/Compiler/CentrifugoRouterPass.php) | `process()` `:30-127` |
| Router-listener to mirror | [`src/EventListener/CentrifugoEventRouter.php`](../../src/EventListener/CentrifugoEventRouter.php) | `invoke()` `:145-153` |
| Attribute autoconfiguration | [`src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php`](../../src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php) | `load()` `:66-103` |
| Compiler-pass registration | [`src/FluffyDiscordRoadRunnerBundle.php`](../../src/FluffyDiscordRoadRunnerBundle.php) | `build()` `:18-22` |
| Producer API | `vendor/spiral/roadrunner-jobs/src/Jobs.php`, `Queue.php`, `Task/PreparedTask.php` | `connect`/`create`/`dispatch` |
| RPC wiring to reuse | [`config/services.php`](../../config/services.php) | `RPCInterface` block; Jobs block |
| Config node to extend | [`src/DependencyInjection/Configuration.php`](../../src/DependencyInjection/Configuration.php) | `jobs` node |
| Test harness (SpyReceivedTask) | [`tests/Worker/AbstractJobsWorkerTestCase.php`](../../tests/Worker/AbstractJobsWorkerTestCase.php) | `SpyReceivedTask` |
| Compiler-pass test to mirror | [`tests/DependencyInjection/Compiler/CentrifugoRouterPassTest.php`](../../tests/DependencyInjection/Compiler/CentrifugoRouterPassTest.php) | — |

### Verification log (B11/B12)
- PHP native `unserialize(serialize($m), ['allowed_classes' => true]) == $m` for `SendWelcomeEmail{public string, private int, array, ?Address{public string, private string}}` — class + private + nested props restored (`tests/Job/SerializerTest.php`).
- `Serializer([PropertyNormalizer],[JsonEncoder])`: `deserialize(serialize($m,'json'), Msg::class, 'json')` restores public + private + nested — verified.
