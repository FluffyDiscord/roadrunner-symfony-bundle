# RoadRunner Runtime for Symfony

Yet another runtime for Symfony and [RoadRunner](https://roadrunner.dev/).

> Using DDEV? There's a companion [DDEV add-on](https://github.com/FluffyDiscord/ddev-roadrunner-symfony) for a one-command local setup — see [DDEV add-on](#ddev-add-on) below.

## Features

- [HTTP worker](#usage) — drop-in runtime; service reset runs *after* the response, off the request's critical path
- [Worker warmup](#worker-warmup) — zero-config pre-warming; first request at steady-state speed
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

    # How RoadRunner requests are converted to Symfony requests.
    #
    # native (fastest) - build the Symfony Request directly from the
    # RoadRunner request, skipping the intermediate PSR-7 object.
    # Roughly halves the per-request conversion cost.
    # psr7 - the previous behavior: build a PSR-7 request first, then
    # convert it via symfony/psr-http-message-bridge. Use this when you
    # decorate the conversion with a custom HttpFoundationFactoryInterface
    # service (that service is picked up automatically).
    # auto (default) - psr7 when a custom HttpFoundationFactoryInterface
    # service is registered, native otherwise.
    #
    # The small, deliberate behavior differences of "native" (uploaded-file
    # class/pathname, verbatim header forwarding, raw query-string encoding)
    # are listed in UPGRADE.md.
    request_factory: auto

  # Worker warmup (see "Worker warmup" section below)
  warmup:
    enabled: true
    learn: true
    learn_requests: 30
    manifest_path: null

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
variables and the `REMOTE_ADDR` is missing. This is intentional.


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

Headers already emitted in a `103` frame are not repeated in the final response. RoadRunner writes
worker headers with Go's `Header().Add()`, and Go keeps the `1xx` headers in place as RFC 8297
requires, so re-sending the whole header bag would put every hinted header on the wire twice. The
worker tracks what each `1xx` frame emitted and sends only the values that are not on the wire yet
— the same rule Symfony's `Response::sendHeaders()` applies on other SAPIs.

One limitation is inherent to the RoadRunner protocol: it can only *add* headers, with no
equivalent of `header_remove()`. A header whose **value changes** after the `103` was sent (typically
when the early-hints response and the final response are different objects) therefore reaches the
client with both the old and the new value — the stale one cannot be retracted. Send hints on the
response you are going to return, as `sendEarlyHints($links, $response)` does. While `kernel.debug`
is enabled the worker writes a STDERR line naming any header this happens to, so the stale value
does not go unnoticed.

## Error handling

Worker-level failures become real HTTP responses instead of RoadRunner's raw error page.

| Failure | dev (`kernel.debug`) | prod |
|---|---|---|
| exception in your code | Symfony's exception page | Symfony's error page |
| exception escaping Symfony | `HtmlErrorRenderer` page | bare `500`, empty body |
| `die()` / `exit()` / fatal | small built-in error page | bare `500`, empty body |

`die()`, `exit()` and fatals cannot be caught — a shutdown handler answers instead, best-effort.
Details go to STDERR (RoadRunner worker logs) and Sentry if installed, never to `stdout` (the
goridge protocol channel).

In dev the page also names **where the last `dump()`/`dd()` ran** — `file:line`, hyperlinked to your
IDE (`framework.ide`) — since PHP records nothing for `die()`/`exit()` itself. The dump is shown on
the page too, unless a dump server (Buggregator / `debug.dump_destination`) is configured, in which
case it goes there. Needs `symfony/var-dumper`; never active in prod.

Not covered:

- true out-of-memory — Symfony's fatal handler can trip RoadRunner's `stdout` CRC check first
- a response already streaming — never patched with a second frame
- `SIGKILL`, segfault, stack overflow — PHP shutdown never runs

Best dev experience: socket relay (`RR_RELAY=tcp://…`/`unix://…`) or `http.pool.debug: true`.

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

## Worker warmup

A fresh worker's first request is several times slower than its steady state: PHP
compiles thousands of classes and Symfony initializes its infrastructure lazily, on
first use. `opcache.preload` can't help — it is a no-op in `cli` workers.

The bundle warms all of this during worker boot, before RoadRunner marks the worker
ready. Zero config:

1. **Generic warmers** — router, Doctrine metadata, event listeners, form types, Twig
   runtimes, container preload class list. Missing dependencies are skipped.
2. **Learned manifest** — workers record what real traffic loads
   (`<kernel.cache_dir>/roadrunner/warmup.manifest.json`) and every next worker replays
   it at boot. Invalidated automatically when the container is rebuilt.

Measured on a production Sylius app: first request 252 ms → 41 ms (steady state 33–43 ms).

Development (`http.pool.debug: true`): warmup and learning switch themselves off. With one
process per request nothing warmed survives to a second request, so replaying the manifest
only adds boot latency — and compiling cached Twig templates at boot early-binds their
classes, which makes Twig skip its freshness check and keep serving stale templates after an
edit. The gate is `kernel.runtime_mode.worker`, which the bundle's runtime derives from
`pool.debug` in your `.rr.yaml`; no configuration needed.

Production notes:

- Run worker PHP with `display_errors=0` — warnings on stdout corrupt the worker protocol.
- `opcache.file_cache=/some/dir` shares compiled bytecode across workers
  (worker boot ~600 ms → ~200 ms). The bundle adapts to it automatically.
- Warmed classes live in each worker's own opcache — budget
  `opcache.memory_consumption` × worker count.
- `warmup.learn_requests` (default 30) — how many responses each worker records.
- Set `warmup.manifest_path` outside the cache dir to keep learning across deploys.

### Warming your own services

Implement the interface — autoconfiguration does the rest:

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

Or listen to `WorkerBootingEvent`. A throwing warmer is logged and skipped — warmup
never prevents the worker from serving.

### Warm the cache before starting workers

```bash
php bin/console cache:warmup
rr serve
```

An unwarmed cache makes every worker warm the container at once. Symfony locks the container
dump but not the rest of the warmup, so with `APP_DEBUG=1` the concurrent writes corrupt
`<Container>Deprecations.log`; the `unserialize()` warning is promoted to an exception and the
worker dies at boot. RoadRunner respawns it, it dies again — with no ready worker every request
blocks until the client times out, so it reads as a hang rather than an error.

Upstream Symfony bug, not a RoadRunner one: reproduces with concurrent `php public/index.php`.
Unaffected: `APP_DEBUG=0`, or any already-warm cache.

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

## DDEV add-on

If you develop with [DDEV](https://ddev.com/), the [`ddev-roadrunner-symfony`](https://github.com/FluffyDiscord/ddev-roadrunner-symfony)
add-on wires RoadRunner into your DDEV project so you can get this bundle running locally in one command:

```shell
ddev add-on get FluffyDiscord/ddev-roadrunner-symfony
```

See the [add-on repository](https://github.com/FluffyDiscord/ddev-roadrunner-symfony) for configuration and usage details.

## Credits

Inspiration taken from existing solutions like [Baldinof's Bundle](https://github.com/Baldinof/roadrunner-bundle) 
and [Nyholm's Runtime](https://github.com/php-runtime/roadrunner-symfony-nyholm)
