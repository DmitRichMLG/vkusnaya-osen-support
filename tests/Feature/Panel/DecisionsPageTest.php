<?php

namespace Tests\Feature\Panel;

use App\Models\Message;

class DecisionsPageTest extends PanelTestCase
{
    public function test_decisions_are_listed_newest_first_with_reply_and_ticket_link(): void
    {
        $p = $this->participant();
        $reply = Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'Нет, кефир не участвует.']);
        $this->decision($this->inbound($p, 'кефир участвует?'), 'answer', ['reply_message_id' => $reply->id, 'rule_refs' => ['4.2'], 'model' => 'gemini-test']);
        $ticket = $this->ticket($p);
        $this->decision($this->inbound($p, 'где мой приз'), 'operator', ['ticket_id' => $ticket->id, 'reason' => 'llm_error']);

        $response = $this->get('/decisions')->assertOk();

        $response->assertSeeInOrder(['где мой приз', 'передано оператору', 'ИИ не ответил', "/tickets/{$ticket->id}", 'кефир участвует?', 'ответил сам', 'gemini-test', '4.2', 'Нет, кефир не участвует.']);
    }
}
