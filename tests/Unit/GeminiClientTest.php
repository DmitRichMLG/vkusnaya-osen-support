<?php

namespace Tests\Unit;

use App\Bot\GeminiClient;
use App\Bot\GeminiException;
use App\Bot\GeminiResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Цепочка моделей и разбор ответа Gemini. Сеть подменена.
 * Минутный лимит (429 с retryDelay) здесь не вызываем: клиент на нём делает sleep().
 */
class GeminiClientTest extends TestCase
{
    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private const SCHEMA = ['type' => 'OBJECT', 'properties' => ['action' => ['type' => 'STRING']]];

    /** @param  list<string>  $models */
    private static function client(array $models = ['model-a', 'model-b']): GeminiClient
    {
        return new GeminiClient('test-key', $models);
    }

    /** Ответ generateContent: «мысли» модели, затем текст. */
    private static function candidate(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'думаю'], ['text' => $text]]], 'finishReason' => 'STOP']]];
    }

    private static function generate(?GeminiClient $client = null): GeminiResult
    {
        return ($client ?? self::client())->generateJson('система', 'вопрос', self::SCHEMA);
    }

    public function test_first_model_answers(): void
    {
        Http::fake([self::GEMINI => Http::response(self::candidate('{"action":"answer"}'))]);

        $result = self::generate();

        $this->assertSame('model-a', $result->model);
        $this->assertSame('{"action":"answer"}', $result->text);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
        Http::assertSentCount(1);
    }

    public function test_text_is_glued_from_parts_without_thoughts(): void
    {
        Http::fake([self::GEMINI => Http::response(['candidates' => [['content' => ['parts' => [
            ['thought' => true, 'text' => 'рассуждения'],
            ['text' => '{"action":'],
            ['text' => '"refuse"}'],
        ]]]]])]);

        $this->assertSame('{"action":"refuse"}', self::generate()->text);
    }

    public function test_server_error_switches_to_next_model(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 500)
            ->push(self::candidate('{"action":"smalltalk"}'))]);

        $result = self::generate();

        $this->assertSame('model-b', $result->model);
        $this->assertSame('{"action":"smalltalk"}', $result->text);
        Http::assertSentCount(2);
        Http::assertSentInOrder([
            fn (Request $r) => str_contains($r->url(), '/models/model-a:generateContent'),
            fn (Request $r) => str_contains($r->url(), '/models/model-b:generateContent'),
        ]);
    }

    public function test_non_json_body_and_thoughts_only_switch_to_next_model(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push('<html>bad gateway</html>', 200)
            ->push(['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'только мысли']]], 'finishReason' => 'MAX_TOKENS']]])
            ->push(self::candidate('{"action":"refuse"}'))]);

        $result = self::generate(self::client(['model-a', 'model-b', 'model-c']));

        $this->assertSame('model-c', $result->model);
        Http::assertSentCount(3);
    }

    public function test_connection_failure_switches_to_next_model(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->pushFailedConnection('timed out')
            ->push(self::candidate('{"action":"answer"}'))]);

        $this->assertSame('model-b', self::generate()->model);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/models/model-b:generateContent'));
    }

    public function test_daily_quota_switches_to_next_model_without_waiting(): void
    {
        // Суточная квота исчерпана, даже если рядом есть подсказка retryDelay: ждать бессмысленно
        $perDay = ['error' => ['code' => 429, 'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '20s'],
        ]]];
        Http::fake([self::GEMINI => Http::sequence()
            ->push($perDay, 429)
            ->push(self::candidate('{"action":"answer"}'))]);

        $started = microtime(true);
        $result = self::generate();

        $this->assertSame('model-b', $result->model);
        $this->assertLessThan(1.0, microtime(true) - $started);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_without_hint_switches_to_next_model(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push(['error' => ['message' => 'Resource exhausted']], 429)
            ->push(self::candidate('{"action":"answer"}'))]);

        $this->assertSame('model-b', self::generate()->model);
        Http::assertSentCount(2);
    }

    public function test_all_models_failed_throws_with_every_reason(): void
    {
        Http::fake([self::GEMINI => Http::sequence()
            ->push(['error' => ['message' => 'boom']], 503)
            ->push('', 500)]);

        try {
            self::generate();
            $this->fail('ожидали GeminiException');
        } catch (GeminiException $e) {
            $this->assertStringContainsString('model-a: HTTP 503', $e->getMessage());
            $this->assertStringContainsString('model-b: HTTP 500', $e->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_request_shape_and_key_in_header_not_url(): void
    {
        Http::fake([self::GEMINI => Http::response(self::candidate('{}'))]);

        self::client(['model-a'])->generateJson('системный промпт', 'ход участника', self::SCHEMA);

        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/model-a:generateContent'
                && $r->hasHeader('x-goog-api-key', 'test-key')
                && $r['system_instruction'] === ['parts' => [['text' => 'системный промпт']]]
                && $r['contents'] === [['role' => 'user', 'parts' => [['text' => 'ход участника']]]]
                && $r['generationConfig']['responseMimeType'] === 'application/json'
                && $r['generationConfig']['responseSchema'] === self::SCHEMA;
        });
    }
}
