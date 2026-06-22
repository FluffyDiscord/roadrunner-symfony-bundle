# Temporal usage guide

> Temporal support is in **beta** — the DX is still being explored and the API may change until it settles.

Developer guide for using [Temporal](https://learn.temporal.io/getting_started/php/) with this bundle: defining activities and workflows, assigning them to workers, configuring the integration, starting workflows, and reacting to interceptor events.

## 1. Install

The integration activates automatically once the SDK is installed:

```bash
composer require temporal/sdk
```

## 2. Enable the `temporal` plugin in `.rr.yaml`

```yaml
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'

rpc:
    listen: "tcp://127.0.0.1:6001"

temporal:
    address: "127.0.0.1:7233"   # your Temporal server
    activities:
        num_workers: 4
```

For local development, run a Temporal dev server with `temporal server start-dev` (in-memory, no external database).

## 3. Define an Activity

A normal Temporal activity — an interface marked `#[ActivityInterface]` and an implementation. The implementation carries `#[TaskQueue]` to bind it to a task queue:

```php
namespace App;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'greeting.')]
interface GreetingActivityInterface
{
    #[ActivityMethod]
    public function greet(string $name): string;
}
```

```php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;

#[TaskQueue('my_custom_worker')] // omit the name to use the default task queue
class GreetingActivity implements GreetingActivityInterface
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
```

## 4. Define a Workflow

```php
namespace App;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GreetingWorkflowInterface
{
    #[WorkflowMethod(name: 'GreetingWorkflow')]
    public function greet(string $name): \Generator;
}
```

```php
namespace App;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;

#[TaskQueue('default')]
class GreetingWorkflow implements GreetingWorkflowInterface
{
    public function greet(string $name): \Generator
    {
        $activity = Workflow::newActivityStub(
            GreetingActivityInterface::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(10)
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(3)),
        );

        return yield $activity->greet($name);
    }
}
```

### `#[TaskQueue]`

- Repeatable; place it on the class **or** its interface. Omit the name for the default task queue, or pass a queue name to bind it to a specific worker.
- Every workflow and activity **must** be assigned, or the container build fails with `WorkflowNotAssignedException` / `ActivityNotAssignedException` — this catches forgotten registrations at compile time instead of at runtime.

## 5. Workers

You usually define **nothing** here. The bundle ships `DefaultTemporalWorker` for the default task queue, and **auto-registers a worker for every other task queue** you name in `#[TaskQueue]` — so additional queues no longer need a hand-written worker class. Per-queue worker options are set under `temporal.worker_options` (§6).

You only implement `FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface` yourself when you want to define a queue's `WorkerOptions` in code instead of config — it just declares `getTaskQueue()` and `getWorkerOptions()`; the bundle creates the SDK worker and registers the assigned workflows/activities.

Put `#[TaskQueue('your-queue')]` on your worker class (matching its `getTaskQueue()`). The bundle reads it at compile time so it does **not** also register a default worker for that queue — your worker is the only one for it. A worker without the attribute still works, but the bundle can't see its queue at build time and will register a default alongside it (harmlessly superseded at boot). To customise the `WorkerFactory` itself (e.g. data converter), implement your own `TemporalWorkerFactoryInterface` service — see `DefaultTemporalWorkerFactory` for the default.

### Retrieving instantiated workers at runtime

The SDK worker instances can't be DI services — each is built from the live worker factory at boot, not at compile time. To reach them, inject `FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry`, which the bundle fills at boot (keyed by task queue):

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;

public function __construct(private readonly TemporalWorkerRegistry $workers) {}

// ...
$worker = $this->workers->get('default'); // Temporal\Worker\WorkerInterface|null
$all    = $this->workers->all();          // array<string, WorkerInterface>
```

> The registry is only populated **inside the running Temporal worker process** (after it boots). In a web/HTTP request or any other process no workers are instantiated, so it is empty — guard with `has()` / a null check. Typical callers are activities, interceptors or listeners running during workflow/activity execution.

## 6. Configuration

The autowired clients connect to the Temporal frontend address taken from RoadRunner itself (read from the running RoadRunner, with a `.rr.yaml` fallback via `rr_config_path`) — the same `temporal.address` your worker uses — so there is no separate address to configure here. If neither source yields a `temporal.address`, the container build fails with a clear error rather than silently defaulting to `127.0.0.1` — set `temporal.address` in `.rr.yaml` (and `rr_config_path` for offline builds, e.g. Docker images).

```yaml
fluffy_discord_road_runner:
    temporal:
        namespace: 'default'
        tracing: false          # opt-in tracing listener — see §9
        api_key: '%env(TEMPORAL_API_KEY)%'
        retryable_errors:
            - \Error
        default_worker_options:   # SDK WorkerOptions for the default task queue
            maxConcurrentActivityExecutionSize: 10
        worker_options:           # same, per task queue — the key is the queue name
            billing:              # i.e. the worker for #[TaskQueue('billing')]
                maxConcurrentActivityExecutionSize: 4
```

`default_worker_options` and each `worker_options.<queue>` entry map to `Temporal\Worker\WorkerOptions`; an unknown key fails configuration validation. Duration options (e.g. `stickyScheduleToStartTimeout`, `workerStopTimeout`) accept an int of seconds or a duration string. Only scalar and duration options are settable here; enum/value-object options (e.g. `workflowPanicPolicy`) are rejected with a clear error — set those via a custom `TemporalWorkerInterface`. The `<queue>` key (e.g. `billing`) is the task-queue name you put in `#[TaskQueue('billing')]`.

> **Not the same as `.rr.yaml`.** RoadRunner's `temporal:` config controls the *worker process pool* (`activities.num_workers`, the address, etc.). `WorkerOptions` here are the *SDK-level* knobs the bundle passes to `newWorker($queue, $options)` — per-queue concurrency caps, poller counts and rate limits — which RoadRunner's config does not cover. The default queue uses `default_worker_options`; every other queue the bundle auto-registers reads `worker_options.<queue>` (empty if unset).

## 7. Start a workflow

The bundle wires the SDK's `WorkflowClientInterface` (and `ScheduleClientInterface`) as autowired services — reusing the configured address/namespace, the bundle's data converter, the api key, and the interceptor pipeline. Just type-hint it; no manual `ServiceClient` construction, and the client-side interceptor events (§8) fire:

```php
use App\GreetingWorkflowInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

final class StartGreeting
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
    ) {
    }

    public function __invoke(): string
    {
        $workflow = $this->workflowClient->newWorkflowStub(
            GreetingWorkflowInterface::class,
            WorkflowOptions::new()
                ->withTaskQueue('default')
                ->withWorkflowExecutionTimeout(30),
        );

        return $workflow->greet('World'); // "Hello, World"
    }
}
```

> Building a Temporal client requires the `grpc` PHP extension. The client services are lazy, so the requirement only applies once you inject one.

Temporal **schedules** work the same way — inject `Temporal\Client\ScheduleClientInterface`.

## 8. Interceptor events

The bundle dispatches a Symfony event for every Temporal interceptor call — workflow client, inbound/outbound workflow calls, and activity inbound. Listen to mutate input, add logging/metrics, etc.:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ExecuteActivityEvent::class)]
final class ActivityInputListener
{
    public function __invoke(ExecuteActivityEvent $event): void
    {
        // inspect / mutate the call, e.g. $event->setInput(...)
    }
}
```

The event classes live under `FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\…` (`WorkflowClient`, `WorkflowInboundCalls`, `WorkflowOutboundCalls`, `ActivityInbound`). Each extends `MutableInputEvent` and exposes `getInput()` / `setInput()`, typed to the SDK input of that call.

## 9. Tracing

Set `temporal.tracing: true` to register the bundle's `TemporalTracingListener` (off by default, zero cost otherwise). It:

- logs selected interceptor events on the `temporal` Monolog channel,
- adds Sentry breadcrumbs when Sentry is installed, and
- propagates a correlation id (the request's `X-Request-Id`, else a generated one) into every started workflow's header under `x-correlation-id`, so a workflow run can be tied back to the request that started it.

It is built on the interceptor events (§8), so you can write your own listener instead — or replace the bundle's by overriding the `TemporalTracingListener` service id — for full control.

## 10. Profiler

When the Symfony profiler is enabled, a data-collector tab lists the registered workers, workflows, and activities per task queue. The data comes from the compile-time registration map, so opening the profiler does not open a Temporal/RPC connection.

## 11. Declarative activity stubs

Instead of building activity stubs by hand in the workflow constructor, declare them with `#[ActivityStub]` and let the bundle hydrate them before the workflow runs:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;

