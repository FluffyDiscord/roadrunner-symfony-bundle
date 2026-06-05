# Jobs Message Bus over RoadRunner Jobs — via Symfony Messenger (Implementation)

**Source pinned to:** branch `additional-features` @ `c600d58`, 2026-06-03. `symfony/messenger` verified at `v8.0.12` (vendored `--dev`).
**Component group:** the producer-side `Job\*` *classes* (`#[AsJob]`, serializers, `JobDispatcher`, `JobEnvelope`) and the on-wire format are **unchanged**; only their *DI registration* moves under a Messenger guard (§4.4). The consumer side is **re-pointed** from a custom handler map onto Symfony Messenger's `MessageBusInterface`. Layered **additively** on top of the existing `JobsWorker` / `JobsRunEvent` (docs/specs/rr-jobs-worker.md).
**Scope decision (Option B):** keep `JobsWorker` owning the RR consume loop + ack/nack; on consume, rehydrate the enveloped message and **dispatch it into `MessageBusInterface`** so handlers are plain `#[AsMessageHandler]` classes (Symfony routing + middleware + profiler reused). The raw `JobsRunEvent` and RR Jobs services keep working unchanged. This **replaces** the previous custom routing (`#[AsJobHandler]` + `JobHandlerPass` + a compile-time handler table).

This is **brownfield delta** work against an *unreleased* custom bus (the entire Jobs message bus lives only on the unmerged `additional-features` branch — `#[AsJobHandler]` is absent from every tagged release incl. `v5.0.0`, so its removal needs **no deprecation path**). The previous approach (custom routing mirroring `CentrifugoRouterPass`) is removed; the removed pieces are enumerated in §5.

---

## 1. Reverse-engineered baseline (cited @ `c600d58`)

### 1a. Existing bundle facts

| # | Fact about the existing system | Evidence | Changed by Option B? |
|---|--------------------------------|----------|----------------------|
| B1 | `spiral/roadrunner-jobs` is a **hard `require`** (`^4.7`). The whole Jobs block in `config/jobs.php` is guarded by `class_exists(Consumer::class)`, imported from `config/services.php`. | `composer.json:26`; `config/services.php:85`; `config/jobs.php:28-30` | no |
| B2 | `JobsRunEvent` is dispatched once per consumed task; exposes `getTask(): ReceivedTaskInterface`, `getName/getQueue/getPipeline/getId`, `getPayload(): string`, `getHeaders(): array<non-empty-string, array<string>>`. A listener that throws → worker `nack(..., redelivery:true)`; a listener that itself calls `getTask()->ack()/nack()/requeue()` is respected via `isCompleted()`. | `src/Event/Worker/Jobs/JobsRunEvent.php`; `src/Worker/JobsWorker.php:72-99` | no |
| B3 | `JobsWorker::start()` processes **one task per `while` iteration, sequentially** (no in-process concurrency): `while ($task = $this->waitTask()) { … dispatch JobsRunEvent … }`. | `src/Worker/JobsWorker.php:59-129` | no |
| B4 | `JobDispatcher` (producer) reads `#[AsJob]` via reflection, builds a `JobEnvelope`, constructs `PreparedTask($fqn, $payload, new Options($delay,$priority), $headers)` and pushes via `$jobs->connect($queue)->dispatch($task)`. Class unchanged; only its DI registration moves (§4.4). | `src/Job/JobDispatcher.php:31-70` | DI only |
| B5 | `JobEnvelope` wire contract: payload string + RR headers `x-job-class` (FQN) + `x-job-serializer` (strategy `name()`); `fromTask()` returns `null` when `x-job-class` absent. **Class + wire format unchanged.** | `src/Job/JobEnvelope.php:14-63` | no |
| B6 | Serializers: `JobSerializerInterface { name(); serialize(object): string; deserialize(string,class-string): object }` with `NativeJobSerializer` ('native', default), `IgbinaryJobSerializer` ('igbinary'), `SymfonyJobSerializer` ('symfony', wraps a nullable `Symfony\…\SerializerInterface`). Classes unchanged; DI registration moves (§4.4). | `src/Job/Serializer/*`; `config/jobs.php:78-87` | DI only |
| B7 | The **current** `JobRoutingListener` ctor is `(ServiceLocator $locator, array $routingTable, array $serializers)`; deserializes then invokes `$locator->get($id)->$method($message)` per routing entry, wrapping handler throwables in `JobHandlerException`. Tagged `kernel.event_listener` on `JobsRunEvent::onJobsRun` @ `-100`. **Rewritten by Option B.** | `src/Job/EventListener/JobRoutingListener.php:31-71`; `config/jobs.php:99-111` | **yes (rewrite)** |
| B8 | The **current** consumer routing is built by `JobHandlerPass` (modeled on `CentrifugoRouterPass`) from `fluffy_discord.job_handler` tags; registered in the bundle `build()` at `TYPE_BEFORE_REMOVING`. `#[AsJobHandler]` is autoconfigured to that tag in `Extension::load:123-144`. | `src/Job/DependencyInjection/Compiler/JobHandlerPass.php`; `src/FluffyDiscordRoadRunnerBundle.php:26-28`; `…Extension.php:123-144` | **deleted** |
| B9 | `JobsInterface::class` is registered **unconditionally** inside the `class_exists(Consumer::class)` block (a pure RR-Jobs RPC client; **no** Messenger dependency). `JobDispatcher` depends on it. | `config/jobs.php:71-76` | **stays ungated** |
| B10 | `jobs` config node: `lazy_boot` (bool, default false), `serializer` (enum `igbinary\|native\|symfony`, default null), `default_queue` (scalar, cannotBeEmpty, default `"default"`). The Extension `$config` PHPDoc shape at `:147` is a **sealed** `array{lazy_boot: bool, serializer: …\|null, default_queue: non-empty-string}` (reading an undeclared offset fails PHPStan max). | `src/DependencyInjection/Configuration.php:139-179`; `…Extension.php:147,161-181` | one node added (§4.6) |

