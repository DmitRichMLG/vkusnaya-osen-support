<?php

namespace Tests\Feature\Panel;

use App\Models\Message;

class TicketQueueTest extends PanelTestCase
{
    public function test_open_tickets_are_listed_oldest_first_with_waiting_time_and_summary(): void
    {
        $late = $this->participant(1, ['first_name' => 'Поздний', 'last_name' => '']);
        $early = $this->participant(2, ['first_name' => 'Ранний', 'last_name' => '']);
        $this->ticket($late, '2026-10-06 09:45');
        $ticket = $this->ticket($early, '2026-10-06 08:30');
        $this->decision($this->inbound($early, 'почему отклонили чек'), 'operator', ['ticket_id' => $ticket->id, 'operator_summary' => 'Старая суть']);
        $this->decision($this->inbound($early, 'ещё вопрос'), 'operator', ['ticket_id' => $ticket->id, 'operator_summary' => 'Отклонён чек, нужна причина']);
        $closed = $this->participant(3, ['first_name' => 'Закрытый', 'last_name' => '']);
        $this->ticket($closed, '2026-10-05 12:00', ['closed_at' => self::msk('2026-10-05 13:00'), 'closed_by' => $this->operator->id]);

        $response = $this->get('/tickets')->assertOk();

        $response->assertSeeInOrder(['Ранний', '06.10.2026 08:30', '1 ч 30 мин', 'Отклонён чек, нужна причина', 'Поздний', '15 мин']);
        $response->assertDontSee('Старая суть');
        $response->assertDontSee('Закрытый');
        $response->assertSee('Очередь (2)');
        $response->assertSee('http-equiv="refresh"', false);
    }

    public function test_answered_ticket_shows_answered_instead_of_waiting_time(): void
    {
        $p = $this->participant();
        $ticket = $this->ticket($p);
        Message::create(['participant_id' => $p->id, 'ticket_id' => $ticket->id, 'author' => Message::AUTHOR_OPERATOR, 'operator_id' => $this->operator->id, 'text' => 'Ответ']);

        $this->get('/tickets')->assertOk()->assertSee('отвечено')->assertDontSee('1 ч 30 мин');
    }

    public function test_closed_filter_lists_only_closed_tickets_newest_first(): void
    {
        $this->ticket($this->participant(1, ['first_name' => 'Открытый', 'last_name' => '']));
        $older = $this->participant(2, ['first_name' => 'Старее', 'last_name' => '']);
        $newer = $this->participant(3, ['first_name' => 'Новее', 'last_name' => '']);
        $this->ticket($older, '2026-10-05 12:00', ['closed_at' => self::msk('2026-10-05 13:00')]);
        $this->ticket($newer, '2026-10-05 14:00', ['closed_at' => self::msk('2026-10-05 15:00')]);

        $response = $this->get('/tickets?status=closed')->assertOk();

        $response->assertSeeInOrder(['Новее', 'закрыто 05.10.2026 15:00', 'Старее', 'закрыто 05.10.2026 13:00']);
        $response->assertDontSee('Открытый');
    }
}
