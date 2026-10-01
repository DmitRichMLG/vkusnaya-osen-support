<?php

namespace App\Bot;

/** Итог обработки одного сообщения: что делаем, что отвечаем и почему. */
final class Decision
{
    public const ANSWER = 'answer';

    public const OPERATOR = 'operator';

    public const REFUSE = 'refuse';

    public const SMALLTALK = 'smalltalk';

    public const ACTIONS = [self::ANSWER, self::OPERATOR, self::REFUSE, self::SMALLTALK];

    /**
     * @param  list<string>  $ruleRefs  проверенные номера пунктов
     * @param  list<string>  $invalidRefs  номера, которых нет в правилах
     */
    public function __construct(
        public readonly string $action,
        public readonly string $text,
        public readonly ?string $operatorSummary,
        public readonly array $ruleRefs,
        public readonly string $reason,
        public readonly ?string $model = null,
        public readonly ?array $modelOutput = null,
        public readonly array $invalidRefs = [],
        public readonly int $latencyMs = 0,
    ) {}

    public function needsOperator(): bool
    {
        return $this->action === self::OPERATOR;
    }
}
