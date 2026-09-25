# gRPC usage guide

Serve a RoadRunner `grpc` pool from your Symfony app: implement the interfaces
`protoc-gen-php-grpc` generates from your `.proto` files as plain autowired services,
and the bundle routes, observes and (optionally) authenticates every call.

## 1. Install

```bash
composer require spiral/roadrunner-grpc
```

The worker, events, profiler panel, tracing and `grpc:debug` activate automatically
once the package is installed. For authentication (section 8) also install
`symfony/security-bundle`.

## 2. Enable the `grpc` plugin in `.rr.yaml`

```yaml
server:
  command: "php public/index.php"

grpc:
  listen: "tcp://127.0.0.1:9001"
  proto:
    - "proto/greeter.proto"
  # TLS terminates in RoadRunner, not PHP:
  # tls:
  #   cert: "/etc/certs/server.pem"
  #   key: "/etc/certs/server.key"
  pool:
    num_workers: 4
```

RoadRunner routes only services declared in the `proto:` files; a PHP service missing
from them answers `UNIMPLEMENTED` before ever reaching your worker.

## 3. Generate the PHP interfaces

```bash
./vendor/bin/rr download-protoc-binary   # installs protoc-gen-php-grpc
protoc --plugin=protoc-gen-php-grpc=./protoc-gen-php-grpc \
       --php_out=src/Grpc --php-grpc_out=src/Grpc \
       -I proto proto/greeter.proto
```

This yields the protobuf message classes and one `GreeterInterface extends
Spiral\RoadRunner\GRPC\ServiceInterface` per service, carrying the wire name in its
`NAME` constant.

## 4. Implement the service

```php
use Spiral\RoadRunner\GRPC\ContextInterface;

class GreeterService implements GreeterInterface
{
    public function Greet(ContextInterface $ctx, GreetRequest $in): GreetResponse
    {
        return new GreetResponse()->setMessage('Hello, ' . $in->getName());
    }
}
```

Any autoconfigured service implementing a generated interface is discovered at
compile time (tag `fluffy_discord.roadrunner.grpc.service` for non-autoconfigured
definitions). Two services implementing the same `NAME` fail the container build.
Handlers are ordinary singletons — inject dependencies through the constructor and
implement `ResetInterface` (tag `kernel.reset`) for per-call state.

## 5. Metadata, response headers, trailers

```php
use FluffyDiscord\RoadRunnerBundle\Grpc\GrpcMetadata;
use Spiral\RoadRunner\GRPC\ResponseHeaders;
use Spiral\RoadRunner\GRPC\ResponseTrailers;

public function Greet(ContextInterface $ctx, GreetRequest $in): GreetResponse
{
    $metadata = GrpcMetadata::fromContext($ctx);
    $requestId = $metadata->getFirst('x-request-id');
    $bearerToken = $metadata->getBearerToken();

    $ctx->getValue(ResponseHeaders::class)->set('x-served-by', gethostname());
    $ctx->getValue(ResponseTrailers::class)->set('x-checksum', '...');
    // ...
}
```

Metadata keys are case-insensitive (lower-cased); `-bin` values are passed through
as RoadRunner delivers them.

## 6. Errors and status codes

Throw a `GRPCException` (or a subclass) to answer with a precise gRPC status:

```php
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

throw GRPCException::create('unknown greeting id', StatusCode::NOT_FOUND);
```

Any other exception is logged to STDERR, reported to Sentry (when installed), and the
client receives a non-OK status — the worker keeps serving. With `APP_DEBUG=1` the
client receives the full stack trace (like the HTML debug error page); remember that
gRPC clients are usually other services. Statuses you throw deliberately keep their
message in production, but anything coded `INTERNAL` is treated as a server-side defect
and reaches the client as `Internal server error` — the real message stays in the log. A kernel boot failure answers calls with
`UNAVAILABLE` while RoadRunner respawns the worker and boot is retried.

## 7. Events

Every call dispatches, with fully decoded protobuf messages:

- `FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallReceivedEvent` — service, method, context, request
- `FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallCompletedEvent` — + response, duration
- `FluffyDiscord\RoadRunnerBundle\Event\Grpc\GrpcCallFailedEvent` — + throwable, gRPC status (also for unknown-service/method frames)