### 1b. Symfony Messenger facts (the dependency this adds — verified @ `v8.0.12`)

| # | Fact about Symfony Messenger | Evidence |
|---|------------------------------|----------|
| M1 | `MessageBusInterface::dispatch(object $message, array $stamps = []): Envelope`. The **routing/handling half** (`MessageBusInterface` → `SendMessageMiddleware` → `HandleMessageMiddleware`) has **no consume loop and no receiver** — a synchronous in-process dispatch. | `vendor/symfony/messenger/MessageBusInterface.php:30` |
| M2 | `HandleMessageMiddleware`: with no handler and `allowNoHandlers === false` (default), it **throws `NoHandlerForMessageException` directly** (not wrapped); if a handler throws, exceptions are collected and raised as `HandlerFailedException` *after* the no-handler check. | `…/Middleware/HandleMessageMiddleware.php:112-122`; `Exception/NoHandlerForMessageException.php:17` (`extends LogicException`); `Exception/HandlerFailedException.php:16` (`extends RuntimeException`) |
| M3 | `SendMessageMiddleware`: when the envelope carries **any `ReceivedStamp`**, it does **not** send to a transport — it falls through to handling (`$stack->next()`). Dispatching with a `ReceivedStamp` therefore guarantees *handle-here, never re-send*, even if the class is also routed to a transport (incl. `sync`). | `…/Middleware/SendMessageMiddleware.php:47-79` |
| M4 | `ReceivedStamp` is `final … implements NonSendableStampInterface`; ctor `(string $transportName)`, `getTransportName(): string`. | `…/Stamp/ReceivedStamp.php:26-36` |
| M5 | **`HandlersLocator::shouldHandle()`**: if a `ReceivedStamp` is present **and** the handler declares a `from_transport` option, the handler is invoked **only if** `ReceivedStamp::getTransportName() === from_transport`. A handler with **no** `from_transport` always matches. | `…/Handler/HandlersLocator.php:85-96`; `…/Attribute/AsMessageHandler.php:31` (`fromTransport`) |
| M6 | `HandleMessageMiddleware::callHandler()` calls `$handler($message, ...$additional)` where `$additional` comes from a `HandlerArgumentsStamp`. PHP ignores extra positional args a handler does not declare, so passing extra args is safe for handlers that only declare `(Message $m)`. `HandlerArgumentsStamp` is `final … implements NonSendableStampInterface` (public, not `@internal`), ctor `(array $additionalArguments)`. | `…/Middleware/HandleMessageMiddleware.php:90,138-149`; `…/Stamp/HandlerArgumentsStamp.php:17-23` |
| M7 | A handler receives only the **message object** (plus any `HandlerArgumentsStamp` extras, M6) — it cannot read the `Envelope`/stamps directly. `#[AsMessageHandler]` supports `bus:`, `method:`, `priority:`, `handles:`, `fromTransport:`. | `…/Attribute/AsMessageHandler.php:20-47` |
| M8 | `MessageBusInterface` autowires to the application's **default** bus (`messenger.default_bus`); apps may define multiple named buses. The default bus's middleware stack (validation, doctrine_transaction, custom) is application-controlled. | Symfony Messenger config (`framework.messenger`) |

---

## 2. The 7 Questions (brownfield — answers recorded as a delta)

1. **Exact problem:** the unreleased custom bus (`#[AsJobHandler]` + `JobHandlerPass` + a hand-rolled table) **duplicates Symfony Messenger's routing/handling half**, forces a parallel vocabulary, and `docs/specs/jobs-enhancements.md` would further re-implement Messenger's retry/DLQ/profiler by hand in a weaker form. Goal: a Symfony developer dispatches a typed message and handles it with the `#[AsMessageHandler]` they already know, **while RoadRunner keeps owning the loop, ack/nack, headers, delay/priority, redelivery and driver features** ("max RR features + free hand").
2. **Success metrics:** (a) `JobDispatcher::dispatch($msg)` behaviour + wire format are unchanged; (b) on consume, an enveloped task is rehydrated and dispatched into `MessageBusInterface`, invoking the matching `#[AsMessageHandler]`; (c) a **non-enveloped** task is still left untouched for raw `JobsRunEvent` listeners (additive guarantee); (d) a handler can reach the raw `ReceivedTaskInterface` (for `requeue()`-with-headers / redelivery control) by declaring it as a second parameter, without abandoning the `#[AsMessageHandler]` DX; (e) a valid envelope with **no** handler does not nack-loop; (f) the typed bus is absent (compile-time) when `symfony/messenger` is not installed, leaving the zero-dep raw `JobsRunEvent` path working; (g) PHPStan level max → 0 errors (no `@phpstan-ignore`/baseline/inline `@var`); (h) `phpunit tests` → all green; the raw-worker tests are untouched.
3. **Why it fits:** reuses the `JobsRunEvent` seam (B2), the unchanged producer (B4) + envelope (B5) + serializers (B6), and the bundle's dominant **soft-dependency** idiom (`class_exists`/`interface_exists`: Centrifugo, Temporal, locks, `symfony/serializer`). The prior specs' loop-ownership objection does **not** apply: Messenger's *router* (M1) has no loop; `JobsWorker` keeps the loop + ack/nack.
4. **Core architecture decision:** Consumer = a low-priority `JobsRunEvent` listener that detects the envelope (B5), picks the serializer named in `x-job-serializer` (B6), rehydrates, and `$bus->dispatch($message, [new ReceivedStamp(self::TRANSPORT_NAME), new HandlerArgumentsStamp([$task])])` (M1/M3/M6). Handlers are `#[AsMessageHandler]` (M7). See ADR-B1..B7.
5. **Tech-stack rationale:** `symfony/messenger` is an **optional** soft dependency (`require-dev` + `suggest`), gated by `interface_exists(MessageBusInterface::class)`. The zero-dependency raw `JobsRunEvent` path remains (it carries a raw payload string the user (de)serialises themselves — it does **not** use the bundle serializers), preserving the "thin runtime" identity.
6. **MVP features:** keep `#[AsJob]`, `JobDispatcher`, `JobEnvelope`, the three serializers + `JobSerializationException` (classes unchanged). Rewrite `JobRoutingListener` to dispatch into `MessageBusInterface`, passing the raw task via `HandlerArgumentsStamp`. Add an optional `jobs.bus` config. Delete `JobHandlerPass`, `#[AsJobHandler]`, `JobHandlerException`, the `AsJobHandler` autoconfiguration + compiler-pass registration.
7. **NOT building (explicit exclusions):**
   - **No Messenger *transport* (`TransportInterface`/`ReceiverInterface`) and no `messenger:consume`.** `JobsWorker` is the consumer; we use only Messenger's router (M1) — the choice that keeps RR owning the loop/ack/nack/headers (Option C was rejected for trading those away).
   - **No retry / dead-letter / backoff in this layer.** Those live in Messenger's `Worker` (not run here). Retry is available to handlers via RR-native `requeue()` + delay + the raw task (metric d). `docs/specs/jobs-enhancements.md` §4.4 (retry/DLQ) and §4.6 (profiler) are **not** to be built as specified there.
   - **No replacement of `JobsRunEvent` / raw RR Jobs services** (additive only, metric c). **`JobsInterface` stays ungated** (B9).
   - **No change to the wire contract** (`x-job-class` / `x-job-serializer` / FQN-as-name) — in-flight tasks and existing producers are unaffected.
   - **No change to `#[AsJob]`, `JobDispatcher`, or the serializer classes.**
   - **No live RR end-to-end test in the default suite** (`@group jobs-live`, skipped unless provisioned).

