<?php

namespace Tests\Unit;

use App\Bot\Decision;
use App\Bot\DrawCalendar;
use App\Bot\ReplyComposer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Что код дописывает к тексту модели: срок ответа оператора и предупреждение о карте. */
class ReplyComposerTest extends TestCase
{
    private const ON_SHIFT = '2026-10-06 10:00'; // вторник, часы прогона

    private const OFF_SHIFT = '2026-10-06 20:00';

    private static function msk(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, DrawCalendar::TZ);
    }

    private static function decision(string $action, string $text = 'Текст ответа.'): Decision
    {
        return new Decision($action, $text, null, [], 'model');
    }

    public function test_operator_reply_gets_on_shift_phrase(): void
    {
        $reply = ReplyComposer::compose(self::decision(Decision::OPERATOR, 'Передаю ваш вопрос оператору.'), self::msk(self::ON_SHIFT));

        $this->assertSame("Передаю ваш вопрос оператору.\n\n".__('bot.eta_on_shift'), $reply);
        $this->assertStringContainsString('на смене', $reply);
    }

    public function test_operator_reply_gets_off_shift_phrase_in_the_evening_and_on_weekend(): void
    {
        $evening = ReplyComposer::compose(self::decision(Decision::OPERATOR), self::msk(self::OFF_SHIFT));
        $saturday = ReplyComposer::compose(self::decision(Decision::OPERATOR), self::msk('2026-10-10 12:00'));

        $this->assertStringEndsWith("\n\n".__('bot.eta_off_shift'), $evening);
        $this->assertStringEndsWith("\n\n".__('bot.eta_off_shift'), $saturday);
        $this->assertStringNotContainsString('на смене', $evening);
        $this->assertStringContainsString('9:00 до 18:00', $evening);
    }

    public function test_shift_is_computed_from_utc_moment(): void
    {
        // 06:30 UTC = 09:30 МСК: смена уже идёт
        $reply = ReplyComposer::compose(self::decision(Decision::OPERATOR), CarbonImmutable::parse('2026-10-06 06:30', 'UTC'));

        $this->assertStringEndsWith(__('bot.eta_on_shift'), $reply);
    }

    public function test_answer_refuse_and_smalltalk_are_sent_as_is(): void
    {
        foreach ([Decision::ANSWER, Decision::REFUSE, Decision::SMALLTALK] as $action) {
            $reply = ReplyComposer::compose(self::decision($action, 'Нет, кефир не участвует.'), self::msk(self::ON_SHIFT));

            $this->assertSame('Нет, кефир не участвует.', $reply, $action);
        }
    }

    public function test_hidden_card_warning_goes_between_text_and_operator_phrase(): void
    {
        $reply = ReplyComposer::compose(self::decision(Decision::OPERATOR, 'Передаю оператору.'), self::msk(self::ON_SHIFT), cardHidden: true);

        $this->assertSame("Передаю оператору.\n\n".__('bot.card_hidden')."\n\n".__('bot.eta_on_shift'), $reply);
    }

    public function test_hidden_card_warning_is_added_to_answer_too(): void
    {
        $reply = ReplyComposer::compose(self::decision(Decision::ANSWER, 'Ответ.'), self::msk(self::ON_SHIFT), cardHidden: true);

        $this->assertSame("Ответ.\n\n".__('bot.card_hidden'), $reply);
        $this->assertStringContainsString('скрыли', $reply);
        $this->assertStringNotContainsString('9:00', $reply); // ответ без оператора — без фразы о сроке
    }

    public function test_trailing_whitespace_of_model_text_is_trimmed(): void
    {
        $reply = ReplyComposer::compose(self::decision(Decision::OPERATOR, "Передаю оператору.\n\n  "), self::msk(self::ON_SHIFT));

        $this->assertSame("Передаю оператору.\n\n".__('bot.eta_on_shift'), $reply);
    }

    public function test_empty_text(): void
    {
        $this->assertSame('', ReplyComposer::compose(self::decision(Decision::ANSWER, ''), self::msk(self::ON_SHIFT)));

        // Пустой текст при передаче оператору на практике не бывает: DecisionEngine подставляет bot.handoff.
        // Части склеиваются через два перевода строки, поэтому впереди остаётся пустая часть; участнику уходит только фраза о сроке.
        $reply = ReplyComposer::compose(self::decision(Decision::OPERATOR, ''), self::msk(self::ON_SHIFT));

        $this->assertSame(__('bot.eta_on_shift'), trim($reply));
    }
}
