<?php

namespace Tests\Unit;

use App\Bot\BotContext;
use App\Bot\Decision;
use App\Bot\DecisionEngine;
use App\Support\PromoClock;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Проверки кода поверх ответа модели, которых нет в tests/Feature/DecisionEngineTest.php:
 * причины передачи, тексты по умолчанию, нормализация пунктов и что сохраняется в model_output.
 */
class DecisionEngineTest extends TestCase
{
    private const GEMINI = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['promo.gemini.models' => ['model-a', 'model-b']]);
        PromoClock::freeze('2026-10-06 10:00');
    }

    protected function tearDown(): void
    {
        PromoClock::unfreeze();
        parent::tearDown();
    }

    /** Модель вернула такой JSON (в ответе Gemini он лежит текстом). Один стаб на тест. */
    private static function modelSays(array $json): void
    {
        Http::fake([self::GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]]]])]);
    }

    private function decide(string $message = 'вопрос'): Decision
    {
        return app(DecisionEngine::class)->decide(new BotContext($message, PromoClock::now()));
    }

    /** Схема ответа такого не допускает, но модель может её нарушить: вложенные массивы не должны ронять обработчик. */
    public function test_nested_arrays_in_model_output_are_treated_as_broken_json(): void
    {
        self::modelSays(['action' => 'answer', 'text' => ['Да.'], 'operator_summary' => '', 'rule_refs' => [['4.2']]]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('llm_error', $d->reason);
        $this->assertSame(__('bot.handoff'), $d->text);
    }

    public function test_answer_with_invented_clause_drops_valid_ones_too(): void
    {
        $output = ['action' => 'answer', 'text' => 'Да.', 'operator_summary' => '', 'rule_refs' => ['4.2', '13.7']];
        self::modelSays($output);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertTrue($d->needsOperator());
        $this->assertSame('invalid_refs', $d->reason);
        $this->assertSame([], $d->ruleRefs); // существующий 4.2 участнику тоже не уходит: ответ целиком не прошёл проверку
        $this->assertSame(['13.7'], $d->invalidRefs);
        $this->assertSame(__('bot.handoff'), $d->text);
        $this->assertSame(__('bot.summary_invalid_refs'), $d->operatorSummary);
        $this->assertSame($output, $d->modelOutput);
        $this->assertSame('model-a', $d->model);
    }

    public function test_answer_without_clauses_has_invalid_refs_reason(): void
    {
        self::modelSays(['action' => 'answer', 'text' => 'Да.', 'operator_summary' => '', 'rule_refs' => []]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('invalid_refs', $d->reason);
        $this->assertSame([], $d->invalidRefs);
        $this->assertSame(__('bot.summary_invalid_refs'), $d->operatorSummary);
    }

    public function test_operator_with_invented_clause_keeps_model_summary_but_not_text(): void
    {
        self::modelSays(['action' => 'operator', 'text' => 'Передаю.', 'operator_summary' => 'Жалоба: чек отклонили.', 'rule_refs' => ['6.4', '99.1']]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('invalid_refs', $d->reason);
        $this->assertSame(['99.1'], $d->invalidRefs);
        $this->assertSame('Жалоба: чек отклонили.', $d->operatorSummary);
        $this->assertSame(__('bot.handoff'), $d->text); // частичный ответ с выдуманным пунктом участнику не уходит
    }

    public function test_refuse_without_clauses_stays(): void
    {
        self::modelSays(['action' => 'refuse', 'text' => 'Рецептами не помогу.', 'operator_summary' => '', 'rule_refs' => []]);

        $d = $this->decide('рецепт');

        $this->assertSame(Decision::REFUSE, $d->action);
        $this->assertSame('Рецептами не помогу.', $d->text);
        $this->assertSame('model', $d->reason);
        $this->assertSame([], $d->ruleRefs);
        $this->assertFalse($d->needsOperator());
    }

    public function test_smalltalk_without_clauses_stays(): void
    {
        self::modelSays(['action' => 'smalltalk', 'text' => 'И вам привет!', 'operator_summary' => '', 'rule_refs' => []]);

        $d = $this->decide('привет');

        $this->assertSame(Decision::SMALLTALK, $d->action);
        $this->assertSame('И вам привет!', $d->text);
        $this->assertSame('model', $d->reason);
        $this->assertFalse($d->needsOperator());
    }

    public function test_refuse_gets_default_text_when_model_sent_none(): void
    {
        self::modelSays(['action' => 'refuse', 'text' => '', 'operator_summary' => '', 'rule_refs' => []]);

        $this->assertSame(__('bot.refuse'), $this->decide()->text);
    }

    public function test_smalltalk_gets_default_text_when_model_sent_none(): void
    {
        self::modelSays(['action' => 'smalltalk', 'text' => '   ', 'operator_summary' => '', 'rule_refs' => []]);

        $this->assertSame(__('bot.smalltalk'), $this->decide()->text);
    }

    public function test_unknown_action_goes_to_operator_as_llm_error(): void
    {
        self::modelSays(['action' => 'escalate', 'text' => 'x', 'operator_summary' => '', 'rule_refs' => []]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('llm_error', $d->reason);
        $this->assertSame(__('bot.summary_llm_error'), $d->operatorSummary);
        $this->assertSame('escalate', $d->modelOutput['action']);
        $this->assertSame('model-a', $d->model); // модель ответила, но не по формату
    }

    public function test_broken_json_keeps_raw_text_in_output(): void
    {
        Http::fake([self::GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'не json']]]]]])]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('llm_error', $d->reason);
        $this->assertSame(['raw' => 'не json'], $d->modelOutput);
    }

    public function test_all_models_down_has_no_model_and_output(): void
    {
        Http::fake([self::GEMINI => Http::response('', 503)]);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('llm_error', $d->reason);
        $this->assertSame(__('bot.handoff'), $d->text);
        $this->assertNull($d->model);
        $this->assertNull($d->modelOutput);
        $this->assertSame(0, $d->latencyMs);
    }

    public function test_refs_are_normalized_and_deduplicated(): void
    {
        self::modelSays(['action' => 'answer', 'text' => 'Нет.', 'operator_summary' => '', 'rule_refs' => ['п. 4.2', '4.2.', '4.2', 'п.', 2.3]]);

        $d = $this->decide();

        $this->assertSame(Decision::ANSWER, $d->action);
        $this->assertSame(['4.2', '2.3'], $d->ruleRefs); // «п.» без номера отброшено, число приведено к строке
        $this->assertSame([], $d->invalidRefs);
    }

    public function test_rule_refs_not_a_list_is_treated_as_empty(): void
    {
        self::modelSays(['action' => 'answer', 'text' => 'Нет.', 'operator_summary' => '', 'rule_refs' => '4.2']);

        $d = $this->decide();

        $this->assertSame(Decision::OPERATOR, $d->action);
        $this->assertSame('invalid_refs', $d->reason);
    }

    public function test_successful_answer_stores_model_output_and_latency(): void
    {
        $output = ['action' => 'answer', 'text' => ' Нет, кефир не участвует. ', 'operator_summary' => '', 'rule_refs' => ['4.2']];
        self::modelSays($output);

        $d = $this->decide('кефир?');

        $this->assertSame(Decision::ANSWER, $d->action);
        $this->assertSame('model', $d->reason);
        $this->assertSame($output, $d->modelOutput);
        $this->assertSame('Нет, кефир не участвует.', $d->text); // пробелы по краям обрезаны
        $this->assertNull($d->operatorSummary); // пустая сводка хранится как null
        $this->assertSame([], $d->invalidRefs);
        $this->assertGreaterThanOrEqual(0, $d->latencyMs);
    }
}