#[TaskQueue('default')]
class GreetingWorkflow extends AbstractWorkflow implements GreetingWorkflowInterface
{
    /** @var GreetingActivityInterface */
    #[ActivityStub(GreetingActivityInterface::class, startToClose: '10 seconds', retryAttempts: 3)]
    private $greeting;

    public function greet(string $name): \Generator
    {
        return yield $this->greeting->greet($name);
    }
}
```

`#[ActivityStub]` options: `activity` (the interface, or a single-class activity FQCN), `queue` (omit to inherit the workflow's own queue), `startToClose` / `scheduleToClose` / `scheduleToStart` / `heartbeat` (an `int` of seconds, a duration string like `'30 minutes'`, or a `\DateInterval`), `retryAttempts` (omit for Temporal's default; `0` = unlimited), `retryBackoff`, `retryInitialInterval`, `retryMaxInterval`, `nonRetryable`.

- **Extend `AbstractWorkflow`** — its constructor hydrates the stubs. If the workflow needs its own constructor (e.g. `#[WorkflowInit]`), `use HasActivityStubs` and call `$this->initActivityStubs()` from it instead.
- **Stub properties must be untyped** (`/** @var Interface */` for the IDE only). The SDK's activity proxy does not implement the interface, so a *typed* property throws a `TypeError` on every workflow task — the bundle fails the container build with a clear message if you type one.
- Stubs declared on a **trait** or a **parent class** are hydrated too, so shared stage stubs can live in one trait.
- `#[ActivityStub]` is **extensible** — subclass it to ship a project preset (e.g. a `#[CustomQueueActivityStub]` that fixes the queue, timeouts and retries). The bundle matches any subclass (`IS_INSTANCEOF`). Mark your subclass with its own `#[\Attribute(\Attribute::TARGET_PROPERTY)]` — PHP does not inherit it:
  ```php
  #[\Attribute(\Attribute::TARGET_PROPERTY)]
  class CustomQueueActivityStub extends ActivityStub {
      public function __construct(string $activity) {
          parent::__construct($activity, queue: 'custom_queue', startToClose: '5 minutes', retryAttempts: 3);
      }
  }
  // then: #[CustomQueueActivityStub(MediaActivityInterface::class)] private $media;
  ```

