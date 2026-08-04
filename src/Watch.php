<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;

/**
 * @api
 *
 * A running watch started by {@see Client::watch()}. It drives a background
 * coroutine that delivers status changes to the callback until {@see self::close()}.
 */
final readonly class Watch
{
    /**
     * @internal
     * @param Future<mixed> $future
     */
    public function __construct(
        private DeferredCancellation $cancellation,
        private Future $future,
    ) {}

    public function close(): void
    {
        $this->cancellation->cancel();

        try {
            $this->future->await();
        } catch (CancelledException) {
        }
    }
}
