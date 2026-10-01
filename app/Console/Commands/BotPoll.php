<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Long polling без вебхука. Сообщения обрабатываются по одному, offset хранится в кеше. */
class BotPoll extends Command
{
    protected $signature = 'bot:poll {--once : Обработать накопившиеся сообщения и выйти}';

    protected $description = 'Принимать сообщения из Telegram и отвечать на них';

    private bool $running = true;

    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        try {
            $me = $telegram->getMe();
        } catch (TelegramException $e) {
            $this->error('Telegram не отвечает: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Бот @{$me['username']} слушает сообщения.");
        $this->trapSignals();

        $offset = (int) Cache::get('telegram.offset', 0);
        while ($this->running) {
            try {
                // 20 с, а не 30: более долгие соединения без данных иногда рвутся по пути (Docker Desktop, NAT).
                $updates = $telegram->getUpdates($offset, $this->option('once') ? 0 : 20);
            } catch (TelegramException $e) {
                if ($e->getCode() === 409) {
                    $this->error('409: другой процесс уже получает сообщения с этим токеном. Жду 30 с.');
                    sleep(30);
                } else {
                    $this->warn('getUpdates: '.$e->getMessage());
                    sleep(5);
                }

                continue;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;
                Cache::forever('telegram.offset', $offset);
                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    // URL HTTP-клиента может содержать токен, поэтому для его исключений — только класс.
                    $details = $e instanceof ConnectionException || $e instanceof RequestException ? '' : ': '.$e->getMessage();
                    Log::error('Сбой обработки сообщения '.class_basename($e).$details);
                }
            }

            if ($this->option('once')) {
                break;
            }
        }

        $this->info('Остановлен.');

        return self::SUCCESS;
    }

    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->running = false;
            });
        }
    }
}
