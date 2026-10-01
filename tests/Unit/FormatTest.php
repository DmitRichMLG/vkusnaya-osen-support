<?php

namespace Tests\Unit;

use App\Http\Panel\Format;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Participant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Подписи и форматы панели. Модели собираются в памяти, база не нужна. */
class FormatTest extends TestCase
{
    public function test_msk_shows_moscow_time_and_empty_for_null(): void
    {
        $this->assertSame('06.10.2026 10:00', Format::msk(CarbonImmutable::parse('2026-10-06 07:00', 'UTC')));
        $this->assertSame('06.10.2026 00:30', Format::msk(CarbonImmutable::parse('2026-10-05 21:30', 'UTC'))); // по Москве уже следующие сутки
        $this->assertSame('', Format::msk(null));
    }

    public function test_duration(): void
    {
        $this->assertSame('0 мин', Format::duration(0));
        $this->assertSame('5 мин', Format::duration(300));
        $this->assertSame('1 ч 30 мин', Format::duration(5400));
        $this->assertSame('1 ч 0 мин', Format::duration(3600));
        $this->assertSame('0 мин', Format::duration(59)); // секунды отбрасываются
        $this->assertSame('0 мин', Format::duration(-120)); // отрицательное не ломает вывод
    }

    public function test_author(): void
    {
        $this->assertSame('Участник', Format::author(new Message(['author' => Message::AUTHOR_PARTICIPANT])));
        $this->assertSame('Бот', Format::author(new Message(['author' => Message::AUTHOR_BOT])));

        $withOperator = (new Message(['author' => Message::AUTHOR_OPERATOR]))->setRelation('operator', new User(['name' => 'Мария']));
        $this->assertSame('Оператор Мария', Format::author($withOperator));

        $withoutOperator = (new Message(['author' => Message::AUTHOR_OPERATOR]))->setRelation('operator', null);
        $this->assertSame('Оператор', Format::author($withoutOperator));

        $this->assertSame('system', Format::author(new Message(['author' => 'system']))); // неизвестный автор показывается как есть
    }

    public function test_action_labels(): void
    {
        $this->assertSame('ответил сам', Format::action(BotDecision::ACTION_ANSWER));
        $this->assertSame('передано оператору', Format::action(BotDecision::ACTION_OPERATOR));
        $this->assertSame('отказ', Format::action(BotDecision::ACTION_REFUSE));
        $this->assertSame('служебное', Format::action(BotDecision::ACTION_SMALLTALK));
        $this->assertSame('unknown', Format::action('unknown'));
    }

    public function test_reason_labels(): void
    {
        // У каждой причины, которую пишет код, есть русская подпись
        foreach (['model', 'invalid_refs', 'llm_error', 'no_text', 'media_to_ticket', 'start'] as $reason) {
            $this->assertNotSame($reason, Format::reason($reason), $reason);
        }
        $this->assertSame('ИИ не ответил', Format::reason('llm_error'));
        $this->assertSame('пункты не прошли проверку', Format::reason('invalid_refs'));
        $this->assertSame('mystery', Format::reason('mystery'));
    }

    public function test_rating_labels(): void
    {
        $this->assertSame('без оценки', Format::rating(null));
        $this->assertSame('верно', Format::rating(BotDecision::RATING_CORRECT));
        $this->assertSame('неверно', Format::rating(BotDecision::RATING_WRONG));
        $this->assertSame('спорно', Format::rating(BotDecision::RATING_DEBATABLE));
        $this->assertSame('other', Format::rating('other'));
    }

    public function test_body_text(): void
    {
        $this->assertSame('Привет', Format::body(new Message(['content_type' => 'text', 'text' => "  Привет\n"])));
        $this->assertSame('', Format::body(new Message(['content_type' => 'text', 'text' => null])));
    }

    public function test_body_photo_without_file_id_is_marked(): void
    {
        $this->assertSame("[фото]\nвот чек", Format::body(new Message(['content_type' => 'photo', 'telegram_file_id' => null, 'text' => 'вот чек'])));
        $this->assertSame('[фото]', Format::body(new Message(['content_type' => 'photo', 'telegram_file_id' => null, 'text' => ''])));
        // Старые записи с уже вписанной пометкой не получают её дважды
        $this->assertSame('[фото]', Format::body(new Message(['content_type' => 'photo', 'telegram_file_id' => null, 'text' => '[фото]'])));
    }

    public function test_body_photo_with_file_id_has_no_marker(): void
    {
        $this->assertSame('вот чек', Format::body(new Message(['content_type' => 'photo', 'telegram_file_id' => 'AgACAgIAAxkBAAI', 'text' => 'вот чек'])));
        $this->assertSame('', Format::body(new Message(['content_type' => 'photo', 'telegram_file_id' => 'AgACAgIAAxkBAAI', 'text' => null])));
    }

    public function test_body_other_attachments_are_marked(): void
    {
        $this->assertSame("[вложение]\nдокумент", Format::body(new Message(['content_type' => 'other', 'text' => 'документ'])));
        $this->assertSame('[вложение]', Format::body(new Message(['content_type' => 'other', 'text' => null])));
    }

    public function test_participant_name_username_or_id(): void
    {
        $this->assertSame('Иван Тестов', Format::participant(new Participant(['first_name' => 'Иван', 'last_name' => 'Тестов', 'username' => 'ivan', 'telegram_user_id' => 100])));
        $this->assertSame('Иван', Format::participant(new Participant(['first_name' => 'Иван', 'last_name' => null, 'username' => 'ivan', 'telegram_user_id' => 100])));
        $this->assertSame('@ivan', Format::participant(new Participant(['first_name' => null, 'last_name' => null, 'username' => 'ivan', 'telegram_user_id' => 100])));
        $this->assertSame('id 100', Format::participant(new Participant(['first_name' => '', 'last_name' => '', 'username' => null, 'telegram_user_id' => 100])));
    }
}
