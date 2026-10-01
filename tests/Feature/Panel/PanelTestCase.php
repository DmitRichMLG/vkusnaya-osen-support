<?php

namespace Tests\Feature\Panel;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Participant;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PromoClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Общее для тестов панели: оператор в сессии, часы заморожены на 06.10.2026 10:00 МСК. */
abstract class PanelTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        PromoClock::freeze('2026-10-06 10:00');
        $this->operator = User::factory()->create(['name' => 'Мария']);
        $this->actingAs($this->operator);
    }

    protected function tearDown(): void
    {
        PromoClock::unfreeze();
        parent::tearDown();
    }

    /** Первый подходящий стаб побеждает, поэтому ответ Telegram задаётся один раз на тест. */
    protected function telegramResponds(array $body = ['ok' => true, 'result' => ['message_id' => 1]], int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);
    }

    /** Московское время → UTC. */
    protected static function msk(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'Europe/Moscow')->utc();
    }

    protected function participant(int $telegramUserId = 100, array $attributes = []): Participant
    {
        return Participant::create($attributes + ['telegram_user_id' => $telegramUserId, 'first_name' => 'Иван', 'last_name' => 'Тестов']);
    }

    protected function ticket(Participant $participant, string $openedAtMsk = '2026-10-06 08:30', array $attributes = []): Ticket
    {
        return Ticket::create($attributes + ['participant_id' => $participant->id, 'opened_at' => self::msk($openedAtMsk)]);
    }

    protected function inbound(Participant $participant, string $text, array $attributes = []): Message
    {
        return Message::create($attributes + ['participant_id' => $participant->id, 'author' => Message::AUTHOR_PARTICIPANT, 'text' => $text]);
    }

    protected function decision(Message $inbound, string $action, array $attributes = []): BotDecision
    {
        return BotDecision::create($attributes + ['message_id' => $inbound->id, 'action' => $action, 'reason' => 'model']);
    }
}
