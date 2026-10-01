<?php

namespace Tests\Feature\Panel;

/** Оценка решений бота оператором: шкала из ТЗ (верно / неверно / спорно), комментарий, фильтр на странице решений. */
class DecisionRatingTest extends PanelTestCase
{
    public function test_operator_rates_decision_with_comment(): void
    {
        $decision = $this->decision($this->inbound($this->participant(), 'кефир участвует?'), 'answer');

        $response = $this->from('/decisions')->post("/decisions/{$decision->id}/rate", ['rating' => 'wrong', 'comment' => 'Сказал, что кефир участвует']);

        $response->assertRedirect("/decisions#decision-{$decision->id}")->assertSessionHas('success');
        $decision->refresh();
        $this->assertSame('wrong', $decision->rating);
        $this->assertSame('Сказал, что кефир участвует', $decision->rating_comment);
        $this->assertSame($this->operator->id, $decision->rated_by);
        $this->assertSame('2026-10-06 07:00:00', $decision->rated_at->utc()->format('Y-m-d H:i:s'));

        $this->get('/decisions')->assertOk()
            ->assertSee('<option value="wrong" selected>неверно</option>', false)
            ->assertSee('value="Сказал, что кефир участвует"', false)
            ->assertSee('Мария, 06.10.2026 10:00');
    }

    public function test_rating_is_shown_and_editable_on_ticket_page(): void
    {
        $p = $this->participant();
        $ticket = $this->ticket($p);
        $decision = $this->decision($this->inbound($p, 'где мой приз'), 'operator', ['ticket_id' => $ticket->id]);

        $this->from("/tickets/{$ticket->id}")->post("/decisions/{$decision->id}/rate", ['rating' => 'debatable'])
            ->assertRedirect("/tickets/{$ticket->id}#decision-{$decision->id}");

        $this->get("/tickets/{$ticket->id}")->assertOk()
            ->assertSee("/decisions/{$decision->id}/rate")
            ->assertSee('<option value="debatable" selected>спорно</option>', false);
    }

    public function test_rating_can_be_changed_and_cleared(): void
    {
        $decision = $this->decision($this->inbound($this->participant(), 'x'), 'answer', [
            'rating' => 'correct', 'rating_comment' => 'ок', 'rated_by' => $this->operator->id, 'rated_at' => self::msk('2026-10-05 12:00'),
        ]);

        $this->post("/decisions/{$decision->id}/rate", ['rating' => 'debatable', 'comment' => 'не уверен']);
        $this->assertSame(['debatable', 'не уверен'], [$decision->refresh()->rating, $decision->rating_comment]);

        $this->post("/decisions/{$decision->id}/rate", ['rating' => '', 'comment' => '']);
        $decision->refresh();
        $this->assertNull($decision->rating);
        $this->assertNull($decision->rating_comment);
        $this->assertNull($decision->rated_by);
        $this->assertNull($decision->rated_at);
    }

    public function test_unknown_rating_is_rejected(): void
    {
        $decision = $this->decision($this->inbound($this->participant(), 'x'), 'answer');

        $this->from('/decisions')->post("/decisions/{$decision->id}/rate", ['rating' => 'great'])
            ->assertRedirect('/decisions')->assertSessionHasErrors('rating');
        $this->assertNull($decision->refresh()->rating);
    }

    public function test_decisions_page_filters_by_rating(): void
    {
        $p = $this->participant();
        $this->decision($this->inbound($p, 'оценено верно'), 'answer', ['rating' => 'correct', 'rated_by' => $this->operator->id, 'rated_at' => self::msk('2026-10-06 09:00')]);
        $this->decision($this->inbound($p, 'оценено неверно'), 'answer', ['rating' => 'wrong', 'rated_by' => $this->operator->id, 'rated_at' => self::msk('2026-10-06 09:00')]);
        $this->decision($this->inbound($p, 'без оценки'), 'answer');

        $this->get('/decisions?rating=wrong')->assertOk()->assertSee('оценено неверно')->assertDontSee('оценено верно')->assertDontSee('без оценки</td>', false);
        $this->get('/decisions?rating=none')->assertOk()->assertSee('без оценки</td>', false)->assertDontSee('оценено верно')->assertDontSee('оценено неверно');
        $this->get('/decisions')->assertOk()->assertSee('оценено верно')->assertSee('оценено неверно')->assertSee('без оценки</td>', false);
    }
}
