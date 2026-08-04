<?php

declare(strict_types=1);

namespace Thesis\Grpc\Health\Watchdog;

/**
 * @internal
 */
enum State
{
    case Alive;
    case Dead;
}
