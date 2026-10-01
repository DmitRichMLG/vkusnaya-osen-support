<?php

namespace Tests\Feature\Panel;

use App\Models\Message;

class TicketPageTest extends PanelTestCase
{
    public function test_page_shows_whole_history_and_decision_cards_with_rule_text(): void
    {
        $p = $this->participant(100, ['username' => 'ivan']);
        $before = $this->inbound($p, 'кефир участвует?', ['created_at' => self::msk('2026-10-05 18:00')]);
        $reply = Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'Нет, кефир не участвует.']);
        $this->decision($before, 'answer', ['reply_message_id' => $reply->id, 'rule_refs' => ['4.2'], 'model' => 'gemini-test']);

        $ticket = $this->ticket($p);
        $question = $this->inbound($p, "почему отклонили чек\nномер 1234", ['content_type' => 'photo']);
        $this->decision($question, 'operator', ['ticket_id' => $ticket->id, 'rule_refs' => ['6.4', '13.7'], 'operator_summary' => 'Отклонён чек, нужна причина', 'model' => 'gemini-test']);

        $response = $this->get("/tickets/{$ticket->id}")->assertOk();

        $response->assertSee('Обращение №'.$ticket->id);
        $response->assertSee('Иван Тестов')->assertSee('@ivan')->assertSee('06.10.2026 08:30');
        $response->assertSeeInOrder(['Участник', '05.10.2026 18:00', 'кефир участвует?', 'ответил сам', 'gemini-test', 'п. 4.2', 'кефир, ряженка и молоко']);
        $response->assertSee('Нет, кефир не участвует.');
        $response->assertSee('[фото]');
        $response->assertSee('почему отклонили чек<br />', false);
        $response->assertSeeInOrder(['передано оператору', 'Отклонён чек, нужна причина', 'п. 6.4', 'п. 13.7', 'нет в правилах']);
        $response->assertSee('Закрыть обращение');
        $response->assertSee('name="text"', false);
    }

    public function test_closed_ticket_has_no_forms(): void
    {
        $p = $this->participant();
        $ticket = $this->ticket($p, '2026-10-05 12:00', ['closed_at' => self::msk('2026-10-05 13:00'), 'closed_by' => $this->operator->id]);

        $response = $this->get("/tickets/{$ticket->id}")->assertOk();

        $response->assertSee('Обращение закрыто');
        $response->assertSee('закрыто 05.10.2026 13:00, Мария');
        $response->assertDontSee('Закрыть обращение');
        $response->assertDontSee('name="text"', false);
    }

    public function test_unknown_ticket_is_404(): void
    {
        $this->get('/tickets/999')->assertNotFound();
    }
}
