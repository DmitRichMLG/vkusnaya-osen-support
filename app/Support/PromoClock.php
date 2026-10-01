<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Единственный источник «сейчас». В базу уходит только UTC, в МСК переводим
 * при показе и при расчёте сроков и розыгрышей. В тестах и при прогоне
 * обращений время замораживается через freeze().
 */
final class PromoClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function msk(): CarbonImmutable
    {
        return self::now()->setTimezone(config('promo.timezone'));
    }

    /** Принимает любое время, которое понимает Carbon: без смещения считается московским. */
    public static function freeze(string $moment): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, config('promo.timezone'))->utc());
    }

    public static function unfreeze(): void
    {
        CarbonImmutable::setTestNow();
    }
}