## 3. ADRs

- **ADR-B1 — Consumer dispatches into `MessageBusInterface`; handlers are `#[AsMessageHandler]`.** `JobRoutingListener` calls `$this->bus->dispatch($message, $stamps)` (M1) instead of a custom table; Symfony's `HandlersLocator` + middleware route. *Trade-off:* a soft `symfony/messenger` dependency (gated, ADR-B6) in exchange for deleting `JobHandlerPass` + `#[AsJobHandler]` + `JobHandlerException` and reusing Symfony's routing/middleware/profiler. Handlers become portable (the same `#[AsMessageHandler]` works sync, in tests, and over RR Jobs).
- **ADR-B2 — Dispatch carries a `ReceivedStamp` to force local handling.** The listener adds `new ReceivedStamp(self::TRANSPORT_NAME)` so `SendMessageMiddleware` never re-sends to a transport, even if the user also routed the class (incl. `sync`) in `messenger.yaml` (M3). *Consequence (not merely informational):* per M5, a handler that declares `#[AsMessageHandler(fromTransport: X)]` is invoked **only if** `X === self::TRANSPORT_NAME` (`'roadrunner'`). Handlers with **no** `fromTransport` (the overwhelming default) always match. This is a deliberate, documented capability: `fromTransport: 'roadrunner'` scopes a handler to RR-consumed jobs; any *other* `fromTransport` value will not match an RR job (→ no-handler → ADR-B3 ack-drop). *Trade-off:* the transport name is a fixed public constant (`JobRoutingListener::TRANSPORT_NAME`) rather than a config knob — chosen to avoid extra config surface; users who scope handlers reference the constant's value. Without `ReceivedStamp` a message the user happened to route async would be silently re-enqueued — that is the larger footgun this guards.
- **ADR-B3 — A valid envelope with no matching handler is a logged no-op (ack), not a nack-loop.** On the **standard Symfony bus stack**, `HandleMessageMiddleware` throws `NoHandlerForMessageException` directly when nothing matches (M2). The listener **catches that one type**, logs a warning via an optional `LoggerInterface`, and returns so the worker acks. Preserves the prior additive semantics (an unhandled-but-valid envelope must not poison-requeue) while being louder than silent. *Limitation:* this guarantee holds for the default middleware stack; an app that strips/reorders `HandleMessageMiddleware` or sets `allow_no_handlers: true` changes it (see Assumptions A-3 + the anti-pattern row). *Trade-off:* a genuinely-missing handler is acked-and-dropped (with a warning) rather than failing loud; chosen because rr-jobs-worker.md ADR-3 treats requeue-by-default as dangerous for non-retryable conditions, and a missing handler is not retryable. Reversible later via a config flag (OQ-B1).
- **ADR-B4 — Handler failures surface as `HandlerFailedException` and propagate; the worker nacks/requeues (unchanged outcome).** Any exception other than `NoHandlerForMessageException` propagates out of `dispatch()` (M2) → `JobsWorker` nacks with redelivery (B2). The previous `JobHandlerException` wrapper is removed; `HandlerFailedException` (wrapping the original) replaces it. *Trade-off:* the logged exception type changes; the ack/nack outcome + poison-message caveat (rr-jobs-worker.md ADR-3) are identical.
- **ADR-B5 — The raw `ReceivedTaskInterface` reaches handlers via `HandlerArgumentsStamp`, not a holder service.** The listener dispatches with `new HandlerArgumentsStamp([$event->getTask()])`; Messenger then calls each handler as `$handler($message, $task)` (M6). A handler that wants RR power declares `__invoke(MyMessage $m, ReceivedTaskInterface $task)`; handlers that don't simply omit the param (PHP ignores the extra arg, M6). The worker respects any handler-owned `ack()/nack()/requeue()` via `isCompleted()` (B2). *Trade-off:* the task is a positional second argument rather than an inject-anywhere service. Against that, this is **stateless and per-dispatch by construction** — no holder service, no `kernel.reset`, no `finally`-clear, no single-task-per-process assumption, and no nested-dispatch slot-corruption path (the failure modes a mutable `JobContext` holder would have introduced). This is the concrete embodiment of metric (d).
- **ADR-B6 — The typed bus is gated on `interface_exists(MessageBusInterface::class)`.** When `symfony/messenger` is absent, `JobDispatcher`, the three serializers + the `JobSerializerInterface` alias, and `JobRoutingListener` are **not** registered; only the raw `JobsRunEvent` + `JobsWorker` + the ungated `JobsInterface` (B9) remain. *Rationale:* the typed bus is meaningless without `#[AsMessageHandler]` handlers, so the producer half ships with it as one unit ("typed bus = Messenger"). The zero-dep story is carried by the raw event, whose payload the user (de)serialises in their own format (it never used the bundle serializers). Matches the bundle's optional-dependency convention. *Note:* `JobsInterface` is **not** in this gated set (B9) — it is a raw RR service a user may inject independently.
- **ADR-B7 — Which bus is configurable via `jobs.bus` (default: the autowired default bus).** `jobs.bus` (nullable scalar service id) lets multi-bus apps target a specific bus; null → `MessageBusInterface` (default bus, M8). When set while `symfony/messenger` is **absent**, `JobRoutingListener` is undefined, so the Extension's `hasDefinition`-guarded override is a no-op and the value is silently ignored (documented in the error matrix). *Trade-off:* one nullable node for the multi-bus minority; the default covers single-bus apps with no config.

