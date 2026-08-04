<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

/**
 * @api
 */
interface Reporter
{
    /**
     * @param non-empty-string $service
     */
    public function report(string $service, ServingStatus $status): void;
}
