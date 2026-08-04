<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\Cancellation;
use Amp\NullCancellation;

/**
 * @api
 */
interface Monitor
{
    /**
     * The current status of a service, or `null` when it is unknown.
     */
    public function statusOf(string $service): ?ServingStatus;

    /**
     * A non-atomic snapshot of every known service and its status.
     *
     * @return array<string, ServingStatus>
     */
    public function snapshot(): array;

    /**
     * Yields the current status of a service immediately ({@see ServingStatus::ServiceUnknown}
     * when the service is unknown), then a new status on every change, until
     * the given cancellation is requested.
     *
     * @return iterable<array-key, ServingStatus>
     */
    public function subscribe(
        string $service,
        Cancellation $cancellation = new NullCancellation(),
    ): iterable;
}
