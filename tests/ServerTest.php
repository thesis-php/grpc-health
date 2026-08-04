<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Google\Rpc\Code;
use Grpc\Health\V1\HealthCheckRequest;
use Grpc\Health\V1\HealthCheckResponse\ServingStatus as HealthStatus;
use Grpc\Health\V1\HealthListRequest;
use Testo\Assert;
use Testo\Test;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use function Amp\async;
use function Amp\delay;

#[Test]
final class ServerTest
{
    public function checkReturnsTheOverallStatusByDefault(): void
    {
        $response = self::server()->check(new HealthCheckRequest(), new Metadata(), new NullCancellation());

        Assert::same($response->status, HealthStatus::SERVING);
    }

    public function checkReturnsTheServiceStatus(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('svc', ServingStatus::NotServing);

        $response = self::server($watchdog)->check(new HealthCheckRequest('svc'), new Metadata(), new NullCancellation());

        Assert::same($response->status, HealthStatus::NOT_SERVING);
    }

    public function checkFailsWithNotFoundForAnUnknownService(): void
    {
        $thrown = null;

        try {
            self::server()->check(new HealthCheckRequest('svc'), new Metadata(), new NullCancellation());
        } catch (InvokeError $e) {
            $thrown = $e;
        }

        Assert::instanceOf($thrown, InvokeError::class);
        Assert::same($thrown->statusCode, Code::NOT_FOUND);
    }

    public function listReturnsEveryKnownService(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('a', ServingStatus::Serving);
        $watchdog->report('b', ServingStatus::NotServing);

        $statuses = self::server($watchdog)->list(new HealthListRequest(), new Metadata(), new NullCancellation())->statuses;

        Assert::same(($statuses[''] ?? null)?->status, HealthStatus::SERVING);
        Assert::same(($statuses['a'] ?? null)?->status, HealthStatus::SERVING);
        Assert::same(($statuses['b'] ?? null)?->status, HealthStatus::NOT_SERVING);
    }

    public function watchStreamsTheCurrentStatusThenChanges(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('svc', ServingStatus::Serving);
        $server = self::server($watchdog);

        $cancellation = new DeferredCancellation();

        /** @var list<HealthStatus> $received */
        $received = [];

        $watcher = async(static function () use ($server, $cancellation, &$received): void {
            foreach ($server->watch(new HealthCheckRequest('svc'), new Metadata(), $cancellation->getCancellation()) as $response) {
                $received[] = $response->status;

                if (\count($received) === 2) {
                    return;
                }
            }
        });

        delay(0.01);
        $watchdog->report('svc', ServingStatus::NotServing);

        $watcher->await();

        Assert::same($received, [HealthStatus::SERVING, HealthStatus::NOT_SERVING]);
    }

    private static function server(Watchdog $watchdog = new Watchdog()): Server
    {
        return new Server($watchdog);
    }
}
