<?php

namespace Tests\Unit;

use App\Bot\DrawCalendar;
use App\Bot\OperatorHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DrawCalendarTest extends TestCase
{
    private function msk(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, DrawCalendar::TZ);
    }

    public function test_nine_weekly_draws_on_tuesdays(): void
    {
        $draws = DrawCalendar::weeklyDraws();

        $this->assertCount(9, $draws);
        $this->assertSame('2026-09-08 15:00', $draws[0]->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 15:00', $draws[8]->format('Y-m-d H:i'));
        foreach ($draws as $draw) {
            $this->assertTrue($draw->isTuesday());
        }
    }

    public function test_on_draw_day_before_15_the_next_draw_is_today(): void
    {
        $now = $this->msk('2026-10-06 10:00');

        $this->assertSame('2026-10-06', DrawCalendar::nextDraw($now)->format('Y-m-d'));
        $this->assertSame('2026-09-29', DrawCalendar::previousDraw($now)->format('Y-m-d'));
        [$from, $to] = DrawCalendar::weekFor(DrawCalendar::nextDraw($now));
        $this->assertSame('2026-09-28', $from->format('Y-m-d'));
        $this->assertSame('2026-10-04', $to->format('Y-m-d'));
    }

    public function test_after_15_on_draw_day_the_next_draw_is_next_week(): void
    {
        $now = $this->msk('2026-10-06 16:00');

        $this->assertSame('2026-10-13', DrawCalendar::nextDraw($now)->format('Y-m-d'));
        $this->assertSame('2026-10-06', DrawCalendar::previousDraw($now)->format('Y-m-d'));
    }

    public function test_registration_maps_to_the_following_tuesday(): void
    {
        $this->assertSame('2026-09-29', DrawCalendar::drawForRegistration($this->msk('2026-09-27 23:00'))->format('Y-m-d'));
        $this->assertSame('2026-10-13', DrawCalendar::drawForRegistration($this->msk('2026-10-06 10:00'))->format('Y-m-d'));
        $this->assertSame('2026-11-03', DrawCalendar::drawForRegistration($this->msk('2026-11-01 23:59'))->format('Y-m-d'));
        $this->assertNull(DrawCalendar::drawForRegistration($this->msk('2026-11-02 00:30')));
    }

    public function test_after_last_weekly_draw_only_main_remains(): void
    {
        $now = $this->msk('2026-11-05 12:00');

        $this->assertNull(DrawCalendar::nextDraw($now));
        $this->assertStringContainsString('только розыгрыш главного приза', DrawCalendar::describe($now));
        // Регистрация закрыта 2 ноября (п. 2.3): модель не должна думать, что сегодняшний чек куда-то попадёт.
        $this->assertStringContainsString('новые чеки не принимаются', DrawCalendar::describe($now));
        $this->assertStringNotContainsString('зарегистрированный сегодня', DrawCalendar::describe($now));
    }

    public function test_after_main_draw_the_calendar_says_it_is_over(): void
    {
        $text = DrawCalendar::describe($this->msk('2026-11-11 10:00'));

        $this->assertStringContainsString('главный розыгрыш прошёл 10 ноября', $text);
        $this->assertStringContainsString('Главный розыгрыш прошёл 10 ноября в 15:00', $text);
        $this->assertStringNotContainsString('Впереди только', $text);
        $this->assertStringNotContainsString('зарегистрированный сегодня', $text);
    }

    public function test_describe_mentions_today_and_week(): void
    {
        $text = DrawCalendar::describe($this->msk('2026-10-06 10:00'));

        $this->assertStringContainsString('Сегодня: вторник, 6 октября 2026, 10:00 МСК', $text);
        $this->assertStringContainsString('сегодня, 6 октября в 15:00', $text);
        $this->assertStringContainsString('с 28 сентября по 4 октября', $text);
        $this->assertStringContainsString('Предыдущий еженедельный розыгрыш: 29 сентября', $text);
        $this->assertStringContainsString('06.10 ← 28.09–04.10', $text);
    }

    public function test_describe_on_last_registration_day_and_holiday_handling(): void
    {
        $text = DrawCalendar::describe($this->msk('2026-11-02 10:00'));
        $working = explode('Рабочие дни', $text)[1];

        $this->assertStringContainsString('Ближайший еженедельный розыгрыш: 3 ноября', $text);
        $this->assertStringContainsString('Чек, зарегистрированный сегодня, участвует только в розыгрыше главного приза', $text);
        $this->assertStringContainsString('03.11', $working);
        $this->assertStringContainsString('05.11', $working);
        $this->assertStringNotContainsString('04.11', $working);
        $this->assertSame('2026-11-03', DrawCalendar::drawForRegistration($this->msk('2026-11-01 23:59:59'))->format('Y-m-d'));
        $this->assertNull(DrawCalendar::nextDraw($this->msk('2026-11-03 15:00:01')));
    }

    public function test_operator_hours(): void
    {
        $this->assertTrue(OperatorHours::isOnShift($this->msk('2026-10-06 10:00')));
        $this->assertFalse(OperatorHours::isOnShift($this->msk('2026-10-06 18:00')));
        $this->assertFalse(OperatorHours::isOnShift($this->msk('2026-10-10 12:00')));
        $this->assertTrue(OperatorHours::isOnShift($this->msk('2026-10-09 17:59')->utc()));
    }
}
