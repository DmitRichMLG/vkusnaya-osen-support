<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Participant;
use App\Models\Ticket;
use App\Support\PromoClock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private function participant(): Participant
    {
        return Participant::create(['telegram_user_id' => 100500]);
    }

    public function test_participant_cannot_have_two_open_tickets(): void
    {
        $p = $this->participant();
        Ticket::create(['participant_id' => $p->id, 'opened_at' => PromoClock::now()]);

        $this->expectException(QueryException::class);
        Ticket::create(['participant_id' => $p->id, 'opened_at' => PromoClock::now()]);
    }

    public function test_new_ticket_is_allowed_after_previous_is_closed(): void
    {
        $p = $this->participant();
        $first = Ticket::create(['participant_id' => $p->id, 'opened_at' => PromoClock::now()]);
        $first->update(['closed_at' => PromoClock::now()]);

        $second = Ticket::create(['participant_id' => $p->id, 'opened_at' => PromoClock::now()]);

        $this->assertTrue($p->openTicket()->is($second));
        $this->assertSame(2, $p->tickets()->count());
    }

    public function test_duplicate_inbound_message_from_telegram_is_rejected(): void
    {
        $p = $this->participant();
        $attrs = ['participant_id' => $p->id, 'author' => Message::AUTHOR_PARTICIPANT, 'telegram_message_id' => 7, 'text' => 'привет'];
        Message::create($attrs);

        $this->expectException(QueryException::class);
        Message::create($attrs);
    }

    public function test_outgoing_messages_without_telegram_id_do_not_collide(): void
    {
        $p = $this->participant();
        Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'раз']);
        Message::create(['participant_id' => $p->id, 'author' => Message::AUTHOR_BOT, 'text' => 'два']);

        $this->assertSame(2, $p->messages()->count());
    }

    public function test_time_columns_are_timestamptz(): void
    {
        $types = DB::table('information_schema.columns')
            ->where('table_name', 'messages')
            ->pluck('data_type', 'column_name');

        $this->assertSame('timestamp with time zone', $types['created_at']);
    }
}
