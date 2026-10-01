<?php

namespace App\Bot;

use Carbon\CarbonImmutable;

/**
 * Текст участнику: ответ модели плюс то, что дописывает код —
 * фраза о сроке, если вопрос ушёл оператору, и предупреждение, если в сообщении была карта.
 */
final class ReplyComposer
{
    /** Лимит Telegram на текст одного сообщения. */
    public const MAX_LENGTH = 4096;

    public static function compose(Decision $decision, CarbonImmutable $now, bool $cardHidden = false): string
    {
        $extra = [];
        if ($cardHidden) {
            $extra[] = __('bot.card_hidden');
        }
        if ($decision->needsOperator()) {
            $extra[] = OperatorHours::phrase($now);
        }
        $tail = $extra === [] ? '' : '

'.implode('

', $extra);

        // Сообщение длиннее лимита Telegram не отправит (400): режем текст модели, приписки кода оставляем целиком.
        $text = rtrim($decision->text);
        $limit = self::MAX_LENGTH - mb_strlen($tail);
        if (mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit - 1)).'…';
        }

        return $text.$tail;
    }
}