---

## 4. Design

### 4.1 Unchanged components (classes + wire format)

`Job\Attribute\AsJob`, `Job\JobDispatcher`, `Job\JobEnvelope`, `Job\Serializer\{JobSerializerInterface,NativeJobSerializer,IgbinaryJobSerializer,SymfonyJobSerializer}`, and `Job\Exception\JobSerializationException` keep their current source (B4/B5/B6). The wire format is byte-for-byte compatible with already-queued tasks. Only their **DI registration** moves under the Messenger guard (§4.4) — the producer *class* does not change, but `JobDispatcher` is not a registered service without `symfony/messenger` (ADR-B6).

### 4.2 `Job\EventListener\JobRoutingListener` (rewritten) — consumer router onto Messenger

```php
namespace FluffyDiscord\RoadRunnerBundle\Job\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\JobSerializerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class JobRoutingListener
{
    /** Transport name stamped on every RR-consumed message; target it via #[AsMessageHandler(fromTransport: 'roadrunner')] to scope a handler to RR jobs (ADR-B2/M5). */
    public const TRANSPORT_NAME = 'roadrunner';

    /** @param array<non-empty-string, JobSerializerInterface> $serializers keyed by name() */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly array $serializers,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onJobsRun(JobsRunEvent $event): void
    {
        $envelope = JobEnvelope::fromTask($event->getPayload(), $event->getHeaders());
        if ($envelope === null) {
            return; // not a bundle envelope — raw JobsRunEvent listeners own it (metric c)
        }

        $serializer = $this->serializers[$envelope->serializerName] ?? null;
        if ($serializer === null) {
            throw new JobSerializationException(\sprintf(
                'No serializer "%s" available to decode job "%s".',
                $envelope->serializerName,
                $envelope->messageClass,
            ));
        }

        $message = $serializer->deserialize($envelope->payload, $envelope->messageClass);

        try {
            $this->bus->dispatch($message, [
                new ReceivedStamp(self::TRANSPORT_NAME),          // M3: handle here, never re-send
                new HandlerArgumentsStamp([$event->getTask()]),   // M6: pass the raw task to handlers
            ]);
        } catch (NoHandlerForMessageException $e) {
            // A valid envelope nobody handles is acked, not nack-looped (ADR-B3).
            $this->logger?->warning('No handler for job message "{class}"; acking as no-op.', [
                'class' => $envelope->messageClass,
                'exception' => $e,
            ]);
        }
        // Any other throwable (incl. HandlerFailedException) propagates → worker nacks/requeues (B2/ADR-B4).
    }
}
```

Notes: the `ReceivedStamp` forces handle-not-send (M3); `HandlerArgumentsStamp` exposes the raw task (M6). `NoHandlerForMessageException` is the **only** caught type (ADR-B3); `HandlerFailedException` and serializer errors propagate (ADR-B4). The listener never calls `ack()/nack()` — the worker owns the frame (rr-jobs-worker.md Invariant I-2) unless a handler took ownership via the task argument.

### 4.3 DI wiring — `config/jobs.php`

The Jobs block stays inside `class_exists(Consumer::class)`. `JobsInterface::class` registration **stays where it is** (ungated — B9). The **typed-bus** services (serializers + alias + `JobDispatcher` + `JobRoutingListener`) move inside a nested `interface_exists(MessageBusInterface::class)` guard (ADR-B6). Pseudo-diff of the changed tail:

