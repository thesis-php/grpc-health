<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\DeferredCancellation;
use Testo\Assert;
use Testo\Test;
use function Amp\async;
use function Amp\delay;

#[Test]
final class WatchdogTest
{
    public function defaultsToServingOverall(): void
    {
        Assert::same(new Watchdog()->statusOf(''), ServingStatus::Serving);
    }

    public function reportsAnUnknownServiceAsNull(): void
    {
        Assert::null(new Watchdog()->statusOf('svc'));
    }

    public function tracksPerServiceStatus(): void
    {
        $watchdog = new Watchdog();

        $watchdog->report('svc', ServingStatus::Serving);
        Assert::same($watchdog->statusOf('svc'), ServingStatus::Serving);

        $watchdog->report('svc', ServingStatus::NotServing);
        Assert::same($watchdog->statusOf('svc'), ServingStatus::NotServing);
    }

    public function shutdownMarksEverythingNotServingAndFreezesUpdates(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('svc', ServingStatus::Serving);

        $watchdog->shutdown();

        Assert::same($watchdog->statusOf(''), ServingStatus::NotServing);
        Assert::same($watchdog->statusOf('svc'), ServingStatus::NotServing);

        $watchdog->report('svc', ServingStatus::Serving);
        Assert::same($watchdog->statusOf('svc'), ServingStatus::NotServing);

        $watchdog->resume();
        Assert::same($watchdog->statusOf('svc'), ServingStatus::Serving);
    }

    public function subscribeEmitsTheCurrentStatusThenEveryChange(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('svc', ServingStatus::Serving);

        $cancellation = new DeferredCancellation();

        /** @var list<ServingStatus> $received */
        $received = [];

        $watcher = async(static function () use ($watchdog, $cancellation, &$received): void {
            foreach ($watchdog->subscribe('svc', $cancellation->getCancellation()) as $status) {
                $received[] = $status;

                if (\count($received) === 3) {
                    return;
                }
            }
        });

        delay(0.01);
        $watchdog->report('svc', ServingStatus::NotServing);
        delay(0.01);
        $watchdog->report('svc', ServingStatus::Serving);

        $watcher->await();

        Assert::same($received, [ServingStatus::Serving, ServingStatus::NotServing, ServingStatus::Serving]);
    }

    public function subscribeEmitsServiceUnknownForAnUnknownService(): void
    {
        $watchdog = new Watchdog();
        $cancellation = new DeferredCancellation();

        /** @var list<ServingStatus> $received */
        $received = [];

        $watcher = async(static function () use ($watchdog, $cancellation, &$received): void {
            foreach ($watchdog->subscribe('svc', $cancellation->getCancellation()) as $status) {
                $received[] = $status;
            }
        });

        delay(0.01);
        $cancellation->cancel();
        $watcher->await();

        Assert::same($received, [ServingStatus::ServiceUnknown]);
    }

    public function subscribeSkipsRedundantUpdates(): void
    {
        $watchdog = new Watchdog();
        $watchdog->report('svc', ServingStatus::Serving);

        $cancellation = new DeferredCancellation();

        /** @var list<ServingStatus> $received */
        $received = [];

        $watcher = async(static function () use ($watchdog, $cancellation, &$received): void {
            foreach ($watchdog->subscribe('svc', $cancellation->getCancellation()) as $status) {
                $received[] = $status;
            }
        });

        delay(0.01);
        $watchdog->report('svc', ServingStatus::Serving);
        $watchdog->report('svc', ServingStatus::NotServing);
        delay(0.01);
        $cancellation->cancel();
        $watcher->await();

        Assert::same($received, [ServingStatus::Serving, ServingStatus::NotServing]);
    }
}