## 12. Single-file activities

An activity does not need a separate interface — put `#[ActivityInterface]` on the class and reference it by its concrete name (`#[ActivityMethod]` is optional):

```php
#[ActivityInterface(prefix: 'greeting.')]
#[TaskQueue('default')]
class GreetingActivity
{
    public function greet(string $name): string { return 'Hello, ' . $name; }
}
// in a workflow: #[ActivityStub(GreetingActivity::class, startToClose: 10)] private $greeting;
```

## 13. Starting workflows

Declare a workflow's default start options on its interface with `#[WorkflowDefaults]`, so they live with the workflow instead of being repeated at every call site:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\WorkflowDefaults;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;
use Temporal\Workflow\WorkflowInterface;

#[WorkflowInterface]
#[WorkflowDefaults(
    queue: 'default',
    reusePolicy: IdReusePolicy::AllowDuplicateFailedOnly,
    conflictPolicy: WorkflowIdConflictPolicy::UseExisting,
)]
interface GreetingWorkflowInterface { /* ... */ }
```

Inject `WorkflowLauncherInterface` and start it — `of()` seeds the builder from the attribute; the fluent methods override any field (the dynamic id, or a different reuse policy on a forced re-run):

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncherInterface;

public function __construct(private readonly WorkflowLauncherInterface $launcher) {}

$run = $this->launcher->of(GreetingWorkflowInterface::class)
    ->id('greet-world')
    ->startOrSkip('World');
```

`#[WorkflowDefaults]` fields (all optional): `queue`, `reusePolicy`, `conflictPolicy`, `executionTimeout` (seconds / duration string / `\DateInterval`), `retryAttempts`, `retryBackoff`. `start(...)` returns the SDK `WorkflowRunInterface` and throws `WorkflowExecutionAlreadyStartedException`; `startOrSkip(...)` catches it and returns `null`. `of()` returns a fresh, mutable builder each call, and `WorkflowLauncherInterface` is a decoratable / replaceable service.

## 14. Console commands

The bundle adds two commands that read the compile-time registration map (no Temporal/RPC connection):

- `temporal:debug` — registered workflows/activities per task queue, with each workflow's declared stubs and their resolved options.
- `temporal:diagram` — a Mermaid `flowchart` of workflow → activity edges (`--output <file>` to write it).

Both commands and the profiler collector (§10) read that map through `TemporalIntrospectorInterface` — decorate or replace that service to change what they report.

Misconfigured stubs (a typed property, a missing/unparseable timeout, an unknown activity, or a workflow with `#[ActivityStub]`s but no `AbstractWorkflow`/`HasActivityStubs`) fail the **container build** with a clear message — no separate validation command is needed.