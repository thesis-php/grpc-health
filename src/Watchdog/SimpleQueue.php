<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health\Watchdog;

use Amp\Pipeline;

/**
 * @internal
 * @template T
 */
final readonly class SimpleQueue
{
    /** @var Pipeline\Queue<T> */
    private Pipeline\Queue $queue;

    public function __construct()
    {
        $this->queue = new Pipeline\Queue();
    }

    /**
     * @param T $value
     */
    public function pushAsync(mixed $value): void
    {
        if (!$this->queue->isComplete() && !$this->queue->isDisposed()) {
            $this->queue->pushAsync($value)->ignore();
        }
    }

    public function complete(): void
    {
        if (!$this->queue->isComplete() && !$this->queue->isDisposed()) {
            $this->queue->complete();
        }
    }

    /**
     * @return \Traversable<array-key, T>
     */
    public function iterator(): \Traversable
    {
        return $this->queue->iterate();
    }
}
