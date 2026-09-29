# Temporal usage guide

> Beta — the API may still change.

## 1. Install

```bash
composer require temporal/sdk
```

The client needs the `grpc` PHP extension.

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

Container built without RoadRunner (Docker image, CI)? Set `rr_config_path: .rr.yaml` in the bundle config.

## 2. Activity

Put it on a task queue with `#[TaskQueue]` (no name = `default`):

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

**Keep stub properties untyped.** Use `@var` for your IDE.

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

## 4. Start a workflow

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

- Fluent overrides: `id()`, `queue()`, `executionTimeout()`, `reusePolicy()`, `conflictPolicy()`, `retry()`, `searchAttributes()`, `typedSearchAttributes()`, `memo()`.
- `#[WorkflowDefaults]` fields: `queue`, `reusePolicy`, `conflictPolicy`, `executionTimeout`, `retryAttempts`, `retryBackoff`.
- Need the raw SDK? Inject `Temporal\Client\WorkflowClientInterface` or `ScheduleClientInterface`.

## 5. Configuration

```yaml
fluffy_discord_road_runner:
    temporal:
        namespace: 'default'
        api_key: '%env(TEMPORAL_API_KEY)%'
        retryable_errors: [\Error]     # exceptions Temporal may retry
        worker_options:                # SDK WorkerOptions, per task queue
            default:
                max_concurrent_activity_execution_size: 10
            billing:
                max_concurrent_activity_execution_size: 4
                worker_stop_timeout: '30 seconds'
                workflow_panic_policy: FailWorkflow
```

- Keys are the `Temporal\Worker\WorkerOptions` properties in snake_case — `bin/console config:dump-reference fluffy_discord_road_runner` lists them.
- Durations take seconds or a duration string. Enums take the case name.

## 6. Observability

### Logs

Channel: `temporal`. In workflow code log via `Workflow::getLogger()`.

Filter any log by `extra.temporal.workflowId` to follow one run.

```yaml
monolog:
    handlers:
        temporal:
            type: stream
            path: '%kernel.logs_dir%/temporal.log'
            channels: [temporal]
```

### Status and progress

```php
$this->launcher->of(IngestWorkflowInterface::class)
    ->id('ingest-' . $documentId)
    ->searchAttributes(['WebsiteId' => $websiteId])
    ->start($documentId);
```

```php
$runs = $this->workflowClient->listWorkflowExecutions(
    "WorkflowType = 'IngestWorkflow' AND WebsiteId = 'site-1' AND ExecutionStatus = 'Failed'",
);
```

- Register custom attributes on the server first: `temporal operator search-attribute create --name WebsiteId --type Keyword`.
- Progress from inside the workflow: `Workflow::upsertTypedSearchAttributes(SearchAttributeKey::forInteger('ProcessedChunks')->valueSet($count))`.

### OpenTelemetry

```bash
composer require temporal/open-telemetry-interceptors
```

Configure the exporter with the standard OpenTelemetry env vars (`OTEL_PHP_AUTOLOAD_ENABLED=true`, `OTEL_EXPORTER_OTLP_ENDPOINT`, …).

Own tracer provider? Override the `Temporal\OpenTelemetry\Tracer` service.

Drop one of the interceptors:

```yaml
services:
    Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowOutboundRequestInterceptor:
        autoconfigure: false
        arguments: ['@Temporal\OpenTelemetry\Tracer']
```

> `OpenTelemetryWorkflowOutboundRequestInterceptor` exports spans synchronously. Run a local collector.

### Interceptors

Implement a Temporal SDK interceptor interface:

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

Order: `#[AsTaggedItem(priority: 10)]` — higher runs outermost.

### Events

Only need to read or change a call's input? Listen to an event:

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

Namespaces: `…\Temporal\Interceptor\Event\{WorkflowClient, WorkflowInboundCalls, WorkflowOutboundCalls, ActivityInbound}`.

> Workflow-side listeners run inside deterministic workflow code: no I/O, no clock, no randomness.

### Correlation id

`temporal.tracing: true` — propagates `X-Request-Id` to started workflows as `x-correlation-id`.

### Live workers

```php
$worker = $this->temporalWorkerRegistry->get('billing');   // Temporal\Worker\WorkerInterface|null, worker process only
```

## 7. `debug:temporal`

```bash
bin/console debug:temporal
bin/console debug:temporal --format=json
bin/console debug:temporal --format=mermaid > flow.mmd
```

## 8. Customizing

- **Data converter:**

  ```yaml
  services:
      Temporal\DataConverter\DataConverterInterface: '@App\Temporal\MyDataConverter'
  ```

- **Worker factory** — point `Temporal\Worker\WorkerFactoryInterface` at your subclass of `Temporal\WorkerFactory`.
- **Introspection** — decorate or replace `TemporalIntrospectorInterface`.
