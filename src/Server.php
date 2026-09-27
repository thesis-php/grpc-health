<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\Cancellation;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\Health\V1\HealthCheckRequest;
use Thesis\Grpc\Health\V1\HealthCheckResponse;
use Thesis\Grpc\Health\V1\HealthListRequest;
use Thesis\Grpc\Health\V1\HealthListResponse;
use Thesis\Grpc\Health\V1\HealthServer;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use Thesis\Protobuf;

/**
 * @api
 */
final readonly class Server implements HealthServer
{
    /**
     * The maximum number of services {@see self::list()} will return before
     * failing with `RESOURCE_EXHAUSTED`, matching the reference implementation.
     */
    private const int DEFAULT_MAX_SERVICES = 100;

    /**
     * @param positive-int $maxServices
     */
    public function __construct(
        private Monitor $monitor,
        private int $maxServices = self::DEFAULT_MAX_SERVICES,
    ) {}

    #[\Override]
    public function check(
        HealthCheckRequest $request,
        Metadata $md,
        Cancellation $cancellation,
    ): HealthCheckResponse {
        $status = $this->monitor->statusOf($request->service);

        if ($status === null) {
            throw new InvokeError(Code::NOT_FOUND, \sprintf('unknown service "%s"', $request->service));
        }

        return new HealthCheckResponse($status->proto());
    }

    #[\Override]
    public function list(
        HealthListRequest $request,
        Metadata $md,
        Cancellation $cancellation,
    ): HealthListResponse {
        $snapshot = $this->monitor->snapshot();

        if (\count($snapshot) > $this->maxServices) {
            throw new InvokeError(Code::RESOURCE_EXHAUSTED, 'too many services');
        }

        return new HealthListResponse(Protobuf\Map::fromArray(
            array_map(
                static fn(ServingStatus $status): HealthCheckResponse => new HealthCheckResponse($status->proto()),
                $snapshot,
            ),
        ));
    }

    #[\Override]
    public function watch(
        HealthCheckRequest $request,
        Metadata $md,
        Cancellation $cancellation,
    ): iterable {
        foreach ($this->monitor->subscribe($request->service, $cancellation) as $status) {
            yield new HealthCheckResponse($status->proto());
        }
    }
}
