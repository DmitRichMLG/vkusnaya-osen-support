<?php

namespace App\Bot;

use Carbon\CarbonImmutable;

/**
 * Текст участнику: ответ модели плюс то, что дописывает код —
 * фраза о сроке, если вопрос ушёл оператору, и предупреждение, если в сообщении была карта.
 */
final class ReplyComposer
{
    public static function compose(Decision $decision, CarbonImmutable $now, bool $cardHidden = false): string
    {
        $parts = [rtrim($decision->text)];
        if ($cardHidden) {
            $parts[] = __('bot.card_hidden');
        }
        if ($decision->needsOperator()) {
            $parts[] = OperatorHours::phrase($now);
        }

        return implode("\n\n", $parts);
    }
}
