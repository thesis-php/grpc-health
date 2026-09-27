<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\Cancellation;
use Amp\NullCancellation;
use Testo\Assert;
use Testo\Test;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc;
use Thesis\Grpc\Client\Invoke;
use Thesis\Grpc\ClientStream;
use Thesis\Grpc\Health\V1\HealthCheckResponse;
use Thesis\Grpc\Health\V1\HealthCheckResponse\ServingStatus as HealthStatus;
use Thesis\Grpc\Health\V1\HealthClient;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use function Amp\delay;

#[Test]
final class ClientTest
{
    public function statusMapsNotFoundToServiceUnknown(): void
    {
        $client = new Client(new HealthClient(new FakeTransport(error: new InvokeError(Code::NOT_FOUND))));

        Assert::same($client->statusOf('svc'), ServingStatus::ServiceUnknown);
    }

    public function statusThrowsOtherStatuses(): void
    {
        $client = new Client(new HealthClient(new FakeTransport(error: new InvokeError(Code::UNAVAILABLE))));

        $thrown = null;

        try {
            $client->statusOf('svc');
        } catch (InvokeError $e) {
            $thrown = $e;
        }

        Assert::instanceOf($thrown, InvokeError::class);
        Assert::same($thrown->statusCode, Code::UNAVAILABLE);
    }

    public function servingStatus(): void
    {
        $serving = new Client(new HealthClient(new FakeTransport(response: new HealthCheckResponse(HealthStatus::SERVING))));
        $down = new Client(new HealthClient(new FakeTransport(response: new HealthCheckResponse(HealthStatus::NOT_SERVING))));

        Assert::true($serving->serving('svc'));
        Assert::false($down->serving('svc'));
    }

    public function watchDeliversEveryStatusToTheCallback(): void
    {
        $client = new Client(new HealthClient(new FakeTransport(streamResponses: [
            new HealthCheckResponse(HealthStatus::SERVING),
            new HealthCheckResponse(HealthStatus::NOT_SERVING),
            new HealthCheckResponse(HealthStatus::SERVING),
        ])));

        /** @var list<ServingStatus> $received */
        $received = [];

        $watch = $client->watch('svc', static function (ServingStatus $status) use (&$received): void {
            $received[] = $status;
        });

        delay(0.01);
        $watch->close();

        Assert::same($received, [ServingStatus::Serving, ServingStatus::NotServing, ServingStatus::Serving]);
    }
}

/**
 * @template-implements ClientStream<\Thesis\Grpc\Health\V1\HealthCheckRequest, HealthCheckResponse>
 */
final readonly class FakeClientStream implements ClientStream
{
    /**
     * @param list<HealthCheckResponse> $responses
     */
    public function __construct(
        private array $responses = [],
    ) {}

    #[\Override]
    public function send(object $message): void {}

    #[\Override]
    public function receive(): object
    {
        throw new \LogicException('Not implemented.');
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        yield from $this->responses;
    }

    #[\Override]
    public function headers(): Metadata
    {
        return new Metadata();
    }

    #[\Override]
    public function trailers(Cancellation $cancellation = new NullCancellation()): Metadata
    {
        return new Metadata();
    }

    #[\Override]
    public function close(): void {}
}

final readonly class FakeTransport implements Grpc\Client
{
    /**
     * @param list<HealthCheckResponse> $streamResponses
     */
    public function __construct(
        private ?object $response = null,
        private ?\Throwable $error = null,
        private array $streamResponses = [],
    ) {}

    #[\Override]
    public function invoke(
        object $request,
        Invoke $invoke,
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): object {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->response ?? new HealthCheckResponse(); // @phpstan-ignore return.type
    }

    #[\Override]
    public function createStream(
        Invoke $invoke,
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): ClientStream {
        return new FakeClientStream($this->streamResponses); // @phpstan-ignore return.type
    }

    #[\Override]
    public function close(Cancellation $cancellation = new NullCancellation()): void {}
}
