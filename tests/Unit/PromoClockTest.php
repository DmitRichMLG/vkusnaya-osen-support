<?php

namespace Tests\Unit;

use App\Support\PromoClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Единственный источник «сейчас»: внутри UTC, наружу МСК. Заморозка — как у bot:eval через --now. */
class PromoClockTest extends TestCase
{
    protected function tearDown(): void
    {
        PromoClock::unfreeze();
        parent::tearDown();
    }

    public function test_now_is_immutable_utc(): void
    {
        $now = PromoClock::now();

        $this->assertInstanceOf(CarbonImmutable::class, $now);
        $this->assertSame('UTC', $now->timezoneName);
        $this->assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
    }

    public function test_freeze_reads_moment_as_moscow_time(): void
    {
        PromoClock::freeze('2026-10-06 10:00'); // значение --now по умолчанию

        $this->assertSame('2026-10-06T07:00:00+00:00', PromoClock::now()->toIso8601String());
        $this->assertSame('UTC', PromoClock::now()->timezoneName);
        $this->assertSame('2026-10-06T10:00:00+03:00', PromoClock::msk()->toIso8601String());
        $this->assertSame('Europe/Moscow', PromoClock::msk()->timezoneName);
        $this->assertTrue(PromoClock::msk()->isTuesday());
    }

    public function test_freeze_respects_explicit_offset(): void
    {
        PromoClock::freeze('2026-10-06T10:00+03:00'); // форма из docs/expected-answers.md
        $this->assertSame('2026-10-06T07:00:00+00:00', PromoClock::now()->toIso8601String());

        PromoClock::freeze('2026-10-06T10:00:00Z');
        $this->assertSame('2026-10-06T13:00:00+03:00', PromoClock::msk()->toIso8601String());
    }

    public function test_frozen_time_does_not_tick_and_is_shared_with_carbon(): void
    {
        PromoClock::freeze('2026-10-06 10:00');

        $this->assertTrue(CarbonImmutable::hasTestNow());
        $this->assertTrue(PromoClock::now()->equalTo(PromoClock::now()));
        $this->assertSame('2026-10-06 07:00:00', CarbonImmutable::now()->format('Y-m-d H:i:s'));
    }

    public function test_msk_date_can_differ_from_utc_date(): void
    {
        PromoClock::freeze('2026-10-06 01:30');

        $this->assertSame('2026-10-05', PromoClock::now()->format('Y-m-d')); // в UTC ещё 5 октября
        $this->assertSame('2026-10-06', PromoClock::msk()->format('Y-m-d'));
    }

    public function test_unfreeze_returns_to_real_time(): void
    {
        PromoClock::freeze('2026-10-06 10:00');
        PromoClock::unfreeze();

        $this->assertFalse(CarbonImmutable::hasTestNow());
        $this->assertEqualsWithDelta(time(), PromoClock::now()->getTimestamp(), 2);
    }
}
