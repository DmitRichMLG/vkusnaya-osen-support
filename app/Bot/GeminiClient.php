<?php

namespace App\Bot;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemini generateContent через HTTP-клиент Laravel. Ключ уходит в заголовке, не в URL.
 * Модели пробуются по списку: любой сбой (сеть, таймаут, не-2xx, пустой ответ) — переход к следующей.
 */
final class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** @param  list<string>  $models */
    public function __construct(
        private readonly string $key,
        private readonly array $models,
        private readonly int $timeout = 60,
    ) {}

    /**
     * @param  array<string, mixed>  $schema  JSON-схема ответа
     *
     * @throws GeminiException когда ни одна модель не ответила
     */
    public function generateJson(string $system, string $user, array $schema): GeminiResult
    {
        $errors = [];
        foreach ($this->models as $model) {
            $started = microtime(true);
            try {
                $response = $this->call($model, $system, $user, $schema);
                $elapsed = (int) ((microtime(true) - $started) * 1000);

                if (! $response->successful()) {
                    $errors[] = "{$model}: HTTP {$response->status()}";
                    Log::warning("Gemini {$model}: HTTP {$response->status()}", ['error' => mb_substr((string) $response->json('error.message'), 0, 200)]);

                    continue;
                }

                $text = self::extractText($response->json());
                if ($text === null) {
                    $errors[] = "{$model}: пустой ответ";
                    Log::warning("Gemini {$model}: пустой ответ", ['finish' => $response->json('candidates.0.finishReason')]);

                    continue;
                }

                return new GeminiResult($model, $text, $elapsed);
            } catch (Throwable $e) {
                $errors[] = "{$model}: ".class_basename($e);
                Log::warning("Gemini {$model}: ".class_basename($e).': '.$e->getMessage());
            }
        }

        throw new GeminiException(implode('; ', $errors));
    }

    /**
     * Один вызов модели. При 429 из-за минутного лимита ждём, сколько просит API, и повторяем один раз;
     * суточная квота («PerDay») исчерпана — сразу отдаём ответ, и вызывающий код берёт следующую модель.
     */
    private function call(string $model, string $system, string $user, array $schema): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $response = Http::withHeaders(['x-goog-api-key' => $this->key])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(sprintf(self::ENDPOINT, $model), [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ]);

            if ($response->status() !== 429 || $attempt > 1) {
                return $response;
            }
            $delay = self::retryDelay($response->json('error.details', []));
            if ($delay === null) {
                return $response;
            }
            Log::info("Gemini {$model}: минутный лимит, ждём {$delay} с");
            sleep($delay);
        }
    }

    /** Секунды ожидания при минутном лимите; null — суточная квота или нет подсказки. */
    private static function retryDelay(array $details): ?int
    {
        $delay = null;
        foreach ($details as $detail) {
            foreach ($detail['violations'] ?? [] as $violation) {
                if (str_contains($violation['quotaId'] ?? '', 'PerDay')) {
                    return null;
                }
            }
            if (isset($detail['retryDelay']) && preg_match('/^(\d+)/', $detail['retryDelay'], $m)) {
                $delay = min((int) $m[1] + 1, 30);
            }
        }

        return $delay;
    }

    /** Текст ответа без «мыслей» модели. */
    private static function extractText(?array $json): ?string
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if (empty($part['thought']) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        return trim($text) === '' ? null : $text;
    }
}
