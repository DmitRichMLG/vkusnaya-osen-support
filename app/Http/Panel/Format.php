<?php

namespace App\Http\Panel;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Participant;
use Carbon\CarbonInterface;

/** Подписи и форматы для шаблонов панели. Время показываем по Москве. */
final class Format
{
    private const ACTIONS = [
        BotDecision::ACTION_ANSWER => 'ответил сам',
        BotDecision::ACTION_OPERATOR => 'передано оператору',
        BotDecision::ACTION_REFUSE => 'отказ',
        BotDecision::ACTION_SMALLTALK => 'служебное',
    ];

    private const REASONS = [
        'model' => 'решение модели',
        'invalid_refs' => 'пункты не прошли проверку',
        'llm_error' => 'ИИ не ответил',
        'no_text' => 'нет текста',
        'media_to_ticket' => 'вложение приложено к обращению',
        'start' => 'команда /start',
    ];

    public static function msk(?CarbonInterface $moment): string
    {
        return $moment?->setTimezone(config('promo.timezone'))->format('d.m.Y H:i') ?? '';
    }

    public static function participant(Participant $participant): string
    {
        $name = trim($participant->first_name.' '.$participant->last_name);
        if ($name !== '') {
            return $name;
        }

        return $participant->username ? '@'.$participant->username : 'id '.$participant->telegram_user_id;
    }

    /** 5400 → «1 ч 30 мин», 300 → «5 мин». */
    public static function duration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        return $hours > 0 ? "{$hours} ч {$minutes} мин" : "{$minutes} мин";
    }

    public static function action(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    public static function reason(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }

    public static function author(Message $message): string
    {
        return match ($message->author) {
            Message::AUTHOR_PARTICIPANT => 'Участник',
            Message::AUTHOR_BOT => 'Бот',
            Message::AUTHOR_OPERATOR => rtrim('Оператор '.$message->operator?->name),
            default => $message->author,
        };
    }

    /** Текст сообщения для показа: фото и прочие вложения помечаем. */
    public static function body(Message $message): string
    {
        $text = trim((string) $message->text);
        $marker = match ($message->content_type) {
            'text' => '',
            'photo' => '[фото]',
            default => '[вложение]',
        };
        if ($marker === '' || str_starts_with($text, $marker)) {
            return $text;
        }

        return trim($marker."\n".$text);
    }
}
