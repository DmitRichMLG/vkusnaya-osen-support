<?php

namespace App\Bot;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Календарь розыгрышей по правилам (п. 2.2–2.5, 8.1–8.3). Считает код, модель только читает.
 * Все даты — по московскому времени.
 */
final class DrawCalendar
{
    public const TZ = 'Europe/Moscow';

    public const FIRST_WEEKLY = '2026-09-08';

    public const LAST_WEEKLY = '2026-11-03';

    public const MAIN_DRAW = '2026-11-10 15:00';

    public const PURCHASES_END = '2026-10-31 23:59';

    public const REGISTRATION_END = '2026-11-02 23:59';

    public const WEEKLY_ONLY_UNTIL = '2026-11-01 23:59'; // п. 8.2: позже — только главный розыгрыш

    public const HOLIDAYS = ['2026-11-04'];

    /** @return list<CarbonImmutable> вторники в 15:00 МСК */
    public static function weeklyDraws(): array
    {
        $draws = [];
        foreach (CarbonPeriod::create(self::FIRST_WEEKLY, '1 week', self::LAST_WEEKLY, CarbonPeriod::IMMUTABLE) as $day) {
            $draws[] = CarbonImmutable::parse($day->format('Y-m-d').' 15:00', self::TZ);
        }

        return $draws;
    }

    /** Неделя регистрации (пн–вс), чеки которой участвуют в розыгрыше. */
    public static function weekFor(CarbonImmutable $draw): array
    {
        return [$draw->subDays(8)->startOfDay(), $draw->subDays(2)->endOfDay()];
    }

    /** Ближайший еженедельный розыгрыш, который ещё не прошёл (включая сегодняшний до 15:00). */
    public static function nextDraw(CarbonImmutable $now): ?CarbonImmutable
    {
        foreach (self::weeklyDraws() as $draw) {
            if ($draw->greaterThan($now)) {
                return $draw;
            }
        }

        return null;
    }

    public static function previousDraw(CarbonImmutable $now): ?CarbonImmutable
    {
        $previous = null;
        foreach (self::weeklyDraws() as $draw) {
            if ($draw->lessThanOrEqualTo($now)) {
                $previous = $draw;
            }
        }

        return $previous;
    }

    /** В какой еженедельный розыгрыш попадает чек, зарегистрированный в этот момент; null — только главный. */
    public static function drawForRegistration(CarbonImmutable $registeredAt): ?CarbonImmutable
    {
        $registeredAt = $registeredAt->setTimezone(self::TZ);
        if ($registeredAt->greaterThan(CarbonImmutable::parse(self::WEEKLY_ONLY_UNTIL, self::TZ)->endOfMinute())) {
            return null;
        }
        foreach (self::weeklyDraws() as $draw) {
            [$from, $to] = self::weekFor($draw);
            if ($registeredAt->between($from, $to)) {
                return $draw;
            }
        }

        return null;
    }

    public static function isWorkingDay(CarbonImmutable $day): bool
    {
        return $day->isWeekday() && ! in_array($day->format('Y-m-d'), self::HOLIDAYS, true);
    }

    /** Текст для промпта: сегодня, ближайшие розыгрыши, полный календарь, рабочие дни. */
    public static function describe(CarbonImmutable $now): string
    {
        $now = $now->setTimezone(self::TZ);
        $lines = [];
        $lines[] = 'Сегодня: '.self::ru($now, 'dddd, D MMMM YYYY').', '.$now->format('H:i').' МСК.';

        $next = self::nextDraw($now);
        $previous = self::previousDraw($now);
        if ($next !== null) {
            [$from, $to] = self::weekFor($next);
            $when = $next->isSameDay($now) ? 'сегодня, '.self::ru($next, 'D MMMM') : self::ru($next, 'D MMMM');
            $lines[] = "Ближайший еженедельный розыгрыш: {$when} в 15:00. В нём участвуют чеки, зарегистрированные с "
                .self::ru($from, 'D MMMM').' по '.self::ru($to, 'D MMMM').' и принятые модерацией до 12:00 '
                .self::ru($next, 'D MMMM').'; не проверенные к этому времени переносятся на следующий еженедельный розыгрыш.';
            $after = self::nextDraw($next);
            if ($after !== null) {
                [$af, $at] = self::weekFor($after);
                $lines[] = 'Следующий после ближайшего: '.self::ru($after, 'D MMMM').' (чеки '.self::ru($af, 'D MMMM').' – '.self::ru($at, 'D MMMM').').';
            }
        } else {
            $lines[] = 'Еженедельные розыгрыши закончились 3 ноября. Впереди только розыгрыш главного приза.';
        }
        if ($previous !== null) {
            [$pf, $pt] = self::weekFor($previous);
            $lines[] = 'Предыдущий еженедельный розыгрыш: '.self::ru($previous, 'D MMMM').' (чеки '.self::ru($pf, 'D MMMM').' – '.self::ru($pt, 'D MMMM').').';
        }
        $today = self::drawForRegistration($now);
        $lines[] = $today !== null
            ? 'Чек, зарегистрированный сегодня, при приёме модерацией попадает в розыгрыш '.self::ru($today, 'D MMMM').'.'
            : 'Чек, зарегистрированный сегодня, участвует только в розыгрыше главного приза.';
        $lines[] = 'Последний еженедельный розыгрыш: 3 ноября (чеки 26 октября – 1 ноября). Чеки, зарегистрированные 2 ноября, участвуют только в розыгрыше главного приза.';
        $lines[] = 'Главный розыгрыш: 10 ноября в 15:00, участвуют все принятые чеки за всю акцию.';
        $lines[] = 'Покупки: до 23:59 31 октября. Регистрация чеков: до 23:59 2 ноября.';

        $all = [];
        foreach (self::weeklyDraws() as $draw) {
            [$f, $t] = self::weekFor($draw);
            $all[] = $draw->format('d.m').' ← '.$f->format('d.m').'–'.$t->format('d.m');
        }
        $lines[] = 'Полный календарь еженедельных розыгрышей (дата розыгрыша ← неделя регистрации): '.implode('; ', $all).'.';

        $working = [];
        foreach (CarbonPeriod::create($now->subWeeks(3)->startOfWeek(), '1 day', $now->addWeeks(2)->endOfWeek(), CarbonPeriod::IMMUTABLE) as $day) {
            if (self::isWorkingDay($day)) {
                $working[] = $day->format('d.m');
            }
        }
        $lines[] = 'Рабочие дни (пн–пт, 4 ноября — праздник): '.implode(', ', $working).'. Сроки в днях отсчитываются со следующего дня после события; последний день срока ещё не просрочка.';

        return implode("\n", $lines);
    }

    private static function ru(CarbonImmutable $date, string $format): string
    {
        return $date->locale('ru')->isoFormat($format);
    }
}
