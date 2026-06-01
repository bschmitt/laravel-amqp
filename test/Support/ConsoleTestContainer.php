<?php

namespace Bschmitt\Amqp\Test\Support;

use Illuminate\Container\Container;

/**
 * Minimal container stub for exercising Illuminate console commands in unit
 * tests without booting a full {@see \Illuminate\Foundation\Application}.
 *
 * Laravel 10+ console commands call {@see runningUnitTests()} (and often
 * {@see runningInConsole()}) on the application instance during
 * {@see \Illuminate\Console\Command::run()}. A bare {@see Container} does not
 * provide those methods, which breaks {@see CommandTester} runs in CI.
 */
class ConsoleTestContainer extends Container
{
    /**
     * @return bool
     */
    public function runningUnitTests()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function runningInConsole()
    {
        return true;
    }
}
