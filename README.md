# RoadRunner Runtime for Symfony

Yet another runtime for Symfony and [RoadRunner](https://roadrunner.dev/).

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

Don't forget to add the `RR_RPC` to your `.env`:

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

The default behavior of Symfony's kernel is to reset your services 
before request is handled and slows down the initial reaction time.

|    |  When new request arrives  |  Your app  |  After response was sent back  |
| -- | ------------------------| ----------------| ----------------------- |
| Symfony | does a reset, if something fails here, request may be lost | waits for reset to be done, then handles your request |  |
| RoadRunnerBundle |  | immediately handles your request | does a reset |  

You might want to manually refresh Doctrine connections before each request is handled 
you are using `Mysql`, `MariaDB` or other database that cannot handle long/persistent
connection. For this, it's up to you to create event listener for `WorkerRequestReceivedEvent`

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


## Running behind a load balancer or a proxy
If you want to use `REMOTE_ADDR` as [trusted proxy](https://symfony.com/doc/current/deployment/proxies.html#solution-settrustedproxies), replace it with `private_ranges` instead 
or else your trusted headers will not work.

Symfony is using the `$_SERVER['REMOTE_ADDR']` to find out the proxy address,
but in the context of RoadRunner, `$_SERVER` contains only environment 
variables and the `REMOTE_ADDS` is missing.



## Response/file streaming

Build-in full support for Symfony's `BinaryFileResponse` and `StreamedJsonResponse`. The `StreamedResponse` needs one little 
change to be fully streamable - you have to change the `callback` to a `\Generator`, replacing all `echo` with `yield`. Look at the example:

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

class MyController
{
    #[Route("/stream")]
    public function myStreamResponse() 
    {
        return new StreamedResponse(
            function () {
                // replace all 'echo' or any outputs with 'yield'
                // echo "data";
                yield "data";
            }
        );
    }
}
```

## Sentry

Built in support for [Sentry](https://packagist.org/packages/sentry/sentry-symfony). Just install & configure it as you normally do.

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

## Developing with Symfony and RoadRunner

- If possible, stop using lazy loading in your services, inject services immediately. Lazy loaded services might introduce memory leaks and make your services slower to initialize when requests arrive.
- Do not use/create local class/array caches in your services, only if you know, what you are doing. Try to make them stateless or use [ResetInterface](https://github.com/symfony/contracts/blob/main/Service/ResetInterface.php) to clean up before each request, so state is not being shared.
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