```php
#[AsEventListener]
public function onGrpcCall(GrpcCallCompletedEvent $event): void
{
    $this->metrics->timing('grpc.' . $event->methodName, $event->durationMs);
}
```

## 8. Authentication (Symfony Security)

```yaml
fluffy_discord_road_runner:
    grpc:
        security:
            enabled: true
            token_handler: App\Security\ApiTokenHandler   # your AccessTokenHandlerInterface
            firewall_name: main   # required: the firewall whose user_checker applies
            # metadata_key: authorization
            # token_prefix: 'Bearer '
            # required: true      # false: calls without the key run anonymously
            # user_provider: ~    # needed when your handler's UserBadge has no loader
                                  # and more than one provider is configured
```

The bearer token from call metadata goes through the same `AccessTokenHandlerInterface`
contract the `access_token` firewall authenticator uses; the resolved user (checked by
your `UserCheckerInterface`) lands in the token storage for the duration of the call.

`firewall_name` must name a configured security firewall: the bundle resolves
`security.user_checker.<firewall_name>` at compile time and reuses that firewall's
`user_checker`, so gRPC gets the same account-status checks that firewall gets over HTTP.
A name matching no firewall **fails the container build** rather than falling back to the
global `security.user_checker`, which is a no-op for every non-`InMemoryUser` user and
would let disabled and locked accounts authenticate. Two caveats: a firewall declared with
`security: false` does not qualify, and pointing at a firewall that declares no
`user_checker` of its own resolves back to that same global no-op — parity with the
firewall is what is guaranteed, not enforcement. It also stamps the firewall name on the
`PostAuthenticationToken`. A firewall dedicated to gRPC needs a never-matching `pattern`
(a pattern-less firewall matches everything and the map takes the first match, so an
unpatterned `grpc` firewall declared first would swallow all HTTP traffic).
`bin/console grpc:debug` prints the resolved service id:

```php
#[IsGranted('ROLE_API')]                  // PERMISSION_DENIED when denied,
public function Greet(...)                // UNAUTHENTICATED for anonymous callers
{
    $user = $this->security->getUser();   // works like in a controller
}
```

Missing credentials (with `required: true`) and invalid tokens answer
`UNAUTHENTICATED`; the reason a token failed is never sent to the client.
`#[IsGranted]` works on the handler class, the handler method or the generated
interface method with a string attribute; `subject: 'request'` passes the decoded
request message to your voter. Custom schemes (API keys, mTLS-derived identity)
can replace the whole step by aliasing
`FluffyDiscord\RoadRunnerBundle\Grpc\Security\GrpcCallAuthenticatorInterface`.

## 9. Profiler

With `APP_DEBUG=1` every gRPC call produces a **full** Symfony profile — the same
pipeline an HTTP request gets: logs, Doctrine queries, dispatched events, timeline,
memory, security, dumps — plus a gRPC panel showing the decoded request/response
JSON, the gRPC status, duration and metadata (`authorization` & co. redacted;
configure with `grpc.profiler.redacted_metadata_keys`). Calls appear in the profiler
list as `grpc://<service>/<method>`. Use the `RoadRunnerMicroKernelTrait` (see the
README) so per-call timings are correct.

## 10. Tracing

```yaml
fluffy_discord_road_runner:
    grpc:
        tracing: true
```

Logs every call on the `grpc` Monolog channel (metadata keys only, never values)
and adds Sentry breadcrumbs when Sentry is installed.

## 11. Console commands

```bash
bin/console grpc:debug
```

Prints the RoadRunner server facts from `.rr.yaml` (listen address, TLS,
`client_auth_type`, proto files), the security setup, and every registered service
with its methods, message types and `#[IsGranted]` attributes — plus any method whose
signature `protoc-gen-php-grpc` would reject (non-zero exit code, CI-friendly). No
server connection is made.

## 12. Limitations

- **Unary calls only** — the RoadRunner PHP gRPC contract is request/response;
  server/client streaming is not supported.
- The bundle serves gRPC; it is **not a gRPC client**. Calling other gRPC services
  needs `ext-grpc` + generated client stubs, independent of this bundle.
- Symfony **firewalls** do not run for gRPC calls; authentication is the
  access-token integration above.
- TLS (including mTLS) terminates in RoadRunner; peer-certificate identity is not
  forwarded to PHP.
