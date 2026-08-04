<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Grpc\Health\V1\HealthCheckResponse\ServingStatus as HealthStatus;

/**
 * @api
 */
enum ServingStatus
{
    case Unknown;
    case Serving;
    case NotServing;

    /**
     * Reported by {@see Server::watch()} for a service that is not (yet) known
     * to the server. Unlike {@see self::Unknown} it never terminates the watch.
     */
    case ServiceUnknown;

    public static function fromProto(HealthStatus $status): self
    {
        return match ($status) {
            HealthStatus::UNKNOWN => self::Unknown,
            HealthStatus::SERVING => self::Serving,
            HealthStatus::NOT_SERVING => self::NotServing,
            HealthStatus::SERVICE_UNKNOWN => self::ServiceUnknown,
        };
    }

    public function proto(): HealthStatus
    {
        return match ($this) {
            self::Unknown => HealthStatus::UNKNOWN,
            self::Serving => HealthStatus::SERVING,
            self::NotServing => HealthStatus::NOT_SERVING,
            self::ServiceUnknown => HealthStatus::SERVICE_UNKNOWN,
        };
    }
}
