<?php

namespace Tests\Feature;

use App\Bot\BotContext;
use App\Bot\DecisionEngine;
use App\Bot\ReplyComposer;
use App\Support\PromoClock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Модель подменена: проверяем правила кода вокруг неё. */
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

    private static function gemini(array $json, int $status = 200): array
    {
        return ['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'думаю'], ['text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']]];
    }

    private function decide(string $message)
    {
        return app(DecisionEngine::class)->decide(new BotContext($message, PromoClock::now()));
    }

    public function test_answer_with_existing_clauses_passes(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'answer', 'text' => 'Нет, кефир не участвует.', 'operator_summary' => '', 'rule_refs' => ['п. 4.2']]))]);

        $d = $this->decide('кефир участвует?');

        $this->assertSame('answer', $d->action);
        $this->assertSame(['4.2'], $d->ruleRefs);
        $this->assertSame('model', $d->reason);
        $this->assertSame('model-a', $d->model);
        $this->assertSame('Нет, кефир не участвует.', ReplyComposer::compose($d, PromoClock::now()));
    }

    public function test_answer_with_invented_clause_goes_to_operator(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'answer', 'text' => 'Да.', 'operator_summary' => '', 'rule_refs' => ['4.2', '13.7']]))]);

        $d = $this->decide('x');

        $this->assertSame('operator', $d->action);
        $this->assertSame('invalid_refs', $d->reason);
        $this->assertSame(['13.7'], $d->invalidRefs);
        $this->assertSame(__('bot.handoff'), $d->text);
        $this->assertStringContainsString('на смене', ReplyComposer::compose($d, PromoClock::now()));
    }

    public function test_answer_without_clauses_goes_to_operator(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'answer', 'text' => 'Да.', 'operator_summary' => '', 'rule_refs' => []]))]);

        $this->assertSame('operator', $this->decide('x')->action);
    }

    public function test_operator_keeps_partial_answer_and_summary(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'operator', 'text' => 'Причина в «Мои чеки». Передаю оператору.', 'operator_summary' => 'Отклонён чек, нужна причина.', 'rule_refs' => ['6.4']]))]);

        $d = $this->decide('почему отклонили чек');

        $this->assertSame('operator', $d->action);
        $this->assertSame(['6.4'], $d->ruleRefs);
        $this->assertSame('Отклонён чек, нужна причина.', $d->operatorSummary);
        $this->assertStringStartsWith('Причина в «Мои чеки». Передаю оператору.', ReplyComposer::compose($d, PromoClock::now()));
    }

    public function test_refuse_and_smalltalk_drop_clauses(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'refuse', 'text' => 'Рецептами не помогу.', 'operator_summary' => '', 'rule_refs' => ['4.1']]))]);

        $d = $this->decide('рецепт');

        $this->assertSame('refuse', $d->action);
        $this->assertSame([], $d->ruleRefs);
    }

    public function test_answer_with_empty_text_goes_to_operator(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'answer', 'text' => '', 'operator_summary' => '', 'rule_refs' => ['4.2']]))]);

        $this->assertSame('operator', $this->decide('x')->action);
    }

    public function test_minute_rate_limit_retries_same_model_and_daily_quota_skips_to_next(): void
    {
        $perMinute = ['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '0s']]]];
        $perDay = ['error' => ['details' => [['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]]]];
        Http::fake([
            self::GEMINI => Http::sequence()
                ->push($perMinute, 429) // model-a: минутный лимит → подождать и повторить
                ->push($perDay, 429)    // model-a: суточная квота → следующая модель
                ->push(self::gemini(['action' => 'smalltalk', 'text' => 'Привет!', 'operator_summary' => '', 'rule_refs' => []])),
        ]);

        $d = $this->decide('привет');

        $this->assertSame('model-b', $d->model);
        Http::assertSentCount(3);
    }

    public function test_broken_json_goes_to_operator(): void
    {
        Http::fake([self::GEMINI => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"action": "answer", "text": ']]]]]])]);

        $d = $this->decide('x');

        $this->assertSame('operator', $d->action);
        $this->assertSame('llm_error', $d->reason);
    }

    public function test_second_model_is_used_when_first_fails(): void
    {
        Http::fake([
            self::GEMINI => Http::sequence()
                ->push(['error' => 'overloaded'], 503)
                ->push(self::gemini(['action' => 'smalltalk', 'text' => 'Привет!', 'operator_summary' => '', 'rule_refs' => []])),
        ]);

        $d = $this->decide('привет');

        $this->assertSame('smalltalk', $d->action);
        $this->assertSame('model-b', $d->model);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'model-b:generateContent') && $r->hasHeader('x-goog-api-key'));
    }

    public function test_all_models_down_goes_to_operator(): void
    {
        Http::fake([self::GEMINI => Http::response('', 500)]);

        $d = $this->decide('x');

        $this->assertSame('operator', $d->action);
        $this->assertSame('llm_error', $d->reason);
        $this->assertSame(__('bot.summary_llm_error'), $d->operatorSummary);
        Http::assertSentCount(2);
    }

    public function test_prompt_contains_rules_calendar_and_history(): void
    {
        Http::fake([self::GEMINI => Http::response(self::gemini(['action' => 'smalltalk', 'text' => 'ok', 'operator_summary' => '', 'rule_refs' => []]))]);

        app(DecisionEngine::class)->decide(new BotContext('а если 3 чека?', PromoClock::now(), [
            ['author' => 'participant', 'text' => 'сколько чеков в день'],
            ['author' => 'bot', 'text' => 'Не больше 10.'],
        ], 'Участник спрашивает про приз.'));

        Http::assertSent(function (Request $r) {
            $system = $r['system_instruction']['parts'][0]['text'];
            $user = $r['contents'][0]['parts'][0]['text'];

            return str_contains($system, '12.1. Вопросы по Акции')
                && ! str_contains($system, 'вымышлены') // преамбула правил вырезана
                && str_contains($user, 'Сегодня: вторник, 6 октября 2026')
                && str_contains($user, 'Участник: сколько чеков в день')
                && str_contains($user, 'Бот: Не больше 10.')
                && str_contains($user, 'Участник спрашивает про приз.')
                && str_contains($user, '«а если 3 чека?»')
                && $r['generationConfig']['responseMimeType'] === 'application/json';
        });
    }
}
