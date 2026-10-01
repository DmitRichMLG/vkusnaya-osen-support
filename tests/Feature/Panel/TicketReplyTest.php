<?php

namespace Tests\Feature\Panel;

use App\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TicketReplyTest extends PanelTestCase
{
    public function test_reply_goes_to_telegram_and_is_saved(): void
    {
        $this->telegramResponds();
        $p = $this->participant(555);
        $ticket = $this->ticket($p);

        $this->from("/tickets/{$ticket->id}")
            ->post("/tickets/{$ticket->id}/reply", ['text' => 'Чек проверили, всё в порядке.'])
            ->assertRedirect("/tickets/{$ticket->id}")
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === 555
            && str_starts_with($r['text'], 'Ответ оператора:')
            && str_contains($r['text'], 'Чек проверили, всё в порядке.'));

        $this->assertDatabaseHas('messages', [
            'participant_id' => $p->id,
            'ticket_id' => $ticket->id,
            'author' => Message::AUTHOR_OPERATOR,
            'operator_id' => $this->operator->id,
            'content_type' => 'text',
            'text' => 'Чек проверили, всё в порядке.',
        ]);
    }

    public function test_card_number_in_operator_reply_is_masked(): void
    {
        $this->telegramResponds();
        $ticket = $this->ticket($this->participant());

        $this->post("/tickets/{$ticket->id}/reply", ['text' => 'Ваша карта 2200 1234 5678 9012 нам не нужна']);

        Http::assertSent(fn (Request $r) => str_contains($r['text'], '**** **** **** 9012') && ! str_contains($r['text'], '2200 1234'));
        $this->assertDatabaseHas('messages', ['text' => 'Ваша карта **** **** **** 9012 нам не нужна']);
    }

    public function test_nothing_is_saved_when_telegram_rejects(): void
    {
        $this->telegramResponds(['ok' => false, 'description' => 'Bad Request: chat not found'], 400);
        $ticket = $this->ticket($this->participant());

        $this->from("/tickets/{$ticket->id}")
            ->post("/tickets/{$ticket->id}/reply", ['text' => 'Ответ'])
            ->assertRedirect("/tickets/{$ticket->id}")
            ->assertSessionHas('error', fn (string $e) => str_starts_with($e, 'Не удалось отправить в Telegram:') && str_contains($e, 'chat not found'));

        $this->assertSame(0, Message::count());
    }

    public function test_empty_or_too_long_text_is_rejected(): void
    {
        $this->telegramResponds();
        $ticket = $this->ticket($this->participant());

        $this->post("/tickets/{$ticket->id}/reply", ['text' => '   '])->assertSessionHasErrors('text');
        $this->post("/tickets/{$ticket->id}/reply", ['text' => str_repeat('а', 4001)])->assertSessionHasErrors('text');

        Http::assertNothingSent();
        $this->assertSame(0, Message::count());
    }

    public function test_closed_ticket_rejects_reply(): void
    {
        $this->telegramResponds();
        $ticket = $this->ticket($this->participant(), '2026-10-05 12:00', ['closed_at' => self::msk('2026-10-05 13:00')]);

        $this->post("/tickets/{$ticket->id}/reply", ['text' => 'Ответ'])->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, Message::count());
    }
}