```php
// UNCHANGED, ungated (inside class_exists(Consumer::class)):
//   fluffy_discord.jobs.rr_worker, ConsumerInterface, JobsWorker, WorkerRegistry::registerWorker,
//   JobsInterface::class  ← stays here (raw RR service, B9)

if (interface_exists(MessageBusInterface::class)) {
    $services->set(NativeJobSerializer::class);
    $services->set(IgbinaryJobSerializer::class);
    $services->set(SymfonyJobSerializer::class)->args([service(SerializerInterface::class)->nullOnInvalid()]);
    $services->alias(JobSerializerInterface::class, NativeJobSerializer::class);

    $services->set(JobDispatcher::class)->public()->args([
        service(JobsInterface::class), service(JobSerializerInterface::class), 'default', // default_queue (Extension)
    ]);

    $services->set(JobRoutingListener::class)->args([
        service(MessageBusInterface::class),   // arg 0 — replaced by Extension when jobs.bus is set (ADR-B7)
        ['native' => service(NativeJobSerializer::class), 'igbinary' => service(IgbinaryJobSerializer::class), 'symfony' => service(SymfonyJobSerializer::class)],
        service(LoggerInterface::class)->nullOnInvalid(),
    ])->tag('kernel.event_listener', ['event' => JobsRunEvent::class, 'method' => 'onJobsRun', 'priority' => -100]);
}
```

The `abstract_arg`s for the old ServiceLocator/table are gone; no `JobContext` service exists. The serializer-registry array **key MUST equal each serializer's `name()`** (the consume path indexes by `$envelope->serializerName`); a test pins this invariant (TC-B5).

### 4.4 Bundle + Extension changes

- **`src/FluffyDiscordRoadRunnerBundle.php`:** remove the `class_exists(Consumer::class)` → `addCompilerPass(new JobHandlerPass(), …)` block **and** the `use …\JobHandlerPass;` import. (Centrifugo/Temporal passes untouched.)
- **`src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php`:**
  - remove the `#[AsJobHandler]` `registerAttributeForAutoconfiguration` block (`:123-144`) and the `use …\AsJobHandler;` import;
  - keep the `JobsWorker` lazy_boot (`:161-164`), `JobDispatcher` default_queue (`:166-169`), and `JobSerializerInterface` re-alias (`:171-181`) logic — all already `hasDefinition`/`hasAlias`-guarded, so they no-op cleanly when the typed bus is absent;
  - **update the `$config` PHPDoc at `:147`** so the sealed `jobs` shape gains the new key: `jobs: array{lazy_boot: bool, serializer: 'native'|'igbinary'|'symfony'|null, default_queue: non-empty-string, bus: ?string}` (B10 — without this, reading `$config["jobs"]["bus"]` fails PHPStan max);
  - add the `jobs.bus` application: when `is_string($config["jobs"]["bus"]) && $config["jobs"]["bus"] !== '' && $container->hasDefinition(JobRoutingListener::class)`, `$container->getDefinition(JobRoutingListener::class)->replaceArgument(0, new Reference($config["jobs"]["bus"]))`. `Reference` is already imported.

### 4.5 Configuration — add `jobs.bus`

Add under the `jobs` node, after `default_queue`:

```php
->scalarNode("bus")
    ->info($this->toInfo([
        'Service id of the Symfony Messenger bus the Jobs consumer dispatches into.',
        'Null (default) uses the application default bus (MessageBusInterface).',
        'Only relevant when symfony/messenger is installed and the app defines multiple buses.',
    ]))
    ->defaultNull()
->end()
```

### 4.6 `composer.json` (hard prerequisite — do this first)

`symfony/messenger` is **not yet declared** in `composer.json` (it is only vendored transitively). Before any test that builds a `MessageBus` can run, add it:

- `require-dev`: `"symfony/messenger": "^7.4 || ^8"` (resolves to the locked `v8.0.12`); run `composer update symfony/messenger` and commit the lock.
- `suggest`: `"symfony/messenger": "Enables the typed Jobs message bus: dispatch a #[AsJob] object and handle it with a standard #[AsMessageHandler]. Without it, only the raw JobsRunEvent is available."`

---

## 5. Deletions (exhaustive — derived from `grep -rln 'JobHandlerException\|AsJobHandler\|JobHandlerPass\|job_handler' src tests config docs`)

