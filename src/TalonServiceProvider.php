<?php

declare(strict_types=1);

namespace Actengage\Talon;

use Illuminate\Support\ServiceProvider;
use Override;

class TalonServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(Talon::class, fn (): Talon => new Talon);
    }
}
