# thesis/grpc-health

An implementation of the standard [gRPC Health Checking Protocol](https://github.com/grpc/grpc/blob/master/doc/health-checking.md)
(`grpc.health.v1.Health`) for [thesis/grpc](https://github.com/thesis-php/grpc). It ships both sides of
the protocol:

- a **server** — a mutable health state you drive from your application, plus the gRPC service adapter
  that serves `Check`, `List` and `Watch` from it;
- a **client** — a thin prober over the generated client that returns a typed `ServingStatus`, answers
  `serving()`, and turns the `Watch` stream into a callback with a closable handle.

An empty service name (`''`) always addresses the overall health of the server, as defined by the
protocol.

## Contents

- [Installation](#installation)
- [Server](#server)
- [Client](#client)

## Installation

```bash
composer require thesis/grpc-health
```

## Server

The health state lives in a single `Watchdog`. Register it once in your container and share it through
three narrow ports, each injected only where it is needed:

- `Reporter` — application services report their own readiness (`report()`);
- `Lifecycle` — operators drain the whole surface (`shutdown()` / `resume()`);
- `Monitor` — the gRPC `Server` adapter reads statuses and subscribes to changes.

```php
use Grpc\Health\V1\HealthServerRegistry;
use Thesis\Grpc\Health;

$watchdog = new Health\Watchdog();

// The adapter depends on the Monitor, the generated registry wires it into the gRPC server.
$grpcServer->register(new HealthServerRegistry(new Health\Server($watchdog)));
```

Application services depend on `Health\Reporter` — they never see gRPC — and report their own
readiness:

```php
use Thesis\Grpc\Health\Reporter;
use Thesis\Grpc\Health\ServingStatus;

final readonly class Orders
{
    public function __construct(
        private Reporter $health,
    ) {}

    public function boot(): void
    {
        $this->health->report('orders.v1.Orders', ServingStatus::Serving);
    }

    public function overloaded(): void
    {
        $this->health->report('orders.v1.Orders', ServingStatus::NotServing);
    }
}
```

On shutdown, drain traffic through the `Lifecycle`: mark everything `NOT_SERVING` so load
balancers stop routing while in-flight work finishes:

```php
$watchdog->shutdown(); // every service -> NotServing, further changes are frozen
$watchdog->resume();   // every service -> Serving again
```

In a DI container this is one singleton `Watchdog` aliased to whichever of `Reporter`, `Lifecycle`
and `Monitor` each consumer needs.

## Client

Wrap the generated `HealthClient` to get typed statuses instead of raw protobuf:

```php
use Grpc\Health\V1\HealthClient;
use Thesis\Grpc\Health;
use Thesis\Grpc\Health\ServingStatus;

$health = new Health\Client(new HealthClient($grpcClient));

$status = $health->statusOf('orders.v1.Orders'); // ServingStatus; unknown service -> ServiceUnknown

if ($health->serving('orders.v1.Orders')) {
    // ready — handy for readiness probes and health-check scripts
}
```

`watch()` runs the `Watch` server stream in a background coroutine and calls your callback with the current
status and then on every change, until the returned handle is closed:

```php
$watch = $health->watch('orders.v1.Orders', function (ServingStatus $status): void {
    // react to the new status
});

// later
$watch->close();
```