| Path | Action | Reason / replacement |
|------|--------|----------------------|
| `src/Job/DependencyInjection/Compiler/JobHandlerPass.php` | **delete** | custom map → Messenger `HandlersLocator` (M1/M7) |
| `src/Job/Attribute/AsJobHandler.php` | **delete** | → `#[AsMessageHandler]` (M7). Never in a tagged release → no deprecation shim |
| `src/Job/Exception/JobHandlerException.php` | **delete** | → `HandlerFailedException` (M2/ADR-B4) |
| `src/FluffyDiscordRoadRunnerBundle.php` | **edit** | remove `JobHandlerPass` import + `addCompilerPass` block (`:7,:26-28`) |
| `src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php` | **edit** | remove `AsJobHandler` import + autoconf block (`:13,:123-144`); add `jobs.bus` + PHPDoc (§4.4) |
| `src/Job/EventListener/JobRoutingListener.php` | **rewrite** | §4.2 |
| `config/jobs.php` | **edit** | §4.3 |
| `tests/Job/JobHandlerPassTest.php` | **delete** | tests the deleted pass |
| `tests/Job/JobRoutingListenerTest.php` | **rewrite** | uses the old ctor + `JobHandlerException`; becomes TC-B1..B6 against a real `MessageBus` |
| `tests/Job/JobBusServiceWiringTest.php` | **rewrite** | drops the `JobHandlerPass` method; becomes IT-B1/IT-B2 (Messenger present) |
| `tests/Job/Fixtures/SendWelcomeEmailHandler.php` | **edit** | `#[AsJobHandler]` → `#[AsMessageHandler]` |
| `tests/Job/Fixtures/MethodHandler.php` | **delete** | tested explicit-method `#[AsJobHandler]` inference (pass-only concern) |
| `tests/Job/Fixtures/NoTypeHandler.php` | **delete** | tested compile-time message inference failure (pass-only concern) |
| `tests/Job/Live/JobBusLiveTest.php` | **edit** | docstring references `#[AsJobHandler]` (cosmetic) |
| `tests/docker-validate-jobs.sh` | **edit** | test app: `#[AsJobHandler]` → `#[AsMessageHandler]`; add `symfony/messenger` to the harness app |
| `tests/docker-validate-all.sh` | **edit** | same app fixtures (`:262,266,294,298,384`) |
| `docs/specs/jobs-enhancements.md` | **edit (follow-up)** | §4.4/§4.6 reference the deleted attribute/pass; mark superseded (not in this PR's code scope, but flagged) |

Unchanged job tests that must stay green: `JobDispatcherTest.php`, `SerializerTest.php`, `JobEnvelopeTest.php`, `JobSerializerSelectionTest.php`.

---

## Assumptions

| # | Assumption | Evidence / if wrong |
|---|------------|---------------------|
| A-1 | Dispatching with a `ReceivedStamp` makes Messenger handle locally and never re-send, regardless of `messenger.yaml` routing. | Verified M3 (`SendMessageMiddleware.php:47-49,74-79`). Re-verify on a Messenger major upgrade (pinned `^7.4 || ^8`). |
| A-2 | `NoHandlerForMessageException` is thrown **directly** by `dispatch()` (not wrapped), so a typed `catch` works; handler failures come as `HandlerFailedException` and escape that catch. | Verified M2 (`HandleMessageMiddleware.php:112-114` direct throw; `:120-121` separate aggregation). If wrapped in a future version, a no-handler message would nack-loop — pinned by TC-B3. |
| A-3 | The configured bus is the **standard** Symfony stack (`SendMessageMiddleware` + `HandleMessageMiddleware`, `allow_no_handlers: false`). | A bus missing `HandleMessageMiddleware` would silently ack everything; one with `allow_no_handlers: true` would ack no-handler messages without the warning. Both are non-default, self-inflicted; flagged in anti-patterns. The unit tests build the standard stack, so they don't prove behaviour on an exotic app bus. |
| A-4 | Running the app's default-bus **middleware** (validation, doctrine_transaction, custom) inside the long-lived RR worker is acceptable. | Mostly desirable (transactions around handlers). Request-scoped/session-reading middleware is **not** safe in a CLI worker — such apps should point `jobs.bus` at a dedicated bus (ADR-B7). User's responsibility. |
| A-5 | A handler does not synchronously re-dispatch *the same task object*; the task passed via `HandlerArgumentsStamp` is per-dispatch and not shared state. | True by construction (M6 — the stamp travels on the envelope, no holder). A handler may freely dispatch other messages; nothing is corrupted (this is the failure mode the rejected `JobContext` holder would have had). |

## Open Questions

| # | Question | Why it matters | Blocks | Status |
|---|----------|----------------|--------|--------|
| OQ-B1 | Missing handler: **ack-and-warn** (ADR-B3) vs. **fail loud** (nack/requeue)? | Producer-before-consumer deploy gap drops vs. retries. | Nothing — reversible config-level behaviour. | **Resolved: ack-and-warn**, matching prior semantics + rr-jobs-worker.md ADR-3. A `jobs.fail_on_missing_handler` flag can flip it later if users ask. |
| OQ-B2 | Raw-task access: `HandlerArgumentsStamp` (chosen) vs. a `JobContext` holder vs. raw `JobsRunEvent` only. | Power users need RR features inside the `#[AsMessageHandler]` DX (metric d). | Nothing. | **Resolved: `HandlerArgumentsStamp` (M6).** A holder would add a stateful service, `kernel.reset`, finally-clearing, a one-task-per-process assumption, and a nested-dispatch corruption path — the stamp has none of these and is per-dispatch by construction. The raw event alone forces power users off the nice DX. |
| OQ-B3 | Gate `JobDispatcher`/serializers on `symfony/messenger`? | A user might want to produce typed tasks without Messenger. | Nothing. | **Resolved: yes — gate the whole typed bus (ADR-B6).** Clean "typed bus = Messenger" model. `JobsInterface` stays ungated (B9). The zero-dep path is the raw `JobsRunEvent` with the user's **own** payload format — it never used the bundle serializers, so nothing is lost. |
| OQ-B4 | Make the `ReceivedStamp` transport name configurable? | `#[AsMessageHandler(fromTransport: X)]` matches RR jobs only if `X` == the name (M5). | Nothing. | **Resolved: no — fixed public constant `'roadrunner'`.** Avoids extra config; documented so handlers that scope themselves use `fromTransport: 'roadrunner'`. A different value not matching is documented (ADR-B2) and pinned by TC-B6. |

*No user-blocking unknown. The wire contract is unchanged; the `symfony/messenger` soft dependency and `jobs.bus` are reversible defaults.*

---

## N-3. Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Dispatch the rehydrated message without a `ReceivedStamp` | `dispatch($msg, [new ReceivedStamp(self::TRANSPORT_NAME), new HandlerArgumentsStamp([$task])])` | Without it, a class the user also routed in `messenger.yaml` is re-sent to a transport instead of handled — a silent loop (M3/ADR-B2) |
| Catch `\Throwable`/`HandlerFailedException` around `dispatch()` and swallow it | Catch **only** `NoHandlerForMessageException` | Swallowing handler failures would ack poison messages; the worker must nack/requeue real failures (ADR-B4) |
| Ack/nack inside `JobRoutingListener` | Let it throw to nack, return to ack; a handler may take the task (2nd arg) and call `requeue()`/`nack()` | The worker owns the single ack/nack frame (rr-jobs-worker.md Invariant I-2) |
| Reach the raw task via a stamp `$envelope->last(...)` inside a handler, or a holder service | Declare `__invoke(MyMessage $m, ReceivedTaskInterface $task)`; the listener passes it via `HandlerArgumentsStamp` (M6) | Handlers receive only the message + positional extras, never the envelope (M7); a holder adds avoidable state |
| Scope a handler with `#[AsMessageHandler(fromTransport: 'something-else')]` and expect it to run for RR jobs | Use `fromTransport: JobRoutingListener::TRANSPORT_NAME` (`'roadrunner'`), or omit `fromTransport` entirely | `HandlersLocator` filters by transport name when a `ReceivedStamp` is present (M5); a mismatch yields no-handler → ack-drop (ADR-B3) |
| Point `jobs.bus` at (or leave the default as) a bus stripped of `HandleMessageMiddleware`, or set `allow_no_handlers: true`, or stack request-scoped middleware | Use the standard bus stack; use a dedicated `jobs.bus` for worker-safe middleware (ADR-B7/A-3/A-4) | A non-standard stack breaks the no-handler guarantee or runs web-only middleware in a CLI worker |
| Hard-`require` `symfony/messenger` | `require-dev` + `suggest`, gated by `interface_exists` (ADR-B6) | The raw `JobsRunEvent` path must keep working with zero extra deps |
| Move `JobsInterface` under the Messenger guard | Keep it ungated inside `class_exists(Consumer::class)` (B9) | It is a raw RR-Jobs RPC client with no Messenger dependency; gating it would remove a service users may inject |
| Re-introduce a custom handler attribute/table | Use `#[AsMessageHandler]` + the bus | The whole point of Option B is to stop duplicating Messenger's routing |
| Let a serializer-registry array key drift from its `name()` | Key the registry by `$serializer->name()` value; pin with a test | The consume path indexes by `$envelope->serializerName` (B6) |

## N-2. Test Case Specifications

New/updated tests under `tests/Job/`. The listener tests build a **real** `MessageBus` from Messenger's own components (no mocking of finals): `HandleMessageMiddleware(new HandlersLocator([Msg::class => [new HandlerDescriptor($spy, $opts)]]))`, and for send-skip coverage a `SendMessageMiddleware` with a spy sender. `EnvelopeTask` (the existing `ReceivedTaskInterface` double) is reused. Producer/serializer/envelope tests (`JobDispatcherTest`, `SerializerTest`, `JobEnvelopeTest`, `JobSerializerSelectionTest`) are **unchanged** and must stay green.

### Unit tests
| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-B1 | `JobRoutingListener` enveloped → handler | real bus + `#[AsMessageHandler]` spy + Native serializer; `EnvelopeTask` from a real `JobEnvelope` | handler invoked once with a message `==` the original (incl. private + nested state); task not ack/nacked | round-tripped via both `native` and `symfony` (data provider) |
| TC-B2 | `JobRoutingListener` non-enveloped ignored | `EnvelopeTask` with no `x-job-class` header | listener returns; bus never dispatched; no exception | empty headers; foreign `x-*` headers |
| TC-B3 | `JobRoutingListener` no handler = no-op | enveloped task, bus with empty `HandlersLocator` | listener returns normally (caught `NoHandlerForMessageException`); task not nacked; warning logged via a spy logger | logger `null` → still no throw |
| TC-B4 | `JobRoutingListener` handler throws → propagates | enveloped task, handler throws `\RuntimeException` | `HandlerFailedException` propagates out of `onJobsRun`; original retrievable; task not acked by listener | — |
| TC-B5 | `JobRoutingListener` unknown serializer + registry-key invariant | header `x-job-serializer: nonexistent`; and assert each registry key === serializer `name()` | `JobSerializationException` thrown (propagates → nack); keys match `name()` | — |
| TC-B6 | raw task reaches handler + `fromTransport` scoping | handler declaring `(Msg $m, ReceivedTaskInterface $task)`; plus a `fromTransport`-scoped descriptor (matching vs. mismatching `'roadrunner'`) | handler receives the exact `ReceivedTaskInterface`; matching `fromTransport` runs, mismatching does not (→ no-op) | — |
| TC-B7 | `ReceivedStamp` forces local handling | bus whose `SendMessageMiddleware` has a senders locator that WOULD route the message; dispatch via the listener | the spy sender is **not** called; the handler IS called (proves M3 skip) | — |

### Integration tests
| Test ID | Flow | Setup | Verification | Type |
|---------|------|-------|--------------|------|
| IT-B1 | service wiring (Messenger present) | load `config/services.php` into a `ContainerBuilder` (Messenger is installed in dev, so the guard is true) | `JobsInterface` registered (ungated); `JobDispatcher` public; `JobRoutingListener` tagged on `JobsRunEvent::onJobsRun` @ `-100`; arg 0 is the default `MessageBusInterface` reference | real container |
| IT-B2 | `jobs.bus` override (Messenger present) | container + run the Extension `load()` with `['jobs' => ['bus' => 'app.custom_bus']]` | `JobRoutingListener` arg 0 is a `Reference` whose `(string)` is `app.custom_bus` | real container + Extension |
| IT-B3 | round-trip dispatcher ↔ listener | capture the dispatcher-built task payload+headers, feed into the listener backed by a real bus + handler | handler receives an object equal to the dispatched one | parametrized over `native` + `symfony` |
| IT-LIVE | real RR jobs pool | provisioned `rr` + `.rr.yaml` `memory` pipeline + bundle `MODE_JOBS` worker; `symfony/messenger` in the harness app; dispatch via `JobDispatcher`, consume; `#[AsMessageHandler]` writes proof; raw non-enveloped task still reaches a plain `JobsRunEvent` listener | handler runs and the task is acked; raw task reaches the plain listener | **`@group jobs-live`, skipped** unless `RR_JOBS_LIVE=1` + `rr` present |

*Floors: ≥5 unit (7) and ≥3 integration (4) — met. Both serializer paths exercised (TC-B1/IT-B3).*

**Live test environment (IT-LIVE):** `rr` on PATH; `.rr.yaml` with `rpc:` + `jobs: { pipelines: { default: { driver: memory } }, consume: [default] }`; a worker command running the bundle in `MODE_JOBS`; `symfony/messenger` installed in the harness app; `RR_RPC`/`RR_JOBS_LIVE=1`. `markTestSkipped()` otherwise. Provisioned by `tests/docker-validate-jobs.sh`, whose test app uses `#[AsMessageHandler]`.

## N-1. Error Handling Matrix

### Internal failures
| Error type | Detection | Response | Logging | Worker action |
|------------|-----------|----------|---------|---------------|
| Message not serializable (closure/resource) | `serialize()` throws at dispatch | propagate `\Throwable` | caller's | dispatch fails synchronously (B4) |
| `jobs.serializer: symfony` but `symfony/serializer` absent | `SymfonyJobSerializer` (null wrapped) | `JobSerializationException` | caller's | dispatch fails synchronously (B6) |
| Enveloped task, named serializer unavailable (consume) | `$serializers[name] ?? null` null | `JobSerializationException` | worker STDERR/Sentry (B2) | `nack(..,redelivery:true)` |
| Enveloped task, class no longer exists | `JobEnvelope::fromTask` `!class_exists` | `JobSerializationException` | worker STDERR/Sentry | nack/requeue |
| Deserialize fails (corrupt payload) | serializer throws / type guard | `JobSerializationException` | worker STDERR/Sentry | nack/requeue |
| Handler throws | `dispatch()` raises `HandlerFailedException` | propagate | worker STDERR/Sentry (B2) | nack/requeue (ADR-B4) |
| No handler for a valid envelope (standard stack) | `NoHandlerForMessageException` caught | log warning, return | warning via `LoggerInterface` (ADR-B3) | worker acks |
| Handler scoped to a non-matching `fromTransport` | no handler matches → `NoHandlerForMessageException` | as above (ack + warn) | warning | worker acks (ADR-B2/M5) |
| Handler took the task and `requeue()`d/`nack()`d it | `task->isCompleted()` true | worker does not double-respond | handler's | per handler (B2) |
| `jobs.bus` set but `symfony/messenger` absent | `hasDefinition(JobRoutingListener::class)` false in Extension | override silently skipped | none | only raw `JobsRunEvent` available (ADR-B7) |
| `symfony/messenger` absent | `interface_exists` false at compile | typed bus not registered (`JobsInterface` still registered) | n/a (build-time) | only raw `JobsRunEvent` available (ADR-B6) |

### Producer/consumer outcomes
| Outcome | Effect on the job |
|---------|-------------------|
| dispatch success | task pushed with `x-job-class`/`x-job-serializer` headers (unchanged) |
| handler success | worker `ack()`s |
| handler/deserialize failure | worker `nack(..,redelivery:true)` (poison-message caveat per rr-jobs-worker.md ADR-3) |
| no/mismatched handler for valid envelope | worker `ack()`s; warning logged |
| non-enveloped task | router ignores; raw `JobsRunEvent` listeners handle it (additive guarantee) |

## N. References

| Topic | Location | Anchor |
|-------|----------|--------|
| Jobs worker + ack/nack semantics | [`docs/specs/rr-jobs-worker.md`](rr-jobs-worker.md) | §4.2, ADR-3, Invariant I-2 |
| Event to listen on | [`src/Event/Worker/Jobs/JobsRunEvent.php`](../../src/Event/Worker/Jobs/JobsRunEvent.php) | `getPayload`/`getHeaders`/`getTask` |
| Sequential one-task loop (A-5) | [`src/Worker/JobsWorker.php`](../../src/Worker/JobsWorker.php) | `start()` `:59-129` |
| Producer (unchanged class) | [`src/Job/JobDispatcher.php`](../../src/Job/JobDispatcher.php) | `:31-70` |
| Wire contract (unchanged) | [`src/Job/JobEnvelope.php`](../../src/Job/JobEnvelope.php) | `:14-63` |
| Serializers (unchanged) | [`src/Job/Serializer/`](../../src/Job/Serializer/) | `JobSerializerInterface` |
| Messenger dispatch | `vendor/symfony/messenger/MessageBusInterface.php` | `:30` |
| ReceivedStamp skip-send | `vendor/symfony/messenger/Middleware/SendMessageMiddleware.php` | `:47-79` |
| No-handler / handler-failed | `vendor/symfony/messenger/Middleware/HandleMessageMiddleware.php` | `:112-122` |
| HandlerArgumentsStamp call | `vendor/symfony/messenger/Middleware/HandleMessageMiddleware.php` | `:90,138-149` |
| fromTransport filter | `vendor/symfony/messenger/Handler/HandlersLocator.php` | `:85-96` |
| Handler attribute | `vendor/symfony/messenger/Attribute/AsMessageHandler.php` | `:20-47` |
| DI wiring to change | [`config/jobs.php`](../../config/jobs.php) | `:71-111` |
| Bundle pass to remove | [`src/FluffyDiscordRoadRunnerBundle.php`](../../src/FluffyDiscordRoadRunnerBundle.php) | `:7,:26-28` |
| Extension autoconf to remove + config/PHPDoc to add | [`src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php`](../../src/DependencyInjection/FluffyDiscordRoadRunnerExtension.php) | `:13,:123-144,:147,:161-181` |
| Config node to extend | [`src/DependencyInjection/Configuration.php`](../../src/DependencyInjection/Configuration.php) | `jobs` node `:139-179` |
| Test double for tasks | [`tests/Job/Fixtures/EnvelopeTask.php`](../../tests/Job/Fixtures/EnvelopeTask.php) | `ReceivedTaskInterface` |
| Live harness | [`tests/docker-validate-jobs.sh`](../../tests/docker-validate-jobs.sh) | app fixtures |
