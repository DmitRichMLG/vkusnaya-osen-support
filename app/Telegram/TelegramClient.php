<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bot API через HTTP-клиент Laravel. Токен стоит в URL, поэтому исключения клиента
 * наружу не выпускаем: в их тексте есть адрес запроса.
 */
final class TelegramClient
{
    public function __construct(private readonly string $token) {}

    /** @return array{id: int, username: string} */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 30): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message'],
        ], $timeout + 10);
    }

    /** Обычный текст без разметки: звёздочки маскирования и символы участников ничего не ломают. */
    public function sendMessage(int $chatId, string $text): int
    {
        $result = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);

        return (int) $result['message_id'];
    }

    /**
     * Файл по file_id (фото участника): getFile даёт путь, затем скачиваем по нему.
     * В обоих URL есть токен, поэтому ошибки оборачиваем так же, как в call().
     * Возвращаем только байты: Content-Type Telegram не присылает (application/octet-stream), тип определяет вызывающий.
     */
    public function downloadFile(string $fileId): string
    {
        $path = (string) ($this->call('getFile', ['file_id' => $fileId])['file_path'] ?? '');
        if ($path === '') {
            throw new TelegramException('getFile: в ответе нет file_path');
        }

        try {
            $response = Http::timeout(30)->get("https://api.telegram.org/file/bot{$this->token}/{$path}");
        } catch (Throwable $e) {
            throw new TelegramException('download: '.class_basename($e).': '.str_replace($this->token, '<token>', $e->getMessage()));
        }

        if (! $response->ok()) {
            throw new TelegramException("download: HTTP {$response->status()}", $response->status());
        }

        return $response->body();
    }

    private function call(string $method, array $params = [], int $timeout = 20): array
    {
        try {
            $response = Http::timeout($timeout)
                ->asJson()
                ->post("https://api.telegram.org/bot{$this->token}/{$method}", $params);
        } catch (Throwable $e) {
            // В тексте ошибки HTTP-клиента есть URL с токеном: прячем его.
            throw new TelegramException("{$method}: ".class_basename($e).': '.str_replace($this->token, '<token>', $e->getMessage()));
        }

        if (! $response->ok() || $response->json('ok') !== true) {
            throw new TelegramException(
                "{$method}: HTTP {$response->status()}: ".(string) $response->json('description', 'нет описания'),
                $response->status(),
            );
        }

        return (array) $response->json('result');
    }
}
