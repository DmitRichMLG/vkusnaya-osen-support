<?php

namespace Tests\Unit;

use App\Bot\DrawCalendar;
use App\Bot\OperatorHours;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Часы операторов по п. 12.1: будни 9:00–18:00 МСК. Праздники код не учитывает (README, «Известные проблемы»). */
class OperatorHoursTest extends TestCase
{
    private static function msk(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, DrawCalendar::TZ);
    }

    public function test_weekday_inside_hours_is_on_shift(): void
    {
        $this->assertTrue(OperatorHours::isOnShift(self::msk('2026-10-06 10:00'))); // вторник, часы прогона
        $this->assertTrue(OperatorHours::isOnShift(self::msk('2026-10-05 13:30'))); // понедельник
        $this->assertTrue(OperatorHours::isOnShift(self::msk('2026-10-09 17:59:59'))); // пятница
    }

    public function test_weekday_outside_hours_is_off_shift(): void
    {
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-06 08:59:59')));
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-06 20:00')));
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-06 00:00')));
    }

    public function test_nine_is_included_and_eighteen_is_excluded(): void
    {
        $this->assertTrue(OperatorHours::isOnShift(self::msk('2026-10-06 09:00:00')));
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-06 18:00:00')));
    }

    public function test_weekend_is_off_shift_even_inside_hours(): void
    {
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-10 12:00'))); // суббота
        $this->assertFalse(OperatorHours::isOnShift(self::msk('2026-10-11 12:00'))); // воскресенье
    }

    public function test_holiday_counts_as_a_working_day(): void
    {
        // 4 ноября 2026 — среда и праздник. Код смотрит только на день недели и часы,
        // хотя для DrawCalendar это нерабочий день. Закрепляем фактическое поведение (README, «Известные проблемы»).
        $holiday = self::msk('2026-11-04 12:00');

        $this->assertTrue(OperatorHours::isOnShift($holiday));
        $this->assertFalse(DrawCalendar::isWorkingDay($holiday));
    }

    public function test_utc_moment_is_converted_to_moscow(): void
    {
        $this->assertTrue(OperatorHours::isOnShift(CarbonImmutable::parse('2026-10-06 06:30', 'UTC'))); // 09:30 МСК
        $this->assertFalse(OperatorHours::isOnShift(CarbonImmutable::parse('2026-10-06 05:30', 'UTC'))); // 08:30 МСК
        $this->assertTrue(OperatorHours::isOnShift(CarbonImmutable::parse('2026-10-06 14:59', 'UTC'))); // 17:59 МСК
        $this->assertFalse(OperatorHours::isOnShift(CarbonImmutable::parse('2026-10-06 15:00', 'UTC'))); // 18:00 МСК
    }

    public function test_phrase_depends_on_shift(): void
    {
        $onShift = OperatorHours::phrase(self::msk('2026-10-06 10:00'));
        $evening = OperatorHours::phrase(self::msk('2026-10-06 19:00'));
        $saturday = OperatorHours::phrase(self::msk('2026-10-10 12:00'));

        $this->assertSame(__('bot.eta_on_shift'), $onShift);
        $this->assertSame(__('bot.eta_off_shift'), $evening);
        $this->assertSame(__('bot.eta_off_shift'), $saturday);

        $this->assertStringContainsString('на смене', $onShift);
        $this->assertStringNotContainsString('на смене', $evening);
        $this->assertStringContainsString('9:00 до 18:00', $evening); // когда ждать ответ, участнику говорят в любом случае
    }
}
