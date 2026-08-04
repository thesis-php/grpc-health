<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

/**
 * @api
 */
interface Lifecycle
{
    /**
     * Marks every known service (including the overall '' entry) as
     * {@see ServingStatus::NotServing} and rejects further status changes until
     * {@see self::resume()} is called. Use it to drain traffic on shutdown.
     */
    public function shutdown(): void;

    /**
     * Reverses {@see self::shutdown()}: marks every known service as
     * {@see ServingStatus::Serving} and accepts status changes again.
     */
    public function resume(): void;
}
