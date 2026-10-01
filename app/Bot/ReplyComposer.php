<?php

namespace App\Bot;

use Carbon\CarbonImmutable;

/** Текст участнику: ответ модели плюс фраза о сроке, если вопрос ушёл оператору. */
final class ReplyComposer
{
    public static function compose(Decision $decision, CarbonImmutable $now): string
    {
        if (! $decision->needsOperator()) {
            return $decision->text;
        }

        return rtrim($decision->text)."\n\n".OperatorHours::phrase($now);
    }
}
