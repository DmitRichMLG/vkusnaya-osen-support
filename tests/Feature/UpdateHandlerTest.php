<?php

namespace Tests\Feature;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Participant;
use App\Models\Ticket;
use App\Support\PromoClock;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Telegram и Gemini подменены: проверяем конвейер от апдейта до ответа и записей в базе. */
class UpdateHandlerTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'api.telegram.org/*';

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private int $messageId = 100;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promo.gemini.models' => ['model-a']]);
        PromoClock::freeze('2026-10-06 10:00');
    }

    protected function tearDown(): void
    {
        PromoClock::unfreeze();
        parent::tearDown();
    }

    private static function telegramOk(): array
    {
        return ['ok' => true, 'result' => ['message_id' => 555]];
    }

    private static function gemini(string $action, string $text, array $refs = [], string $summary = ''): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(
            ['action' => $action, 'text' => $text, 'operator_summary' => $summary, 'rule_refs' => $refs], JSON_UNESCAPED_UNICODE,
        )]]]]]];
    }

    private function update(?string $text, array $extra = [], int $userId = 42, string $chatType = 'private'): array
    {
        $message = [
            'message_id' => ++$this->messageId,
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Дмитрий', 'username' => 'dmitry'],
            'chat' => ['id' => $userId, 'type' => $chatType],
            'date' => 1_790_000_000,
        ] + $extra;
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => $this->messageId, 'message' => $message];
    }

    private function handle(array $update): void
    {
        app(UpdateHandler::class)->handle($update);
    }

    private static function isSend(Request $r): bool
    {
        return str_contains($r->url(), '/sendMessage');
    }

    /** Текст пользовательского хода, который ушёл в модель. */
    private static function prompt(Request $r): string
    {
        return str_contains($r->url(), 'generateContent') ? (string) $r['contents'][0]['parts'][0]['text'] : '';
    }

    public function test_question_is_answered_and_everything_is_recorded(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('answer', 'Нет, кефир не участвует.', ['4.2'])), self::TG => Http::response(self::telegramOk())]);

        $this->handle($this->update('кефир участвует?'));

        $participant = Participant::where('telegram_user_id', 42)->firstOrFail();
        $this->assertSame('Дмитрий', $participant->first_name);
        $inbound = $participant->messages()->where('author', 'participant')->firstOrFail();
        $this->assertSame('кефир участвует?', $inbound->text);
        $reply = $participant->messages()->where('author', 'bot')->firstOrFail();
        $this->assertSame('Нет, кефир не участвует.', $reply->text);
        $this->assertNull($reply->telegram_message_id);
        $decision = $inbound->decision;
        $this->assertSame('answer', $decision->action);
        $this->assertSame(['4.2'], $decision->rule_refs);
        $this->assertSame($reply->id, $decision->reply_message_id);
        $this->assertNull($decision->ticket_id);
        $this->assertSame(0, Ticket::count());
        Http::assertSent(fn (Request $r) => self::isSend($r) && $r['chat_id'] === 42 && $r['text'] === 'Нет, кефир не участвует.');
    }

    public function test_duplicate_update_is_ignored(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('smalltalk', 'Привет!')), self::TG => Http::response(self::telegramOk())]);
        $update = $this->update('привет');

        $this->handle($update);
        $this->handle($update);

        $this->assertSame(2, Message::count());
        $this->assertSame(1, BotDecision::count());
        Http::assertSentCount(2);
    }

    public function test_start_command_is_answered_by_code(): void
    {
        Http::fake([self::TG => Http::response(self::telegramOk())]);

        $this->handle($this->update('/start'));

        $this->assertSame('start', BotDecision::first()->reason);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => self::isSend($r) && str_contains($r['text'], 'Я бот поддержки акции'));
    }

    public function test_card_number_is_masked_before_saving_and_before_model(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('operator', 'Передаю оператору.', ['7.4'], 'Просит деньги вместо приза.')), self::TG => Http::response(self::telegramOk())]);

        $this->handle($this->update('переведите на карту 2200 1234 5678 9012'));

        $this->assertSame('переведите на карту **** **** **** 9012', Message::where('author', 'participant')->value('text'));
        Http::assertSent(fn (Request $r) => str_contains(self::prompt($r), '**** **** **** 9012') && ! str_contains(self::prompt($r), '2200 1234'));
    }

    public function test_operator_action_opens_ticket_and_appends_next_time(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('operator', 'Передаю оператору.', [], 'Где приз.')), self::TG => Http::response(self::telegramOk())]);

        $this->handle($this->update('где мой приз?'));
        $this->handle($this->update('ну что там?'));

        $participant = Participant::firstOrFail();
        $this->assertSame(1, Ticket::count());
        $ticket = $participant->openTicket()->firstOrFail();
        $this->assertSame('2026-10-06T07:00:00+00:00', $ticket->opened_at->toIso8601String());
        $this->assertSame(2, BotDecision::where('ticket_id', $ticket->id)->count());
        $this->assertSame(2, Message::where('author', 'participant')->where('ticket_id', $ticket->id)->count());
        Http::assertSent(fn (Request $r) => self::isSend($r) && str_contains($r['text'], 'Операторы сейчас на смене'));
        // Модель видит суть открытого обращения и историю.
        Http::assertSent(fn (Request $r) => str_contains(self::prompt($r), 'Где приз.') && str_contains(self::prompt($r), 'Участник: где мой приз?') && str_contains(self::prompt($r), '«ну что там?»'));
    }

    public function test_after_closing_a_new_ticket_is_opened(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('operator', 'Передаю оператору.', [], 'x')), self::TG => Http::response(self::telegramOk())]);
        $this->handle($this->update('где мой приз?'));
        Ticket::query()->update(['closed_at' => PromoClock::now()]);

        $this->handle($this->update('а теперь где чек?'));

        $this->assertSame(2, Ticket::count());
        $this->assertSame(1, Ticket::whereNull('closed_at')->count());
    }

    public function test_sticker_without_open_ticket_asks_for_text(): void
    {
        Http::fake([self::TG => Http::response(self::telegramOk())]);

        $this->handle($this->update(null, ['sticker' => ['file_id' => 'abc']]));

        $this->assertSame('other', Message::where('author', 'participant')->value('content_type'));
        $this->assertSame('no_text', BotDecision::first()->reason);
        Http::assertSent(fn (Request $r) => self::isSend($r) && $r['text'] === __('bot.no_text'));
    }

    public function test_photo_with_open_ticket_is_attached_to_it(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('operator', 'Пришлите фото чека.', [], 'x')), self::TG => Http::response(self::telegramOk())]);
        $this->handle($this->update('почему отклонили чек'));

        $this->handle($this->update(null, ['photo' => [['file_id' => 'p1']]]));

        $ticket = Ticket::firstOrFail();
        $photo = Message::where('content_type', 'photo')->firstOrFail();
        $this->assertSame($ticket->id, $photo->ticket_id);
        $this->assertSame('media_to_ticket', $photo->decision->reason);
        Http::assertSent(fn (Request $r) => self::isSend($r) && $r['text'] === __('bot.photo_saved'));
    }

    public function test_group_messages_and_bots_are_ignored(): void
    {
        Http::fake();

        $this->handle($this->update('привет', [], 42, 'group'));
        $this->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => 7, 'is_bot' => true], 'chat' => ['id' => 7, 'type' => 'private'], 'text' => 'x']]);
        $this->handle(['update_id' => 2, 'edited_message' => ['message_id' => 1]]);

        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    public function test_telegram_failure_keeps_decision_without_reply(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini('answer', 'Нет.', ['4.2'])), self::TG => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->handle($this->update('кефир?'));

        $decision = BotDecision::firstOrFail();
        $this->assertSame('answer', $decision->action);
        $this->assertNull($decision->reply_message_id);
        $this->assertSame(1, Message::count());
    }

    public function test_poll_once_processes_pending_updates_and_stores_offset(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'vkusen2_bot']]),
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update('/start'), $this->update('/start', [], 43)]]),
            'api.telegram.org/*/sendMessage' => Http::response(self::telegramOk()),
        ]);

        $this->artisan('bot:poll', ['--once' => true])->assertExitCode(0);

        $this->assertSame(2, Participant::count());
        $this->assertSame($this->messageId + 1, cache('telegram.offset'));
    }
}
