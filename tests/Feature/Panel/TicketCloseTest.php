<?php

namespace Tests\Feature\Panel;

use App\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TicketCloseTest extends PanelTestCase
{
    public function test_close_marks_ticket_notifies_participant_and_saves_bot_message(): void
    {
        $this->telegramResponds();
        $p = $this->participant(555);
        $ticket = $this->ticket($p);

        $this->post("/tickets/{$ticket->id}/close")->assertRedirect('/tickets')->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame('2026-10-06T07:00:00+00:00', $ticket->closed_at->toIso8601String());
        $this->assertSame($this->operator->id, $ticket->closed_by);

        Http::assertSent(fn (Request $r) => $r['chat_id'] === 555 && $r['text'] === __('bot.ticket_closed'));
        $this->assertDatabaseHas('messages', [
            'participant_id' => $p->id,
            'ticket_id' => $ticket->id,
            'author' => Message::AUTHOR_BOT,
            'text' => __('bot.ticket_closed'),
        ]);
    }

    public function test_ticket_is_closed_even_when_telegram_is_down(): void
    {
        $this->telegramResponds([], 500);
        $ticket = $this->ticket($this->participant());

        $this->post("/tickets/{$ticket->id}/close")->assertRedirect('/tickets')->assertSessionHas('warning');

        $this->assertNotNull($ticket->refresh()->closed_at);
        $this->assertSame(0, Message::count());
    }

    public function test_closing_twice_does_nothing(): void
    {
        $this->telegramResponds();
        $ticket = $this->ticket($this->participant(), '2026-10-05 12:00', ['closed_at' => self::msk('2026-10-05 13:00')]);

        $this->post("/tickets/{$ticket->id}/close")->assertRedirect('/tickets')->assertSessionHas('error');

        $this->assertSame('2026-10-05T10:00:00+00:00', $ticket->refresh()->closed_at->toIso8601String());
        Http::assertNothingSent();
    }
}
