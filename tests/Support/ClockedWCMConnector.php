<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * WCMConnector with a clock the test sets ($now) and short time limits, so
 * pauses of minutes and timeouts can be tested without waiting.
 */
final class ClockedWCMConnector extends \WCMConnector
{
    protected const CONNECT_TIMEOUT = 0.5;
    protected const REQUEST_TIMEOUT = 0.5;
    protected const NEW_REQUEST_LIMIT = 2.0;

    public float $now = 1000000.0;

    protected function Now(): float
    {
        return $this->now;
    }
}
