<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Google\Rpc\Code;
use Grpc\Health\V1\HealthCheckRequest;
use Grpc\Health\V1\HealthClient;
use Thesis\Grpc\Exception\ClientStreamIsClosed;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use function Amp\async;

/**
 * @api
 */
final readonly class Client
{
    public function __construct(
        private HealthClient $client,
    ) {}

    /**
     * @param string $service empty service name addresses the overall health of the server
     * @throws InvokeError on any status other than `NOT_FOUND`
     * @throws ClientStreamIsClosed
     */
    public function statusOf(
        string $service = '',
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): ServingStatus {
        try {
            $response = $this->client->check(
                new HealthCheckRequest($service),
                $md,
                $cancellation,
            );
        } catch (InvokeError $e) {
            if ($e->statusCode === Code::NOT_FOUND) {
                return ServingStatus::ServiceUnknown;
            }

            throw $e;
        }

        return ServingStatus::fromProto($response->status);
    }

    /**
     * Whether a service is currently {@see ServingStatus::Serving}.
     *
     * @param string $service empty service name addresses the overall health of the server
     * @throws InvokeError on any status other than `NOT_FOUND`
     * @throws ClientStreamIsClosed
     */
    public function serving(
        string $service = '',
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): bool {
        return $this->statusOf($service, $md, $cancellation) === ServingStatus::Serving;
    }

    /**
     * Watches a service: invokes {@see $onStatus} with the current status and then
     * on every change in a background coroutine, until the returned {@see Watch}
     * is closed or cancellation is requested.
     *
     * @param non-empty-string $service
     * @param callable(ServingStatus): void $onStatus
     */
    public function watch(
        string $service,
        callable $onStatus,
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): Watch {
        $deferred = new DeferredCancellation();
        $composite = new CompositeCancellation($cancellation, $deferred->getCancellation());

        $coroutine = async(function () use (
            $service,
            $onStatus,
            $md,
            $composite,
        ): void {
            try {
                $stream = $this->client->watch(
                    new HealthCheckRequest($service),
                    $md,
                    $composite,
                );

                foreach ($stream as $response) {
                    $onStatus(ServingStatus::fromProto($response->status));
                }
            } catch (CancelledException) {
            }
        });

        return new Watch($deferred, $coroutine);
    }
}
