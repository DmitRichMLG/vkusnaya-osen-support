<?php

namespace App\Bot;

use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Одно сообщение → одно решение. Модель предлагает действие, код проверяет:
 * действие из списка, у ответа есть существующие пункты правил. Любой сбой — оператору.
 */
final class DecisionEngine
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly PromptBuilder $prompts,
        private readonly RulesRepository $rules,
    ) {}

    public function decide(BotContext $ctx): Decision
    {
        try {
            $result = $this->gemini->generateJson($this->prompts->system(), $this->prompts->user($ctx), $this->prompts->schema());
        } catch (GeminiException $e) {
            Log::error('Все модели Gemini недоступны: '.$e->getMessage());

            return $this->handoff('llm_error', __('bot.summary_llm_error'));
        }

        try {
            $data = json_decode($result->text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (! is_array($data) || ! self::isWellFormed($data)) {
            Log::warning('Gemini вернул некорректный JSON', ['model' => $result->model, 'text' => mb_substr($result->text, 0, 300)]);

            return $this->handoff('llm_error', __('bot.summary_llm_error'), $result, is_array($data) ? $data : ['raw' => $result->text]);
        }

        $action = $data['action'];
        $text = trim((string) ($data['text'] ?? ''));
        $summary = trim((string) ($data['operator_summary'] ?? '')) ?: null;
        $refs = array_values(array_unique(array_filter(array_map(
            RulesRepository::normalizeRef(...),
            is_array($data['rule_refs'] ?? null) ? array_map('strval', $data['rule_refs']) : [],
        ))));
        $invalid = $this->rules->invalidRefs($refs);
        $valid = array_values(array_diff($refs, $invalid));

        return match ($action) {
            Decision::ANSWER => ($valid === [] || $invalid !== [] || $text === '')
                ? $this->handoff('invalid_refs', $summary ?? __('bot.summary_invalid_refs'), $result, $data, $invalid)
                : new Decision($action, $text, $summary, $valid, 'model', $result->model, $data, latencyMs: $result->latencyMs),
            Decision::OPERATOR => $invalid !== [] || $text === ''
                ? $this->handoff('invalid_refs', $summary ?? __('bot.summary_invalid_refs'), $result, $data, $invalid)
                : new Decision($action, $text, $summary, $valid, 'model', $result->model, $data, latencyMs: $result->latencyMs),
            Decision::REFUSE => new Decision($action, $text ?: __('bot.refuse'), $summary, [], 'model', $result->model, $data, latencyMs: $result->latencyMs),
            Decision::SMALLTALK => new Decision($action, $text ?: __('bot.smalltalk'), $summary, [], 'model', $result->model, $data, latencyMs: $result->latencyMs),
        };
    }

    /** Действие из списка, текст и сводка — строки, внутри списка пунктов нет вложенных массивов. Остальное — «кривой JSON». */
    private static function isWellFormed(array $data): bool
    {
        $refs = $data['rule_refs'] ?? [];

        return in_array($data['action'] ?? null, Decision::ACTIONS, true)
            && is_scalar($data['text'] ?? '')
            && is_scalar($data['operator_summary'] ?? '')
            && (! is_array($refs) || array_filter($refs, fn ($ref) => ! is_scalar($ref)) === []);
    }

    private function handoff(string $reason, string $summary, ?GeminiResult $result = null, ?array $output = null, array $invalid = []): Decision
    {
        return new Decision(Decision::OPERATOR, __('bot.handoff'), $summary, [], $reason, $result?->model, $output, $invalid, $result?->latencyMs ?? 0);
    }
}
