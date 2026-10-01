<?php

namespace App\Bot;

/** Собирает системный промпт и пользовательский ход из файлов resources/prompts/. */
final class PromptBuilder
{
    public function __construct(
        private readonly RulesRepository $rules,
        private readonly string $promptsDir,
    ) {}

    public function system(): string
    {
        return $this->template('system.md')."\n\n# Правила акции (единственный источник ответов)\n\n".$this->rules->text();
    }

    public function user(BotContext $ctx): string
    {
        $history = $ctx->history === []
            ? 'Это первое сообщение участника, предыдущих сообщений нет.'
            : implode("\n", array_map(
                fn (array $m) => self::authorLabel($m['author']).': '.str_replace("\n", ' ', $m['text']),
                $ctx->history,
            ));

        return strtr($this->template('user.md'), [
            '{{calendar}}' => DrawCalendar::describe($ctx->now),
            '{{operators}}' => OperatorHours::isOnShift($ctx->now) ? 'Операторы сейчас на смене.' : 'Операторы сейчас не на смене.',
            '{{open_ticket}}' => $ctx->openTicketSummary !== null
                ? "У участника есть открытое обращение к оператору, его суть: {$ctx->openTicketSummary}"
                : 'Открытого обращения к оператору у участника нет.',
            '{{history}}' => $history,
            '{{message}}' => $ctx->message,
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return json_decode($this->template('decision.schema.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function version(): string
    {
        return substr(md5($this->template('system.md').$this->template('user.md')), 0, 8);
    }

    private function template(string $name): string
    {
        return RulesRepository::normalize((string) file_get_contents($this->promptsDir.'/'.$name));
    }

    private static function authorLabel(string $author): string
    {
        return match ($author) {
            'participant' => 'Участник',
            'operator' => 'Оператор',
            default => 'Бот',
        };
    }
}
