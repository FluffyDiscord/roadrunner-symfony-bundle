# Temporal usage guide

> Beta — the API may still change.

[Temporal](https://learn.temporal.io/getting_started/php/) with this bundle: write activities and workflows as services, start them with an autowired client, see everything in logs, the profiler and `debug:temporal`.

## 1. Install

```bash
composer require temporal/sdk
```

Activates automatically. The client needs the `grpc` PHP extension (only once you inject it).

`.rr.yaml`:

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

Local server: `temporal server start-dev`.

**The bundle reads the Temporal address from RoadRunner.** No RoadRunner during the container build (Docker image, CI)? Set `rr_config_path: .rr.yaml` in the bundle config.

## 2. Activity

One class. Put it on a task queue with `#[TaskQueue]` (no name = `default`):

```php
namespace App\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use Temporal\Activity\ActivityInterface;

#[ActivityInterface(prefix: 'greeting.')]
#[TaskQueue]
class GreetingActivity
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
```

Activities are regular autowired services. Services are reset after every activity.

Prefer an interface? Put `#[ActivityInterface]` on the interface and `#[TaskQueue]` on either.

## 3. Workflow

```php
namespace App\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\ActivityStub;
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\TaskQueue;
use FluffyDiscord\RoadRunnerBundle\Temporal\Workflow\AbstractWorkflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GreetingWorkflowInterface
{
    #[WorkflowMethod(name: 'GreetingWorkflow')]
    public function greet(string $name): \Generator;
}

#[TaskQueue]
class GreetingWorkflow extends AbstractWorkflow implements GreetingWorkflowInterface
{
    /** @var GreetingActivity */
    #[ActivityStub(GreetingActivity::class, startToClose: '10 seconds', retryAttempts: 3)]
    private $greeting;

    public function greet(string $name): \Generator
    {
        return yield $this->greeting->greet($name);
    }
}
```

**Stub properties stay untyped** — the SDK proxy is not an instance of the activity. The `@var` is for your IDE.

`#[ActivityStub]` options:

- `startToClose` or `scheduleToClose` — required. Seconds, `'30 minutes'`, or `\DateInterval`.
- `scheduleToStart`, `heartbeat` — same format.
- `queue` — defaults to the workflow's queue.
- `retryAttempts` (`0` = unlimited), `retryBackoff`, `retryInitialInterval`, `retryMaxInterval`, `nonRetryable`.

Own constructor (e.g. `#[WorkflowInit]`)? `use HasActivityStubs` instead of extending, and call `$this->initActivityStubs()`.

Same stub settings everywhere? Subclass the attribute:

```php
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MediaActivityStub extends ActivityStub
{
    public function __construct(string $activity)
    {
        parent::__construct($activity, queue: 'media', startToClose: '5 minutes', retryAttempts: 3);
    }
}
```

Mistakes — missing `#[TaskQueue]`, typed stub, missing timeout, unknown activity, bad duration — fail the container build with a clear message.

## 4. Start a workflow

Put the start defaults on the interface, then start by name:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Attribute\WorkflowDefaults;
use Temporal\Common\IdReusePolicy;

#[WorkflowInterface]
#[WorkflowDefaults(queue: 'default', executionTimeout: '1 hour', reusePolicy: IdReusePolicy::AllowDuplicateFailedOnly)]
interface GreetingWorkflowInterface { /* ... */ }
```

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Client\WorkflowLauncherInterface;

public function __construct(private readonly WorkflowLauncherInterface $launcher) {}

$run = $this->launcher->of(GreetingWorkflowInterface::class)
    ->id('greet-world')
    ->startOrSkip('World');   // null when that id already runs; start() throws instead
```

- Fluent methods override the defaults: `id()`, `queue()`, `executionTimeout()`, `reusePolicy()`, `conflictPolicy()`, `retry()`.
- `searchAttributes()`, `typedSearchAttributes()` and `memo()` attach data to the run — see [Status and progress](#status-and-progress).
- `#[WorkflowDefaults]` fields: `queue`, `reusePolicy`, `conflictPolicy`, `executionTimeout`, `retryAttempts`, `retryBackoff`.

The SDK clients are autowired too — `Temporal\Client\WorkflowClientInterface` and `ScheduleClientInterface`, with the configured namespace, API key, data converter and interceptors:

```php
$workflow = $this->workflowClient->newWorkflowStub(
    GreetingWorkflowInterface::class,
    WorkflowOptions::new()->withTaskQueue('default'),
);
$workflow->greet('World');
```

## 5. Configuration

```yaml
fluffy_discord_road_runner:
    temporal:
        namespace: 'default'
        api_key: '%env(TEMPORAL_API_KEY)%'
        retryable_errors: [\Error]     # exceptions Temporal may retry
        worker_options:                # SDK WorkerOptions, one entry per task queue
            default:
                max_concurrent_activity_execution_size: 10
            billing:
                max_concurrent_activity_execution_size: 4
                worker_stop_timeout: '30 seconds'
                workflow_panic_policy: FailWorkflow
```

**One worker runs per task queue** — `default` plus every queue named in a `#[TaskQueue]`. No worker classes to write.

- `worker_options.<queue>` keys are the `Temporal\Worker\WorkerOptions` properties in snake_case. `bin/console config:dump-reference fluffy_discord_road_runner` lists them all.
- Durations take seconds or a duration string. Enums take the case name.
- `default` is the queue named `default`, not a fallback for other queues.
- A queue in `worker_options` that no `#[TaskQueue]` uses fails the build (typo guard).
- RoadRunner's `temporal:` block sizes the process pool; `worker_options` tunes the SDK worker inside each process.

## 6. Observability

### Logs

Everything goes to the `temporal` Monolog channel:

- **Activity failures** — logged as errors with the exception, activity type, attempt, workflow id and task queue. The first attempt is also sent to Sentry when it is installed. Cancellations are not errors.
- **SDK logs** — the worker logs through the same channel.
- **Your workflow code** — use `Workflow::getLogger()`. It skips replays, so each line appears once (`enable_logging_in_replay: true` to keep them).

**Every log line written inside a workflow or activity carries its Temporal context** — in any channel, your own logger included (needs MonologBundle):

```json
"extra": {"temporal": {"workflowType": "IngestWorkflow", "workflowId": "ingest-42", "runId": "…", "activityType": "ingest.import", "activityId": "5", "taskQueue": "ingest", "attempt": 2}}
```

Filter your log search by `extra.temporal.workflowId` to see one run end to end.

Route the channel wherever you want:

```yaml
monolog:
    handlers:
        temporal:
            type: stream
            path: '%kernel.logs_dir%/temporal.log'
            channels: [temporal]
```

### Status and progress

**Temporal already stores every run's status** — running, completed, failed, canceled, timed out. No status table needed. Tag runs with search attributes to find them:

```php
$this->launcher->of(IngestWorkflowInterface::class)
    ->id('ingest-' . $documentId)
    ->searchAttributes(['WebsiteId' => $websiteId])
    ->memo(['source' => 'catalog'])
    ->start($documentId);
```

```php
$runs = $this->workflowClient->listWorkflowExecutions(
    "WorkflowType = 'IngestWorkflow' AND WebsiteId = 'site-1' AND ExecutionStatus = 'Failed'",
);
```

- Register custom attributes on the server first: `temporal operator search-attribute create --name WebsiteId --type Keyword`.
- Typed keys: `->typedSearchAttributes(TypedSearchAttributes::empty()->withValue(SearchAttributeKey::forKeyword('WebsiteId'), $websiteId))`. Use one of the two, not both.
- Progress from inside the workflow: `Workflow::upsertTypedSearchAttributes(SearchAttributeKey::forInteger('ProcessedChunks')->valueSet($count))`, or answer a `#[QueryMethod]`.
- Memo is shown with the run but not searchable.

### Profiler

The Temporal panel shows every client call the request made — start, signal, query, update, cancel, terminate, result — with workflow id, run id, task queue, time and error. Below: task queues with their options, workflows and activities.

### OpenTelemetry

```bash
composer require temporal/open-telemetry-interceptors
```

Done — client calls, workflow operations and activities become spans. The tracer comes from the OpenTelemetry SDK's environment setup (`OTEL_PHP_AUTOLOAD_ENABLED=true`, `OTEL_EXPORTER_OTLP_ENDPOINT`, …).

Own tracer provider? Override the `Temporal\OpenTelemetry\Tracer` service.

Drop one of the three interceptors — keep the service, stop autoconfiguring it:

```yaml
services:
    Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowOutboundRequestInterceptor:
        autoconfigure: false
        arguments: ['@Temporal\OpenTelemetry\Tracer']
```

> `OpenTelemetryWorkflowOutboundRequestInterceptor` exports spans synchronously. Run a local collector.

### Interceptors

Any service implementing a Temporal SDK interceptor interface joins the pipeline — for the client and the worker. Nothing to configure:

```php
use Temporal\Interceptor\ActivityInboundInterceptor;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;

class ActivityMetricsInterceptor implements ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $next($input);
        } finally {
            $this->metrics->timing('temporal.activity', hrtime(true) - $startedAt);
        }
    }
}
```

- Order: `#[AsTaggedItem(priority: 10)]` — higher runs outermost.
- `GrpcClientInterceptor` wraps each raw gRPC request the client sends to Temporal — metadata headers, network timing.

### Events

Only need to look at or change a call's input? Listen to a Symfony event instead — one per interceptor method:

```php
use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\Event\WorkflowOutboundCalls\ExecuteActivityEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class ActivityInputListener
{
    public function __invoke(ExecuteActivityEvent $event): void
    {
        $event->setInput($event->getInput()->with(/* ... */));
    }
}
```

Namespaces: `…\Temporal\Interceptor\Event\{WorkflowClient, WorkflowInboundCalls, WorkflowOutboundCalls, ActivityInbound}`. Events fire before the call; for timing and results use an interceptor.

> Workflow-side events run inside deterministic workflow code: no I/O, no clock, no randomness in those listeners.

### Correlation id

`temporal.tracing: true` puts the request's `X-Request-Id` (or a generated id) into every started workflow's header as `x-correlation-id`, logs starts and activity calls, and adds Sentry breadcrumbs.

### Live workers

`TemporalWorkerRegistry` holds the running SDK worker per task queue — inside the Temporal worker process only:

```php
$worker = $this->workers->get('billing');   // Temporal\Worker\WorkerInterface|null
```

## 7. `debug:temporal`

```bash
bin/console debug:temporal                        # task queues, options, workflows, stubs, activities
bin/console debug:temporal --format=json
bin/console debug:temporal --format=mermaid > flow.mmd   # workflow → activity flowchart
```

Reads the build-time registration — no Temporal connection.

## 8. Customizing

- **Data converter** — point the alias at your service; client and worker both use it:

  ```yaml
  services:
      Temporal\DataConverter\DataConverterInterface: '@App\Temporal\MyDataConverter'
  ```

- **Worker factory** — `Temporal\Worker\WorkerFactoryInterface` is a service; decorate it for anything config can't express (e.g. experimental deployment options).
- **Introspection** — `debug:temporal` and the profiler read `TemporalIntrospectorInterface`; decorate or replace it.
