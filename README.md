# RoadRunner Runtime for Symfony

Yet another runtime for Symfony and [RoadRunner](https://roadrunner.dev/).

## Features

- [HTTP worker](#usage) — drop-in runtime; service reset runs *after* the response, off the request's critical path
- [Response & file streaming](#responsefile-streaming) — `StreamedResponse`, `StreamedJsonResponse`, `BinaryFileResponse`
- [Early Hints (103)](#early-hints-103)
- [Graceful error handling](#error-handling) — proper HTTP responses for `die()`/`exit()`/fatals
- [Sentry](#sentry) & [Monolog](#monolog) integration
- [Centrifugo (websockets)](#centrifugo-websockets) — `#[AsCentrifugoChannelListener]` / `#[AsCentrifugoRpcListener]`
- [Jobs / queues](#jobs-queues) + [typed message bus](#message-bus-dispatch-typed-messages-messenger-style) — dispatch plain objects, handle them with standard Symfony Messenger `#[AsMessageHandler]`s
- [Key-Value cache](#configuration) — auto-registered `cache.adapter.rr_kv.*` adapters
- [Distributed locks](#distributed-locks-symfonylock) — Symfony `LockFactory` over RR's Lock plugin
- [Temporal](#temporal-beta-test) (beta) — workflows & activities, see the [usage guide](docs/temporal.md)
- [PostgreSQL preconnect](#database-connections) — opens PostgreSQL Doctrine connections at worker boot so the first request skips the connection handshake

## Installation

```shell
composer require fluffydiscord/roadrunner-symfony-bundle
```

## Usage

1. Define the environment variable `APP_RUNTIME` in `.rr.yaml` and set up `rpc` plugin:

`.rr.yaml`
```yaml
server:
    env:
        APP_RUNTIME: FluffyDiscord\RoadRunnerBundle\Runtime\Runtime

rpc:
    listen: tcp://127.0.0.1:6001
```

Don't forget to add the `RR_RPC` to your `.env` — it **must match** the `rpc.listen` address in `.rr.yaml`:

```dotenv
RR_RPC=tcp://127.0.0.1:6001
```

> **Starter config:** the bundle ships a fuller, commented `.rr.yaml` (gzip/static middleware, dev
> `pool.debug`, logging) at [`install/.rr.yaml`](install/.rr.yaml). After installing, copy it to your
> project root and tweak it instead of writing one by hand:
> ```shell
> cp vendor/fluffydiscord/roadrunner-symfony-bundle/install/.rr.yaml .rr.yaml
> ```
> There is no Symfony Flex recipe yet, so the `.rr.yaml`, the `RR_RPC` line, and the kernel-trait swap
> (step 2 below) are all manual.

2. Replace `MicroKernelTrait` with `RoadRunnerMicroKernelTrait` in your `Kernel.php`:

```diff
<?php

namespace App\Kernel;

- use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
+ use FluffyDiscord\RoadRunnerBundle\Kernel\RoadRunnerMicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
-    use MicroKernelTrait;
+    use RoadRunnerMicroKernelTrait;
}
```

### Service reset

|               | New request arrives                                  | Your app                          | After the response is sent                    |
| ------------- | ---------------------------------------------------- | --------------------------------- | --------------------------------------------- |
| Stock Symfony | resets services first (reset is on the request path) | handled only after reset finishes | does nothing                                  |
| This bundle   | container already warm, handed straight to your app  | handled immediately               | `terminate()`, then `services_resetter` reset |

> ⚠️ **Non‑shared services (`shared: false`).** Before Symfony 8.1 these are **not** reset even with
> `ResetInterface` — `services_resetter` builds its own throwaway instance instead of resetting the
> ones your app used. Starting Symfony 8.1, it's fixed.

#### Database connections

- **PostgreSQL** — connections are opened at worker boot for you (see `doctrine.preconnect` in
  [Configuration](#configuration)), so the first request skips the connection handshake. With the
  native `pgsql` driver every worker always opens its own socket (it has no persistent-connection
  support); with the PDO driver a `persistent` connection can additionally be reused across worker
  spawns. Either way, preconnect warms the socket before the first request.
- **MySQL / MariaDB** — listen to `WorkerRequestReceivedEvent` and reset your database connections
  (preconnect intentionally skips non-PostgreSQL drivers).

> The Doctrine ORM `EntityManager` identity map is cleared for you: the `doctrine` registry implements
> `ResetInterface`, so `services_resetter` clears it between requests. The bullets above concern the
> underlying DBAL **connection** (stale/dropped sockets), not the identity map.

## Configuration

`fluffy_discord_road_runner.yaml`
```yaml
fluffy_discord_road_runner:
  # Specify relative path from "kernel.project_dir"
  # to your RoadRunner config file if you want to
  # run cache:warmup without having your RoadRunner
  # running in background, e.g. when building Docker images.
  rr_config_path: ".rr.yaml"
    
  # Http worker
  # https://docs.roadrunner.dev/http/http
  http:
    # This decides when to boot the Symfony kernel.
    #
    # false (default) - before first request (worker takes some time
    # to be ready, but app has consistent response times)
    # true - once first request arrives (worker is ready immediately,
    # but inconsistent response times due to kernel boot time spikes)
    #
    # If you use large amount of workers, you might want to set this
    # to true or else the RR boot up might take a lot of time
    # or just boot up using only a few "emergency" workers
    # and then use dynamic worker scaling as described here
    # https://docs.roadrunner.dev/php-worker/scaling
    lazy_boot: false

    # This decides if Symfony routing should be preloaded
    # when worker starts and boots Symfony kernel.
    #
    # This option halves the initial request response time.
    # (based on a project with over 400 routes
    # and quite a lot of services, YMMW)
    #
    # true - sends one dummy (empty) HTTP request
    # for kernel to initialize routing and services around it
    #
    # false - only when first request arrives
    # routing and it's services are loaded
    #
    # You might want to create a dummy "/"
    # route for the route to "land",
    # or listen to onKernelRequest events
    # and look in the request for the attribute
    # FluffyDiscord\RoadRunnerBundle\Worker\HttpWorker::DUMMY_REQUEST_ATTRIBUTE
    early_router_initialization: false

  # Centrifugo (websockets)
  # Will activate only when "roadrunner-php/centrifugo" is installed.
  # https://docs.roadrunner.dev/plugins/centrifuge
  centrifugo:
    # See http section,
    # behaves the same way.
    lazy_boot: false

  # Jobs (queue consumer)
  # Will activate only when "spiral/roadrunner-jobs" is installed.
  # https://docs.roadrunner.dev/queues-and-jobs/overview-queues
  jobs:
    # See http section,
    # behaves the same way.
    lazy_boot: false

  # Doctrine
  # Will activate only when "doctrine/dbal" is installed.
  doctrine:
    # Open PostgreSQL connections at worker boot, before the
    # first request, so the first request skips the PostgreSQL
    # connection handshake. Only PostgreSQL connections are
    # touched; other drivers are ignored. Runs on every worker
    # boot regardless of "lazy_boot". Set false to opt out
    # (no listener is registered).
    preconnect: true

  # Key-Value storage
  # Will activate only when "spiral/roadrunner-kv" is installed.
  # https://docs.roadrunner.dev/key-value/overview-kv
  kv:
    # If true, bundle will automatically register
    # all "kv" adapters in your .rr.yaml.
    # Registered services have alias "cache.adapter.rr_kv.NAME"
    auto_register: true

    # Which data serializer should be used.
    #
    # By default, "IgbinarySerializer" will be used
    # if "igbinary" php extension
    # is installed, otherwise "DefaultSerializer".
    #
    # You are free to create your own serializer.
    # It needs to implement
    # Spiral\RoadRunner\KeyValue\Serializer\SerializerInterface
    serializer: null

    # Specify relative path from "kernel.project_dir"
    # to a keypair file for end-to-end encryption.
    # "sodium" php extension is required.
    # https://docs.roadrunner.dev/key-value/overview-kv#end-to-end-value-encryption
    keypair_path: bin/keypair.key

```


## Running behind a load balancer/reverse proxy
If you want to use `REMOTE_ADDR` as [trusted proxy](https://symfony.com/doc/current/deployment/proxies.html#solution-settrustedproxies), replace it with `private_ranges` instead 
or else your trusted headers will not work.

Symfony is using the `$_SERVER['REMOTE_ADDR']` to find out the proxy address,
but in the context of RoadRunner, `$_SERVER` contains only environment 
variables and the `REMOTE_ADDS` is missing. This is intentional.


## Response/file streaming

Build-in support for Symfony's `BinaryFileResponse`, `StreamedResponse` and `StreamedJsonResponse`. Stream responses need one little 
change to be fully streamable - you have to change their `callback` to a `\Generator` and replace all `echo` with `yield`. Look at the example:

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Route("/stream")]
class MyStreamController
{
    public function __invoke() 
    {
        return new StreamedResponse(
            function (): \Generator {
                // replace all 'echo' or any outputs with 'yield'
                // echo "data";
                yield "data";
            }
        );
    }
}
```

## Early Hints (103)

Symfony's `sendEarlyHints()` works out of the box by adding `headers_send()` polyfill that Franken SAPI exposes.

More info at [Symfony docs](https://symfony.com/doc/current/web_link.html#early-hints)

## Error handling

The HTTP worker turns worker-level failures into proper HTTP responses instead of leaking a raw
RoadRunner error to the client. Behaviour depends on `kernel.debug`:

| Failure | `kernel.debug = true` (dev) | `kernel.debug = false` (prod) |
|---------|-----------------------------|-------------------------------|
| A normal exception thrown in your code | Symfony's standard exception page (Symfony handles it; the worker forwards the response) | Symfony's standard error page |
| An exception that escapes Symfony's handling | Symfony's `HtmlErrorRenderer` debug page | bare `500`, empty body |
| **`die()` / `exit()` / a fatal error** (e.g. `OutOfMemoryError`, timeout) | a small built‑in HTML error page | bare `500`, empty body |

The last row is the important one: `die()`, `exit()` and fatal errors *cannot* be caught with
`try/catch`, so without this the worker would simply vanish and the client would get RoadRunner's
internal error. The bundle registers a shutdown handler that, on a best‑effort basis, still sends a
response. The full throwable / fatal is always written to **STDERR** (which RoadRunner records as
worker logs) and reported to Sentry if configured — never echoed to `stdout` (in `pipes` relay mode
`stdout` *is* the protocol channel, which is why you must never `dump()`‑and‑`die()`).

Known limits (all best‑effort, by nature of a dying process):

- A genuine **out‑of‑memory** fatal may not produce the page: Symfony's own error handler can write
  to the protocol stream first and trip RoadRunner's `stdout` CRC check. The worker is respawned and
  the request fails — the fatal is still logged.
- A response that has **already started streaming** (a `StreamedResponse`, or after `103` early
  hints have begun the body) is never patched with a second frame — that would corrupt the stream.
- `SIGKILL`, segfaults and stack overflows skip PHP shutdown entirely and cannot be handled.

For the richest dev experience with `die()`/`exit()`, use a socket relay (`RR_RELAY=tcp://…`/`unix://…`)
or keep `http.pool.debug: true` (one worker per request) in development.

## Sentry

Built in support for [Sentry](https://packagist.org/packages/sentry/sentry-symfony). Just install & configure it as you normally do.

```shell
composer require sentry/sentry-symfony
```

## Monolog

If possible, [do not use fingers_crossed](https://symfony.com/doc/current/logging.html#logging-handler-fingers_crossed) handler. It is made to [leak memory by design](https://symfony.com/doc/current/messenger.html#stateless-worker).
Nevertheless, this bundle is still somewhat compatible with it due to calling `ServiceResetter` after each response. If you encounter hard error,
your logs might be missing though. Nothing to be done there.

```shell
composer require sentry/sentry-symfony
```

## Centrifugo (websockets)

To enable [Centrifugo](https://github.com/centrifugal/centrifugo) you need to add `roadrunner-php/centrifugo` package.

```shell
composer require roadrunner-php/centrifugo
```

Bundle is using Symfony's Event dispatcher. You can create [event listener](https://symfony.com/doc/current/event_dispatcher.html#creating-an-event-listener) for any event extending `FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\CentrifugoEventInterface`:
- `ConnectEvent` required :)
- `InvalidEvent`
- `PublishEvent`
- `RefreshEvent`
- `RPCEvent`
- `SubRefreshEvent`
- `SubscribeEvent`

Example usage:

```php
<?php

namespace App\EventListener;

use App\Centrifuge\Event\ConnectEvent;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConnectEvent::class, method: "handleConnect")]
readonly class ChatListener
{
    public function handleConnect(ConnectEvent $event): void
    {
        // original Centrifugo request passed from RoadRunner
        $request = $event->getRequest();
        
        // auth your user or whatever you want
        $authToken = $request->getData()["authToken"] ?? null;
        $user = ...

        // stop propagating to other listeners,
        // you have successfully connected your user
        $event->stopPropagation();

        // send response using the $event->setResponse($myResponse)
        $event->setResponse(new ConnectResponse(
            user: $user->getId(),
            data: [
                "messages" => ... // initial data client receives when connected
            ],
        ));
    }
}
```

Be aware that if you do not set any response, bundle will send `DisconnectResponse` back by default.

### Channel and RPC routing

Instead of writing a single listener and manually handle each event, you can use the dedicated routing attributes.

#### `#[AsCentrifugoChannelListener]`

Routes `PublishEvent`, `SubscribeEvent`, `SubRefreshEvent`, and `ConnectEvent` to specific methods based on the channel name. Supports `*` as a wildcard.

```php
<?php

namespace App\EventListener;

use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoChannelListener;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\PublishEvent;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\SubscribeEvent;

class ChatListener
{
    // Event is inferred from the method's type hint.
    // Only called for PublishEvent on channel "news".
    #[AsCentrifugoChannelListener(channel: 'news')]
    public function onNewsPublish(PublishEvent $event): void
    {
        // handle publish to the "news" channel
    }

    // Wildcard: matches "chat:general", "chat:room-42", etc.
    #[AsCentrifugoChannelListener(channel: 'chat:*', priority: 10)]
    public function onChatSubscribe(SubscribeEvent $event): void
    {
        $channel = $event->getRequest()->channel;
        // handle subscription to any "chat:*" channel
    }
}
```

When placed on the **class**, you must also specify `event` and `method`:

```php
#[AsCentrifugoChannelListener(channel: 'private:*', event: PublishEvent::class, method: 'handle')]
class PrivateChannelHandler
{
    public function handle(PublishEvent $event): void { ... }
}
```

**Parameters:**

| Parameter  | Type      | Default      | Description |
|------------|-----------|--------------|-------------|
| `channel`  | `string`  | *(required)* | Exact channel name or pattern with `*` wildcard (e.g. `chat:*`) |
| `event`    | `?string` | `null`       | Event class FQCN. Optional on methods — inferred from the first parameter type hint |
| `priority` | `int`     | `0`          | Higher = called first (within matched handlers for this channel) |
| `method`   | `?string` | `null`       | Method to call. Auto-detected when placed on a method |

#### `#[AsCentrifugoRpcListener]`

Routes `RPCEvent` to a specific method based on the RPC method name.

```php
<?php

namespace App\EventListener;

use FluffyDiscord\RoadRunnerBundle\Attribute\AsCentrifugoRpcListener;
use FluffyDiscord\RoadRunnerBundle\Event\Centrifugo\RPCEvent;
use RoadRunner\Centrifugo\Payload\RPCResponse;

class RpcHandler
{
    #[AsCentrifugoRpcListener(rpcMethod: 'ping')]
    public function onPing(RPCEvent $event): void
    {
        $event->setResponse(new RPCResponse(data: ['pong' => true]));
    }

    #[AsCentrifugoRpcListener(rpcMethod: 'getUserInfo')]
    public function onGetUserInfo(RPCEvent $event): void
    {
        $data = $event->getRequest()->getData();
        // ...
    }
}
```

**Parameters:**

| Parameter   | Type      | Default      | Description |
|-------------|-----------|--------------|-------------|
| `rpcMethod` | `string`  | *(required)* | Exact RPC method name (matched against `RPCEvent::getRequest()->method`) |
| `priority`  | `int`     | `0`          | Higher = called first |
| `method`    | `?string` | `null`       | Method to call. Auto-detected when placed on a method |

#### How it works

The routing table is built **at container compile time** — there is no runtime overhead beyond a single hash-map lookup per request. Handlers are dispatched in priority order and respect `stopPropagation()`. The routing listeners fire at priority `-100`, after any plain `#[AsEventListener]` handlers at default priority `0`.

## Jobs (queues)

To consume [RoadRunner Jobs](https://docs.roadrunner.dev/queues-and-jobs/overview-queues) (queue tasks) add the `spiral/roadrunner-jobs` package:

```shell
composer require spiral/roadrunner-jobs
```

Configure a `jobs` pool in your `.rr.yaml`, for example:

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

The bundle registers a Jobs worker under RoadRunner's `jobs` mode. Listen to a single `JobsRunEvent`, dispatched once per consumed task, with a normal `#[AsEventListener]`:

```php
<?php

namespace App\EventListener;

use FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs\JobsRunEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: JobsRunEvent::class, method: "onJob")]
final class JobListener
{
    public function onJob(JobsRunEvent $event): void
    {
        // metadata
        $event->getName();      // job name
        $event->getQueue();     // broker queue name
        $event->getPipeline();  // RoadRunner pipeline name
        $event->getId();        // task id
        $event->getHeaders();   // array<string, string[]>

        // your payload (raw string — you own the format)
        $data = json_decode($event->getPayload(), true);

        // ... process the task ...
    }
}
```

**Ack / nack semantics:**
- If your listener returns normally, the worker **acks** the task (it is removed from the queue).
- If your listener throws any `\Throwable`, the worker **nacks with requeue** (`redelivery: true`) so the task is retried, and logs the error to STDERR / Sentry. A hard `\Error` additionally stops the worker (RoadRunner respawns it).
- If the worker dies mid-task (`die`/`exit`/fatal), a shutdown handler best-effort requeues the task so it is not lost.

> **Poison-message caveat:** because the default for an unhandled failure is *requeue*, a task that always throws will be redelivered indefinitely. If a job can fail permanently, **catch the error inside your listener** and decide there (e.g. log + return normally to ack-and-drop, or take the task via `$event->getTask()` and call `->nack($e, redelivery: false)` yourself). A listener that takes ownership of the task (`getTask()->ack()`/`nack()`/`requeue()`) is respected — the worker will not respond a second time.

Like the other workers, `jobs` supports `lazy_boot` (see [Configuration](#configuration)); it defaults to `false`.

### Message bus (dispatch typed messages, Messenger-style)

On top of the raw `JobsRunEvent`, the bundle ships an optional typed layer built on **Symfony Messenger**: dispatch a **plain PHP object** to a queue and handle it with a standard `#[AsMessageHandler]` on the consumer side — no manual (de)serialization, and you reuse Messenger's routing, middleware, `debug:messenger` and profiler panel. The raw `JobsRunEvent` and RR Jobs services keep working unchanged; this layer is purely additive (a task it did not produce is left untouched for your raw listeners).

It activates once `symfony/messenger` is installed:

```shell
composer require symfony/messenger
```

Serialization works out of the box — by default the **igbinary** serializer is used when the `igbinary` extension is present, otherwise the zero-dependency **Native** serializer (PHP `serialize()`/`unserialize()`, which handles any serializable object including private state). For interoperable JSON payloads you can opt into the **Symfony Serializer** instead:

```shell
# optional — only needed for jobs.serializer: symfony
composer require symfony/serializer symfony/property-access
```

> The strategy is chosen by the `jobs.serializer` config (`igbinary` / `native` / `symfony`) and recorded in the task's `x-job-serializer` header so the consumer decodes with the same one. Selecting `symfony` without `symfony/serializer` installed throws a clear error.

Mark a message class with `#[AsJob]` (queue/delay/priority are optional defaults):

```php
use FluffyDiscord\RoadRunnerBundle\Job\Attribute\AsJob;

#[AsJob(queue: 'emails', delay: 0, priority: 10)]
final class SendWelcomeEmail
{
    public function __construct(public string $email) {}
}
```

Dispatch it with the `JobDispatcher` service (public; explicit arguments override the attribute defaults):

```php
use FluffyDiscord\RoadRunnerBundle\Job\JobDispatcher;

public function __construct(private JobDispatcher $jobs) {}

$this->jobs->dispatch(new SendWelcomeEmail('a@b.test'));
// or override per dispatch:
$this->jobs->dispatch(new SendWelcomeEmail('a@b.test'), queue: 'priority', delay: 30, priority: 5);
```

Handle it with a standard Symfony Messenger handler — `#[AsMessageHandler]` (the message class is inferred from the first parameter):

```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __invoke(SendWelcomeEmail $message): void
    {
        // ... send the email ...
    }
}
```

Everything `#[AsMessageHandler]` already supports applies — handler priority, multiple handlers per message, `__invoke` or a named method, and `php bin/console debug:messenger` to inspect the wiring. Consumed jobs arrive on the Messenger transport named `roadrunner`, so you can scope a handler with `#[AsMessageHandler(fromTransport: 'roadrunner')]` to tell RoadRunner jobs apart from messages you dispatch through Messenger normally.

**Need the RoadRunner task** (to read headers, or ack/nack/requeue manually)? Add a second `ReceivedTaskInterface` parameter — the bundle passes the consumed task to your handler:

```php
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendWelcomeEmailHandler
{
    public function __invoke(SendWelcomeEmail $message, ReceivedTaskInterface $task): void
    {
        // $task->getHeaders(); $task->withDelay(30)->requeue(...); $task->nack($e, redelivery: false); ...
    }
}
```

**Ack / nack semantics** match the raw listener: if every handler returns normally the task is **acked**; if a handler throws, the worker **nacks with requeue** (`redelivery: true`) and logs the error to STDERR / Sentry; a message with **no** registered handler is logged and acked as a no-op. The poison-message caveat from the raw section applies equally — an always-throwing handler is requeued indefinitely unless you catch the error, or take ownership of the task via the `ReceivedTaskInterface` above and `nack(..., redelivery: false)` yourself.

The serializer, default queue and target bus are configurable:

```yaml
fluffy_discord_road_runner:
  jobs:
    serializer: ~              # default: "igbinary" if the extension is present, else "native". Or "symfony" (JSON).
    default_queue: "default"   # used when neither a dispatch() argument nor #[AsJob(queue:)] is given; pipeline must exist in your .rr.yaml
    bus: ~                     # service id of the Messenger bus to dispatch into (default: the application's default bus)
```

> **Wire-format note:** the envelope (`x-job-class` / `x-job-serializer` headers, message FQCN as the RR job name) is a stable contract — changing it would break in-flight queued tasks across an upgrade (`docs/specs/jobs-message-bus.md`).

## Distributed locks (symfony/lock)

Optional. Install the bridge and you get a Symfony `LockFactory` backed by RoadRunner's Lock plugin over the same RPC connection — no extra config:

```shell
composer require roadrunner-php/symfony-lock-driver
```

Add a `lock` section to your `.rr.yaml`, then autowire `LockFactory` (or `PersistingStoreInterface`) anywhere:

```php
use Symfony\Component\Lock\LockFactory;

public function __construct(private LockFactory $locks) {}

$lock = $this->locks->createLock('report-generation');
if ($lock->acquire()) { /* ... */ $lock->release(); }
```

## Temporal (beta-test)

> [!WARNING]
> Temporal support is in **beta**. The overall flow and the way it's implemented might still
> change. The goal is a nice and easy DX, which is being actively explored right now — expect
> breaking changes until the API settles.

The bundle integrates [Temporal](https://learn.temporal.io/getting_started/php/). It activates
automatically once `temporal/sdk` is installed:

```bash
composer require temporal/sdk
```

Assign workflows/activities to a worker's task queue with the `#[TaskQueue]` attribute, run
them under RoadRunner's `temporal` plugin, and react to interceptor calls via Symfony events. A
profiler tab lists the registered workers, workflows and activities.

**→ Full usage guide with copy-paste examples: [`docs/temporal.md`](docs/temporal.md)** (defining
activities/workflows, configuration, starting a workflow, interceptor events).

## Developing with Symfony and RoadRunner

- If possible, stop using lazy loading in your services, inject services immediately. Lazy loaded services might introduce memory leaks and make your services slower to initialize when requests arrive.
- Do not use/create local class/array caches in your services, only if you know, what you are doing. Try to make them stateless or use [ResetInterface](https://github.com/symfony/contracts/blob/main/Service/ResetInterface.php) to clean up between requests, so state is not being shared. Mind the [non‑shared caveat](#service-reset): a `shared: false` resettable service isn't reset before Symfony 8.1.
- Symfony forms might leak data across requests due to caching, see section bellow.
- Simplify your `User` session serialization by taking advantage of `EquatableInterface` and a custom de/serialization logic. 
This will prevent errors because of detached Doctrine entities and, as a side bonus, will speed up loading user from sessions.
```php
<?php

namespace App\Entity\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $password = null;

    // serialize ony these three fields
    public function __serialize(): array
    {
        return [
            "id"       => $this->id,
            "email"    => $this->email,
            "password" => $this->password,
        ];
    }

    // unserialize ony these three fields
    public function __unserialize(array $data): void
    {
        $this->id = $data["id"] ?? null;
        $this->email = $data["email"] ?? null;
        $this->password = $data["password"] ?? null;
    }

    // check only the three serialized fields
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

Symfony caches **OptionsResolver::setDefaults()** calls,
so they resolve only once for current worker when someone uses
them for the first time.

This may lead to sharing sensitive information across requests in the context of a single worker,
if you do not use defaults correctly.

Consider this Form, which has major flaw that will leak user email to subsequent requests
that worker receives.
```php
class MyType extends AbstractType
{
    // your buildForm() and what not
    // ...
    
    // invalid use of setDefaults()
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // loads current user
            // and reuses his email forever until worker restarts
            // everyone, even in anonymous browser tabs or different sessions,
            // will see their email 
            "label" => $this->security->getUser()->getEmail(),
        ]);
    }
}
```

You should really use only static/stateless default values
and dynamic options should be passed when
`OptionsResolver` is used, or form is being created, eg:

```php
// with this the user email will
// stay within this single request
// and won't be leaked to subsequent worker requests
$correctForm = $this->createForm(MyType::class, options: [
    "label" => $this->getUser()->getEmail(),
]);
```

## Debugging (recommendations)

With RoadRunner you cannot simply dump and die, because nothing will be printed.
I would like to introduce [Buggregator](https://docs.buggregator.dev/config/var-dumper.html) to work around that. 
As a bonus it can also work as a [mailtrap](https://docs.buggregator.dev/config/smtp.html) or testing [Sentry](https://docs.buggregator.dev/config/sentry.html) locally

## Credits

Inspiration taken from existing solutions like [Baldinof's Bundle](https://github.com/Baldinof/roadrunner-bundle) 
and [Nyholm's Runtime](https://github.com/php-runtime/roadrunner-symfony-nyholm)
