<?php

namespace App\Bot;

use Carbon\CarbonImmutable;

/** Часы операторов по п. 12.1: будни 9:00–18:00 МСК. Фразу о сроке ответа пишет код, не модель. */
final class OperatorHours
{
    public static function isOnShift(CarbonImmutable $now): bool
    {
        $msk = $now->setTimezone(DrawCalendar::TZ);

        return $msk->isWeekday() && $msk->hour >= 9 && $msk->hour < 18;
    }

    public static function phrase(CarbonImmutable $now): string
    {
        return __(self::isOnShift($now) ? 'bot.eta_on_shift' : 'bot.eta_off_shift');
    }
}
