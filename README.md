# RoadRunner Runtime for Symfony

Yet another runtime for Symfony and [RoadRunner](https://roadrunner.dev/).

DDEV users: see [DDEV add-on](#ddev-add-on).

## Features

- [HTTP worker](#usage) — service reset runs *after* the response, off the request path
- [Worker warmup](#worker-warmup) — zero-config; first request at steady-state speed
- [Streaming](#responsefile-streaming) — `StreamedResponse`, `StreamedJsonResponse`, `BinaryFileResponse`
- [Early Hints (103)](#early-hints-103)
- [Graceful error handling](#error-handling) — real HTTP responses for `die()`/`exit()`/fatals
- [Sentry](#sentry) & [Monolog](#monolog)
- [Centrifugo](#centrifugo-websockets) — `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`
- [Jobs / queues](#jobs-queues) + [typed message bus](#message-bus-messenger-style) on Symfony Messenger
- [Key-Value cache](#configuration) — `cache.adapter.rr_kv.*`
- [Distributed locks](#distributed-locks) — Symfony `LockFactory` over RR's Lock plugin
- [Temporal](#temporal-beta) (beta) — [usage guide](docs/temporal.md)
- [PostgreSQL preconnect](#database-connections)

## Installation

```shell
composer require fluffydiscord/roadrunner-symfony-bundle
```

## Usage

1. `.rr.yaml`:

```yaml
server:
    env:
        APP_RUNTIME: FluffyDiscord\RoadRunnerBundle\Runtime\Runtime

rpc:
    listen: tcp://127.0.0.1:6001
```

`.env` — `RR_RPC` must match `rpc.listen`:

```dotenv
RR_RPC=tcp://127.0.0.1:6001
```

2. Swap the kernel trait:

```diff
- use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
+ use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;

class Kernel extends BaseKernel
{
-    use MicroKernelTrait;
+    use RoadRunnerMicroKernelTrait;
}
```

### Service reset

|               | New request arrives                          | Your app                | After the response is sent                    |
| ------------- | -------------------------------------------- | ----------------------- | --------------------------------------------- |
| Stock Symfony | resets services first (on the request path)  | handled after reset     | nothing                                       |
| This bundle   | container already warm                       | handled immediately     | `terminate()`, then `services_resetter` reset |

> Non-shared services (`shared: false`) are **not** reset before Symfony 8.1, even with `ResetInterface` — `services_resetter` resets a throwaway instance. Fixed in 8.1.

#### Database connections

- **PostgreSQL** — connections opened at worker boot (`doctrine.preconnect`). Native `pgsql` driver: every worker opens its own socket (no persistent-connection support). PDO driver: a `persistent` connection is additionally reused across worker spawns.
- **MySQL / MariaDB** — preconnect skips them; listen to `WorkerRequestReceivedEvent` and reset your connections.

> The ORM identity map is cleared for you (`doctrine` registry implements `ResetInterface`). The above concerns the DBAL **connection**.

## Configuration

`fluffy_discord_road_runner.yaml`

```yaml
fluffy_discord_road_runner:
  rr_config_path: ".rr.yaml"

  http:
    lazy_boot: false
    request_factory: auto

  warmup:
    enabled: true
    learn: true
    learn_requests: 30
    manifest_path: null

  centrifugo:
    lazy_boot: false

  jobs:
    lazy_boot: false
    serializer: ~
    default_queue: "default"
    bus: ~

  doctrine:
    preconnect: true

  kv:
    auto_register: true
    serializer: null
    keypair_path: bin/keypair.key
```

| Option | Default | Meaning |
|---|---|---|
| `rr_config_path` | `.rr.yaml` | Path to RR config, relative to `kernel.project_dir`. Lets `cache:warmup` run without RR running (Docker builds). |
| `*.lazy_boot` | `false` | `false` = boot kernel before first request (slower worker ready, consistent response times). `true` = boot on first request (instant ready, boot-time spikes). Many workers → `true`, or boot a few workers + [dynamic scaling](https://docs.roadrunner.dev/php-worker/scaling). |
| `http.request_factory` | `auto` | `native` = build the Symfony Request directly, ~halves conversion cost. `psr7` = PSR-7 then `symfony/psr-http-message-bridge`; use with a custom `HttpFoundationFactoryInterface` service (picked up automatically). `auto` = `psr7` when such a service exists, else `native`. |
| `warmup.enabled` | `true` | Master switch for the warmup runner, built-in warmers and the recorder. See [Worker warmup](#worker-warmup). |
| `warmup.learn` | `true` | Record which classes and cache files real responses load, replay them at every later worker boot. Covers only routes visited while learning. |
| `warmup.learn_requests` | `30` | Stop recording after this many responses per worker process. |
| `warmup.manifest_path` | `null` | `null` = `<kernel.cache_dir>/roadrunner/warmup.manifest.json`. Point outside the cache dir to persist learning across deploys; self-invalidates when the container build id changes. |
| `doctrine.preconnect` | `true` | Opens PostgreSQL connections at worker boot; other drivers ignored; runs on every boot regardless of `lazy_boot`. Needs `doctrine/dbal`. |
| `kv.auto_register` | `true` | Registers every `kv` adapter from `.rr.yaml` as `cache.adapter.rr_kv.NAME`. |
| `kv.serializer` | `null` | `IgbinarySerializer` when the `igbinary` extension is present, else `DefaultSerializer`. Custom: implement `Spiral\RoadRunner\KeyValue\Serializer\SerializerInterface`. |
| `kv.keypair_path` | — | Relative path to a keypair for [end-to-end encryption](https://docs.roadrunner.dev/key-value/overview-kv#end-to-end-value-encryption). Needs `sodium`. |

Each section activates only with its package installed:

| Section | Package |
|---|---|
| `centrifugo` | `roadrunner-php/centrifugo` |
| `jobs` | `spiral/roadrunner-jobs` |
| `kv` | `spiral/roadrunner-kv` |
| `doctrine` | `doctrine/dbal` |
| `temporal` | `temporal/sdk` — options in [`docs/temporal.md`](docs/temporal.md) |

## Behind a load balancer / reverse proxy

Use `private_ranges` instead of `REMOTE_ADDR` as [trusted proxy](https://symfony.com/doc/current/deployment/proxies.html#solution-settrustedproxies). The `REMOTE_ADDR` placeholder is resolved from `$_SERVER` at container build time, where no request exists yet, so trusted headers won't work. The per-request client IP is on the `Request` (`$request->server->get('REMOTE_ADDR')`), never in `$_SERVER`.

## Response/file streaming

`BinaryFileResponse`, `StreamedResponse`, `StreamedJsonResponse` are fully supported. Although streamed callbacks must return a `\Generator` — replace `echo` with `yield`:

```diff
 return new StreamedResponse(
-    function (): void {
-        echo "data";
+    function (): \Generator {
+        yield "data";
     }
 );
```

## Early Hints (103)

`sendEarlyHints()` works out of the box via a `headers_send()` polyfill. See [Symfony docs](https://symfony.com/doc/current/web_link.html#early-hints).

- Headers already emitted in a `103` frame are not repeated in the final response.
- The RR protocol can only *add* headers — no `header_remove()` equivalent. A header whose **value changes** after the `103` reaches the client with both values. Send hints on the response you return: `sendEarlyHints($links, $response)`.
- With `kernel.debug` on, the worker writes a STDERR line naming any affected header.

## Error handling

| Failure | dev (`kernel.debug`) | prod |
|---|---|---|
| exception in your code | Symfony's exception page | Symfony's error page |
| exception escaping Symfony | `HtmlErrorRenderer` page | bare `500`, empty body |
| `die()` / `exit()` / fatal | built-in minimal error page | bare `500`, empty body |

- `die()`/`exit()`/fatals are answered best-effort by a shutdown handler.
- Details go to STDERR (RR worker logs) and Sentry if installed, never `stdout` (goridge channel).
- Dev page names where the last `dump()`/`dd()` ran (`file:line`, hyperlinked via `framework.ide`) and shows the dump — unless a dump server (Buggregator / `debug.dump_destination`) is configured, which receives it instead. Needs `symfony/var-dumper`; never active in prod.

Not covered:

- true out-of-memory — Symfony's fatal handler can trip RR's `stdout` CRC check first
- an already-streaming response — never patched with a second frame
- `SIGKILL`, segfault, stack overflow — PHP shutdown never runs

Best dev experience: socket relay (`RR_RELAY=tcp://…`/`unix://…`) or `http.pool.debug: true`.

## Sentry

```shell
composer require sentry/sentry-symfony
```

Configure as usual.

## Monolog

Avoid the [`fingers_crossed`](https://symfony.com/doc/current/logging.html#logging-handler-fingers_crossed) handler — it [leaks memory by design](https://symfony.com/doc/current/messenger.html#stateless-worker). It still mostly works here because `ServiceResetter` runs after each response, but logs may be missing after a hard error.

## Centrifugo (websockets)

```shell
composer require roadrunner-php/centrifugo
```

Listen to any event implementing `FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface`:

- `ConnectEvent` (required)
- `InvalidEvent`
- `PublishEvent`
- `RefreshEvent`
- `RPCEvent`
- `SubRefreshEvent`
- `SubscribeEvent`

```php
#[AsEventListener(event: ConnectEvent::class, method: "handleConnect")]
readonly class ChatListener
{
    public function handleConnect(ConnectEvent $event): void
    {
        $request = $event->getRequest();
        $authToken = $request->getData()["authToken"] ?? null;
        $user = ...

        $event->stopPropagation();

        $event->setResponse(new ConnectResponse(
            user: $user->getId(),
            data: ["messages" => ...],
        ));
    }
}
```

No response set → `DisconnectResponse` is sent.

### `#[AsCentrifugoChannelListener]`

Routes `PublishEvent`, `SubscribeEvent`, `SubRefreshEvent`, `ConnectEvent` by channel name. `*` = wildcard.

```php
class ChatListener
{
    #[AsCentrifugoChannelListener(channel: 'news')]
    public function onNewsPublish(PublishEvent $event): void {}

    #[AsCentrifugoChannelListener(channel: 'chat:*', priority: 10)]
    public function onChatSubscribe(SubscribeEvent $event): void
    {
        $channel = $event->getRequest()->channel;
    }
}
```

On a **class**, `event` and `method` are required:

```php
#[AsCentrifugoChannelListener(channel: 'private:*', event: PublishEvent::class, method: 'handle')]
class PrivateChannelHandler
{
    public function handle(PublishEvent $event): void { ... }
}
```

| Parameter  | Type      | Default      | Description |
|------------|-----------|--------------|-------------|
| `channel`  | `string`  | *(required)* | Exact name or `*` pattern (`chat:*`) |
| `event`    | `?string` | `null`       | Event FQCN; inferred from the first parameter type hint on methods |
| `priority` | `int`     | `0`          | Higher = called first within the matched channel |
| `method`   | `?string` | `null`       | Auto-detected when placed on a method |

### `#[AsCentrifugoRpcListener]`

Routes `RPCEvent` by RPC method name.

```php
class RpcHandler
{
    #[AsCentrifugoRpcListener(rpcMethod: 'ping')]
    public function onPing(RPCEvent $event): void
    {
        $event->setResponse(new RPCResponse(data: ['pong' => true]));
    }
}
```

| Parameter   | Type      | Default      | Description |
|-------------|-----------|--------------|-------------|
| `rpcMethod` | `string`  | *(required)* | Matched against `RPCEvent::getRequest()->method` |
| `priority`  | `int`     | `0`          | Higher = called first |
| `method`    | `?string` | `null`       | Auto-detected when placed on a method |

Routing table is built at container compile time — one hash-map lookup per request. Handlers run in priority order and respect `stopPropagation()`. Routing listeners fire at priority `-100`, after plain `#[AsEventListener]` handlers at `0`.

## Jobs (queues)

```shell
composer require spiral/roadrunner-jobs
```

`.rr.yaml`:

```yaml
jobs:
  pool:
    num_workers: 4
  pipelines:
    emails:
      driver: memory
      config:
        priority: 10
  consume: ["emails"]
```

`JobsRunEvent` is dispatched once per consumed task:

```php
#[AsEventListener(event: JobsRunEvent::class, method: "onJob")]
final class JobListener
{
    public function onJob(JobsRunEvent $event): void
    {
        $event->getName();      // job name
        $event->getQueue();     // broker queue name
        $event->getPipeline();  // RoadRunner pipeline name
        $event->getId();        // task id
        $event->getHeaders();   // array<string, string[]>

        $data = json_decode($event->getPayload(), true); // raw string — you own the format
    }
}
```

Ack / nack:

- Listener returns normally → **ack**.
- Listener throws `\Throwable` → **nack with requeue** (`redelivery: true`) + error logged to STDERR / Sentry. A hard `\Error` also stops the worker (RR respawns it).
- Worker dies mid-task (`die`/`exit`/fatal) → shutdown handler best-effort requeues.
- A listener that takes the task (`$event->getTask()->ack()`/`nack()`/`requeue()`) is respected; the worker won't respond twice.

> **Poison messages:** the default is requeue, so an always-throwing task is redelivered indefinitely. Catch inside the listener and ack-and-drop, or `nack($e, redelivery: false)` yourself.

### Message bus (Messenger-style)

Optional typed layer: dispatch a plain PHP object, handle it with a standard `#[AsMessageHandler]`. Purely additive — raw `JobsRunEvent` and RR Jobs services keep working, and a task this layer did not produce is left to your raw listeners.

```shell
composer require symfony/messenger
```

Serialization: **igbinary** when the extension is present, otherwise **Native** (`serialize()`/`unserialize()`, handles any serializable object incl. private state). For JSON:

```shell
# only for jobs.serializer: symfony
composer require symfony/serializer symfony/property-access
```

> The strategy comes from `jobs.serializer` (`igbinary` / `native` / `symfony`) and is recorded in the task's `x-job-serializer` header so the consumer decodes with the same one. `symfony` without `symfony/serializer` throws a clear error.

```php
use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'emails', delay: 0, priority: 10)]
final class SendWelcomeEmail
{
    public function __construct(public string $email) {}
}
```

Dispatch via the public `JobDispatcher`; explicit arguments override the attribute:

```php
public function __construct(private JobDispatcher $jobs) {}

$this->jobs->dispatch(new SendWelcomeEmail('a@b.test'));
$this->jobs->dispatch(new SendWelcomeEmail('a@b.test'), queue: 'priority', delay: 30, priority: 5);
```

```php
#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __invoke(SendWelcomeEmail $message): void {}
}
```

Everything `#[AsMessageHandler]` supports applies — priorities, multiple handlers, named methods, `debug:messenger`. Consumed jobs arrive on the Messenger transport `roadrunner`; scope with `#[AsMessageHandler(fromTransport: 'roadrunner')]`.

Need the RR task (headers, manual ack/nack/requeue)? Add a `ReceivedTaskInterface` parameter:

```php
#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __invoke(SendWelcomeEmail $message, ReceivedTaskInterface $task): void
    {
        // $task->getHeaders(); $task->withDelay(30)->requeue(...); $task->nack($e, redelivery: false);
    }
}
```

Ack / nack matches the raw listener: all handlers return → **ack**; a handler throws → **nack with requeue** + STDERR / Sentry log; **no** registered handler → logged and acked as a no-op. The poison-message caveat applies equally.

```yaml
fluffy_discord_road_runner:
  jobs:
    serializer: ~              # "igbinary" if the extension is present, else "native"; or "symfony" (JSON)
    default_queue: "default"   # used when no dispatch() argument and no #[AsJob(queue:)]; pipeline must exist in .rr.yaml
    bus: ~                     # Messenger bus service id (default: application's default bus)
```

> **Wire format** (`x-job-class` / `x-job-serializer` headers, message FQCN as the RR job name) is a stable contract — changing it breaks in-flight queued tasks across an upgrade (`docs/specs/jobs-message-bus.md`).

## Worker warmup

A fresh worker's first request is several times slower than steady state; `opcache.preload` is a no-op in `cli` workers. The bundle warms during worker boot, before RR marks the worker ready. Zero config:

1. **Generic warmers** — router, Doctrine metadata, event listeners, form types, Twig runtimes, container preload class list. Missing dependencies are skipped.
2. **Learned manifest** — workers record what real traffic loads (`<kernel.cache_dir>/roadrunner/warmup.manifest.json`); every next worker replays it at boot. Invalidated when the container is rebuilt.

Measured on a production Sylius app: first request 252 ms → 41 ms (steady state 33–43 ms).

Development (`http.pool.debug: true`): warmup and learning switch themselves off — one process per request keeps nothing warm. Gate is `kernel.runtime_mode.worker`, derived from `pool.debug` in `.rr.yaml`.

Production expectations:

- `display_errors=0` — warnings on stdout corrupt the worker protocol.
- `opcache.file_cache=/some/dir` shares bytecode across workers (boot ~600 ms → ~200 ms). Adapted to automatically.
- `warmup.learn_requests` (default 30) — responses recorded per worker.
- `warmup.manifest_path` outside the cache dir keeps learning across deploys. 

Warmed classes live in each worker's opcache — budget `opcache.memory_consumption` × worker count.

### Warming your own services

```php
use FluffyDiscord\RoadRunnerBundle\Warmup\WorkerWarmerInterface;

class MyCacheWarmer implements WorkerWarmerInterface
{
    public function __construct(private readonly MyExpensiveService $service)
    {
    }

    public function warmup(): void
    {
        $this->service->buildInMemoryIndexes();
    }
}
```

Autoconfigured. Or listen to `WorkerBootingEvent`. A throwing warmer is logged and skipped in production.

### Cold cache in dev

```bash
php bin/console cache:warmup
rr serve
```
Suggested, not required. Sidesteps upstream Symfony bug: [symfony/symfony#65447](https://github.com/symfony/symfony/issues/65447)

- Dev only (`APP_DEBUG=1`); prod and any warm cache are unaffected.
- Concurrent cold-cache boots corrupt `<Container>Deprecations.log` → workers die at boot and respawn, requests hang instead of erroring.

## Distributed locks

Optional. Symfony `LockFactory` backed by RR's Lock plugin over the same RPC, no extra config:

```shell
composer require roadrunner-php/symfony-lock-driver
```

Add a `lock` section to `.rr.yaml`, then autowire `LockFactory` (or `PersistingStoreInterface`):

```php
public function __construct(private LockFactory $locks) {}

$lock = $this->locks->createLock('report-generation');
if ($lock->acquire()) { /* ... */ $lock->release(); }
```

## Temporal (beta)

> [!WARNING]
> Beta. Flow and implementation may still change; expect breaking changes until the API settles.

```bash
composer require temporal/sdk
```

Activates automatically. Assign workflows/activities to a worker's task queue with `#[TaskQueue]`, run them under RR's `temporal` plugin, react to interceptor calls via Symfony events. A profiler tab lists registered workers, workflows and activities.

**→ [`docs/temporal.md`](docs/temporal.md)** — defining activities/workflows, configuration, starting a workflow, interceptor events.

## Developing with Symfony and RoadRunner

- Drop lazy loading; inject services immediately. Lazy services can leak memory and slow framework initialization when requests arrive.
- No local class/array caches in services — stay stateless or implement [`ResetInterface`](https://github.com/symfony/contracts/blob/main/Service/ResetInterface.php). Mind the [`shared: false` caveat](#service-reset).
- Forms can leak data across requests — see [OptionsResolver](#optionsresolver-forms).
- Simplify `User` session serialization with `EquatableInterface` + custom de/serialization — avoids detached Doctrine entities and speeds up loading the user from the session.

```php
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $password = null;

    public function __serialize(): array
    {
        return [
            "id"       => $this->id,
            "email"    => $this->email,
            "password" => $this->password,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data["id"] ?? null;
        $this->email = $data["email"] ?? null;
        $this->password = $data["password"] ?? null;
    }

    public function isEqualTo(mixed $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->id === $user->getId()
            &&
            $this->password === $user->getPassword()
            &&
            $this->email === $user->getEmail()
        ;
    }
}
```

### OptionsResolver (Forms)

`OptionsResolver::setDefaults()` is cached — it resolves once per worker, on first use. Dynamic defaults leak across requests and sessions:

```php
// leaks the first user's email to every later request of that worker
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        "label" => $this->security->getUser()->getEmail(),
    ]);
}
```

Keep defaults static; pass dynamic values at form creation:

```php
$correctForm = $this->createForm(MyType::class, options: [
    "label" => $this->getUser()->getEmail(),
]);
```

## Debugging

- `dd()` works in dev — the [rescue page](#error-handling) names the `file:line` it ran on and shows the dump. Needs `symfony/var-dumper`.
- `dump()` on a *successful* response is still invisible: RoadRunner re-streams the output buffer to STDERR, so the HTML lands escaped in the worker log.
- A dump server takes both cases over TCP — the rescue page then shows the location only and forwards the dump. [Buggregator](https://docs.buggregator.dev/config/var-dumper.html) (or any `VAR_DUMPER_SERVER`) also serves as a [mailtrap](https://docs.buggregator.dev/config/smtp.html) and a local [Sentry](https://docs.buggregator.dev/config/sentry.html).

## DDEV add-on

```shell
ddev add-on get FluffyDiscord/ddev-roadrunner-symfony
```

See the [add-on repository](https://github.com/FluffyDiscord/ddev-roadrunner-symfony) for configuration and usage.

## Credits

Inspiration taken from [Baldinof's Bundle](https://github.com/Baldinof/roadrunner-bundle) and [Nyholm's Runtime](https://github.com/php-runtime/roadrunner-symfony-nyholm).
