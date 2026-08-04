<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health;

use Amp\Cancellation;
use Amp\NullCancellation;

/**
 * The single source of truth for the health of every service in the process.
 * Register it once in your container and share it through its narrow ports: the
 * application reports through {@see Reporter}, operators drain through
 * {@see Lifecycle}, and the gRPC {@see Server} adapter reads and subscribes
 * through {@see Monitor}.
 *
 * @api
 */
final class Watchdog implements
    Reporter,
    Lifecycle,
    Monitor
{
    /**
     * @var array<string, ServingStatus>
     */
    private array $statuses = [
        '' => ServingStatus::Serving,
    ];

    /**
     * @var array<string, array<string, Watchdog\SimpleQueue<ServingStatus>>>
     */
    private array $watchers = [];

    /** @var non-empty-string */
    private string $watcherId = 'a';

    private Watchdog\State $state = Watchdog\State::Alive;

    #[\Override]
    public function report(string $service, ServingStatus $status): void
    {
        if ($this->state === Watchdog\State::Alive) {
            $this->setStatus($service, $status);
        }
    }

    #[\Override]
    public function shutdown(): void
    {
        if ($this->state === Watchdog\State::Alive) {
            $this->state = Watchdog\State::Dead;

            foreach (array_keys($this->statuses) as $service) {
                $this->setStatus($service, ServingStatus::NotServing);
            }
        }
    }

    #[\Override]
    public function resume(): void
    {
        if ($this->state === Watchdog\State::Dead) {
            $this->state = Watchdog\State::Alive;

            foreach (array_keys($this->statuses) as $service) {
                $this->setStatus($service, ServingStatus::Serving);
            }
        }
    }

    #[\Override]
    public function statusOf(string $service): ?ServingStatus
    {
        return $this->statuses[$service] ?? null;
    }

    #[\Override]
    public function snapshot(): array
    {
        return $this->statuses;
    }

    #[\Override]
    public function subscribe(
        string $service,
        Cancellation $cancellation = new NullCancellation(),
    ): iterable {
        $id = $this->watcherId;
        $this->watcherId = str_increment($this->watcherId);

        /** @var Watchdog\SimpleQueue<ServingStatus> $queue */
        $queue = new Watchdog\SimpleQueue();
        $this->watchers[$service][$id] = $queue;

        $queue->pushAsync($this->statuses[$service] ?? ServingStatus::ServiceUnknown);

        $cancellationId = $cancellation->subscribe($queue->complete(...));

        try {
            yield from $queue->iterator();
        } finally {
            $cancellation->unsubscribe($cancellationId);

            unset($this->watchers[$service][$id]);

            if (($this->watchers[$service] ?? []) === []) {
                unset($this->watchers[$service]);
            }

            $queue->complete();
        }
    }

    private function setStatus(string $service, ServingStatus $status): void
    {
        if (($this->statuses[$service] ?? null) === $status) {
            return;
        }

        $this->statuses[$service] = $status;

        foreach ($this->watchers[$service] ?? [] as $queue) {
            $queue->pushAsync($status);
        }
    }
}
