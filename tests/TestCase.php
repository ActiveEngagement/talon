<?php

declare(strict_types=1);

namespace Actengage\Talon\Tests;

use Actengage\Talon\TalonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;

abstract class TestCase extends BaseTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [TalonServiceProvider::class];
    }
}
