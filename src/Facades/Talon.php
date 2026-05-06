<?php

declare(strict_types=1);

namespace Actengage\Talon\Facades;

use Illuminate\Support\Facades\Facade;
use Override;

/**
 * @method static string extractFrom(string $body, string $contentType = 'text/html')
 * @method static string extractFromHtml(string $html)
 * @method static string extractFromPlain(string $text)
 *
 * @see \Actengage\Talon\Talon
 */
class Talon extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return \Actengage\Talon\Talon::class;
    }
}
