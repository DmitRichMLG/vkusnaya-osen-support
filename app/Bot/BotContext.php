<?php

namespace App\Bot;

use Carbon\CarbonImmutable;

/** Всё, что модель видит о текущем разговоре. */
final class BotContext
{
    /**
     * @param  list<array{author: string, text: string}>  $history  последние сообщения, от старых к новым
     */
    public function __construct(
        public readonly string $message,
        public readonly CarbonImmutable $now,
        public readonly array $history = [],
        public readonly ?string $openTicketSummary = null,
    ) {}
}
