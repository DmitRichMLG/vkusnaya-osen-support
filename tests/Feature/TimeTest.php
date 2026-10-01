<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Participant;
use App\Support\PromoClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Время хранится в UTC и не съезжает при записи и чтении московских значений. */
class TimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PromoClock::unfreeze();
        parent::tearDown();
    }

    public function test_app_and_database_sessions_run_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
    }

    public function test_moscow_time_round_trips_without_shift(): void
    {
        $p = Participant::create(['telegram_user_id' => 1]);
        $msk = CarbonImmutable::parse('2026-10-06 10:00:00', 'Europe/Moscow');

        $m = Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'x', 'created_at' => $msk]);
        $fresh = Message::findOrFail($m->id);

        $this->assertTrue($fresh->created_at->equalTo($msk));
        $this->assertSame('2026-10-06T07:00:00+00:00', $fresh->created_at->utc()->toIso8601String());
        $this->assertSame('2026-10-06 07:00:00+00', DB::table('messages')->value('created_at'));
    }

    public function test_queries_with_utc_bounds_find_moscow_moments(): void
    {
        $p = Participant::create(['telegram_user_id' => 1]);
        Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'x',
            'created_at' => CarbonImmutable::parse('2026-10-06 00:30:00', 'Europe/Moscow')]);

        // Московские сутки 06.10 = 05.10 21:00 — 06.10 21:00 UTC.
        $from = CarbonImmutable::parse('2026-10-06 00:00:00', 'Europe/Moscow')->utc();
        $to = $from->addDay();

        $this->assertSame(1, Message::whereBetween('created_at', [$from, $to])->count());
        $this->assertSame(0, Message::whereBetween('created_at', [$from->subDay(), $from])->count());
    }

    public function test_frozen_clock_is_moscow_by_default_and_utc_inside(): void
    {
        PromoClock::freeze('2026-10-06 10:00');

        $this->assertSame('2026-10-06T07:00:00+00:00', PromoClock::now()->toIso8601String());
        $this->assertSame('2026-10-06T10:00:00+03:00', PromoClock::msk()->toIso8601String());
        $this->assertSame('Tuesday', PromoClock::msk()->englishDayOfWeek);

        $p = Participant::create(['telegram_user_id' => 1]);
        $this->assertSame('2026-10-06T07:00:00+00:00', $p->created_at->toIso8601String());
    }
}
