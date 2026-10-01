<?php

namespace Tests\Feature\Panel;

use App\Models\Message;

class StatsTest extends PanelTestCase
{
    public function test_questions_are_counted_without_smalltalk(): void
    {
        $p = $this->participant();
        $this->decision($this->inbound($p, '1'), 'answer');
        $this->decision($this->inbound($p, '2'), 'answer');
        $this->decision($this->inbound($p, '3'), 'operator', ['reason' => 'llm_error']);
        $this->decision($this->inbound($p, '4'), 'refuse');
        $this->decision($this->inbound($p, '5'), 'smalltalk');

        $response = $this->get('/stats')->assertOk();

        $response->assertSeeInOrder(['Всего вопросов</th><td class="num">4</td>'], false);
        $response->assertSeeInOrder(['Ответил сам</th><td class="num">2</td><td class="num">50%</td>'], false);
        $response->assertSeeInOrder(['Передано оператору</th><td class="num">1</td><td class="num">25%</td>'], false);
        $response->assertSeeInOrder(['ИИ не ответил</th><td class="num">1</td><td class="num">25%</td>'], false);
        $response->assertSeeInOrder(['по решению модели</th><td class="num">0</td><td class="num">0%</td>'], false);
        $response->assertSeeInOrder(['Отказ</th><td class="num">1</td><td class="num">25%</td>'], false);
    }

    public function test_operator_ratings_are_counted_over_questions(): void
    {
        $p = $this->participant();
        $this->decision($this->inbound($p, '1'), 'answer', ['rating' => 'correct']);
        $this->decision($this->inbound($p, '2'), 'operator', ['rating' => 'correct']);
        $this->decision($this->inbound($p, '3'), 'refuse', ['rating' => 'wrong']);
        $this->decision($this->inbound($p, '4'), 'answer');
        $this->decision($this->inbound($p, '5'), 'smalltalk', ['rating' => 'debatable']);

        $response = $this->get('/stats')->assertOk();

        $response->assertSee('Верно</th><td class="num">2</td><td class="num">50%</td>', false);
        $response->assertSee('Неверно</th><td class="num">1</td><td class="num">25%</td>', false);
        $response->assertSee('Спорно</th><td class="num">0</td><td class="num">0%</td>', false);
        $response->assertSee('Без оценки</th><td class="num">1</td><td class="num">25%</td>', false);
    }

    public function test_empty_stats_render_dashes(): void
    {
        $response = $this->get('/stats')->assertOk();

        $response->assertSee('Всего вопросов</th><td class="num">0</td>', false);
        $response->assertSee('Ответил сам</th><td class="num">0</td><td class="num">—</td>', false);
        $response->assertSee('Среднее время первого ответа оператора</th><td class="num">—</td>', false);
    }

    public function test_tickets_and_average_first_reply_time(): void
    {
        $p1 = $this->participant(1);
        $answered = $this->ticket($p1, '2026-10-06 08:00');
        // Первый ответ через 90 минут, второй позже: в расчёт идёт первый.
        Message::create(['participant_id' => $p1->id, 'ticket_id' => $answered->id, 'author' => Message::AUTHOR_OPERATOR, 'operator_id' => $this->operator->id, 'text' => 'a', 'created_at' => self::msk('2026-10-06 09:30')]);
        Message::create(['participant_id' => $p1->id, 'ticket_id' => $answered->id, 'author' => Message::AUTHOR_OPERATOR, 'operator_id' => $this->operator->id, 'text' => 'b', 'created_at' => self::msk('2026-10-06 09:50')]);
        // Сообщение бота в обращении ответом оператора не считается.
        Message::create(['participant_id' => $p1->id, 'ticket_id' => $answered->id, 'author' => Message::AUTHOR_BOT, 'text' => 'c', 'created_at' => self::msk('2026-10-06 08:01')]);

        $p2 = $this->participant(2);
        $this->ticket($p2, '2026-10-06 09:00');
        $p3 = $this->participant(3);
        $this->ticket($p3, '2026-10-05 09:00', ['closed_at' => self::msk('2026-10-05 10:00'), 'closed_by' => $this->operator->id]);

        $response = $this->get('/stats')->assertOk();

        $response->assertSee('Всего</th><td class="num">3</td>', false);
        $response->assertSee('Открыто</th><td class="num">2</td>', false);
        $response->assertSee('Закрыто</th><td class="num">1</td>', false);
        $response->assertSee('Ждут первого ответа оператора</th><td class="num">1</td>', false);
        $response->assertSee('Среднее время первого ответа оператора</th><td class="num">1 ч 30 мин</td>', false);
    }

    public function test_average_is_over_answered_tickets_only(): void
    {
        $p1 = $this->participant(1);
        $t1 = $this->ticket($p1, '2026-10-06 08:00');
        Message::create(['participant_id' => $p1->id, 'ticket_id' => $t1->id, 'author' => Message::AUTHOR_OPERATOR, 'text' => 'a', 'created_at' => self::msk('2026-10-06 09:00')]);
        $p2 = $this->participant(2);
        $t2 = $this->ticket($p2, '2026-10-06 08:00');
        Message::create(['participant_id' => $p2->id, 'ticket_id' => $t2->id, 'author' => Message::AUTHOR_OPERATOR, 'text' => 'b', 'created_at' => self::msk('2026-10-06 10:00')]);
        $this->ticket($this->participant(3), '2026-10-01 08:00');

        // (60 + 120) / 2 = 90 минут.
        $this->get('/stats')->assertOk()->assertSee('<td class="num">1 ч 30 мин</td>', false);
    }
}
