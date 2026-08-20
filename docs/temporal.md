# Temporal usage guide

> Beta — DX is still being explored; the API may change until it settles.

[Temporal](https://learn.temporal.io/getting_started/php/) with this bundle: activities, workflows, workers, config, starting workflows, interceptor events.

## 1. Install

```bash
composer require temporal/sdk
```

Activates automatically.

## 2. `.rr.yaml`

```yaml
server:
    command: "php public/index.php"
    env:
        APP_RUNTIME: 'FluffyDiscord\RoadRunnerBundle\Runtime\Runtime'

rpc:
    listen: "tcp://127.0.0.1:6001"

temporal:
    address: "127.0.0.1:7233"
    activities:
        num_workers: 4
```

Local dev server (in-memory, no database): `temporal server start-dev`.

## 3. Activity

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

#[TaskQueue('my_custom_worker')] // omit the name for the default task queue
class GreetingActivity implements GreetingActivityInterface
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
```

## 4. Workflow

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

- Repeatable; on the class **or** its interface. No name = default queue.
- Every workflow and activity must be assigned — otherwise the container build fails with `WorkflowNotAssignedException` / `ActivityNotAssignedException`.

## 5. Workers

Usually nothing to define:

- `DefaultTemporalWorker` serves the default task queue.
- A worker is auto-registered for every other queue named in `#[TaskQueue]`.
- Per-queue options live under `temporal.worker_options` ([§6](#6-configuration)).

Implement `FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerInterface` only to define a queue's `WorkerOptions` in code instead of config — it declares `getTaskQueue()` and `getWorkerOptions()`; the bundle builds the SDK worker and registers the assigned workflows/activities.

- Put `#[TaskQueue('your-queue')]` on your worker class, matching its `getTaskQueue()` — the bundle then skips its own default worker for that queue.
- Without the attribute the queue is invisible at build time, so a default worker is registered alongside (harmlessly superseded at boot).
- Custom `WorkerFactory` (e.g. data converter): implement `TemporalWorkerFactoryInterface`; see `DefaultTemporalWorkerFactory`.

### Instantiated workers at runtime

SDK worker instances can't be DI services — each is built from the live factory at boot. Inject `TemporalWorkerRegistry`, filled at boot, keyed by task queue:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\TemporalWorkerRegistry;

public function __construct(private readonly TemporalWorkerRegistry $workers) {}

$worker = $this->workers->get('default'); // Temporal\Worker\WorkerInterface|null
$all    = $this->workers->all();          // array<string, WorkerInterface>
```

> Populated only inside the running Temporal worker process. Empty in HTTP requests and other processes — guard with `has()` or a null check. Typical callers: activities, interceptors, listeners during workflow/activity execution.

## 6. Configuration

```yaml
fluffy_discord_road_runner:
    temporal:
        namespace: 'default'
        tracing: false          # see §9
        api_key: '%env(TEMPORAL_API_KEY)%'
        retryable_errors:
            - \Error
        default_worker_options:   # SDK WorkerOptions for the default task queue
            maxConcurrentActivityExecutionSize: 10
        worker_options:           # same, per task queue — key = queue name
            billing:              # worker for #[TaskQueue('billing')]
                maxConcurrentActivityExecutionSize: 4
```

Address: taken from the running RoadRunner, with a `.rr.yaml` fallback via `rr_config_path` — the same `temporal.address` your worker uses, so there is nothing to configure here. With neither source the container build fails instead of defaulting to `127.0.0.1`; set `temporal.address` in `.rr.yaml` (plus `rr_config_path` for offline builds, e.g. Docker images).

Worker options:

- `default_worker_options` and each `worker_options.<queue>` map to `Temporal\Worker\WorkerOptions`; unknown keys fail configuration validation.
- Duration options (`stickyScheduleToStartTimeout`, `workerStopTimeout`, …) take an int of seconds or a duration string.
- Scalar and duration options only. Enum/value-object options (e.g. `workflowPanicPolicy`) are rejected with a clear error — set those via a custom `TemporalWorkerInterface`.
- `<queue>` is the name from `#[TaskQueue('billing')]`.

> **Not `.rr.yaml`.** RoadRunner's `temporal:` config controls the worker *process pool* (`activities.num_workers`, address). `WorkerOptions` are SDK-level knobs passed to `newWorker($queue, $options)` — per-queue concurrency caps, poller counts, rate limits — which RoadRunner's config does not cover. Default queue reads `default_worker_options`; every auto-registered queue reads `worker_options.<queue>` (empty if unset).

## 7. Start a workflow

`WorkflowClientInterface` and `ScheduleClientInterface` are autowired — configured address/namespace, the bundle's data converter, the api key, and the interceptor pipeline included. No manual `ServiceClient`; client-side interceptor events ([§8](#8-interceptor-events)) fire.

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

> Needs the `grpc` PHP extension. Client services are lazy — the requirement applies once you inject one.

Schedules: inject `Temporal\Client\ScheduleClientInterface`.

## 8. Interceptor events

A Symfony event is dispatched for every Temporal interceptor call — workflow client, inbound/outbound workflow calls, activity inbound:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ExecuteActivityEvent::class)]
final class ActivityInputListener
{
    public function __invoke(ExecuteActivityEvent $event): void
    {
        // inspect / mutate, e.g. $event->setInput(...)
    }
}
```

Classes live under `FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\…` (`WorkflowClient`, `WorkflowInboundCalls`, `WorkflowOutboundCalls`, `ActivityInbound`). Each extends `MutableInputEvent` with `getInput()` / `setInput()`, typed to that call's SDK input.

## 9. Tracing

`temporal.tracing: true` registers `TemporalTracingListener` (off by default, zero cost otherwise):

- logs selected interceptor events on the `temporal` Monolog channel,
- adds Sentry breadcrumbs when Sentry is installed,
- propagates a correlation id (request `X-Request-Id`, else generated) into every started workflow's header as `x-correlation-id`.

Built on [§8](#8-interceptor-events) — write your own listener, or override the `TemporalTracingListener` service id.

## 10. Profiler

With the profiler enabled, a data-collector tab lists registered workers, workflows and activities per task queue. Data comes from the compile-time registration map — no Temporal/RPC connection is opened.

## 11. Declarative activity stubs

Declare stubs with `#[ActivityStub]` instead of building them in the workflow constructor; the bundle hydrates them before the workflow runs:

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

Options: `activity` (interface or single-class activity FQCN), `queue` (omit to inherit the workflow's queue), `startToClose` / `scheduleToClose` / `scheduleToStart` / `heartbeat` (int seconds, duration string like `'30 minutes'`, or `\DateInterval`), `retryAttempts` (omit for Temporal's default; `0` = unlimited), `retryBackoff`, `retryInitialInterval`, `retryMaxInterval`, `nonRetryable`.

- **Extend `AbstractWorkflow`** — its constructor hydrates the stubs. Own constructor needed (e.g. `#[WorkflowInit]`)? `use HasActivityStubs` and call `$this->initActivityStubs()` from it.
- **Stub properties must be untyped** (`/** @var Interface */` for the IDE). The SDK proxy does not implement the interface, so a typed property throws `TypeError` on every workflow task — the container build fails with a clear message if you type one.
- Stubs on a **trait** or **parent class** are hydrated too.
- `#[ActivityStub]` is **extensible** — subclass it for a project preset; the bundle matches any subclass (`IS_INSTANCEOF`). Mark the subclass with its own `#[\Attribute(\Attribute::TARGET_PROPERTY)]`, PHP does not inherit it:

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

No separate interface needed — put `#[ActivityInterface]` on the class and reference the concrete name (`#[ActivityMethod]` optional):

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

Put default start options on the interface with `#[WorkflowDefaults]` instead of repeating them at every call site:

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

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncherInterface;

public function __construct(private readonly WorkflowLauncherInterface $launcher) {}

$run = $this->launcher->of(GreetingWorkflowInterface::class)
    ->id('greet-world')
    ->startOrSkip('World');
```

- `of()` seeds a fresh, mutable builder from the attribute; fluent methods override any field.
- `#[WorkflowDefaults]` fields (all optional): `queue`, `reusePolicy`, `conflictPolicy`, `executionTimeout` (seconds / duration string / `\DateInterval`), `retryAttempts`, `retryBackoff`.
- `start(...)` returns the SDK `WorkflowRunInterface` and throws `WorkflowExecutionAlreadyStartedException`; `startOrSkip(...)` catches it and returns `null`.
- `WorkflowLauncherInterface` is decoratable / replaceable.

## 14. Console commands

Both read the compile-time registration map — no Temporal/RPC connection:

- `temporal:debug` — registered workflows/activities per task queue, each workflow's declared stubs and resolved options.
- `temporal:diagram` — Mermaid `flowchart` of workflow → activity edges (`--output <file>` writes it).

They and the profiler collector ([§10](#10-profiler)) read the map through `TemporalIntrospectorInterface` — decorate or replace it to change what they report.

Misconfigured stubs (typed property, missing/unparseable timeout, unknown activity, `#[ActivityStub]`s without `AbstractWorkflow`/`HasActivityStubs`) fail the **container build** with a clear message; no validation command needed.
