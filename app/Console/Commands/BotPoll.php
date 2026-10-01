<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
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
        // Обработчики сигналов — до любых ожиданий: в контейнере процесс идёт как PID 1, без обработчика
        // SIGTERM от `docker compose down` он не получит и будет убит только по истечении stop_grace_period.
        $this->trapSignals();

        if (trim((string) config('promo.telegram.token')) === '') {
            $this->error('TELEGRAM_BOT_TOKEN пуст: впишите токен бота в .env и перезапустите `docker compose up`.');
            $this->pause(30); // сервис перезапускается автоматически, не засоряем лог

            return self::FAILURE;
        }
        if (trim((string) config('promo.gemini.key')) === '') {
            $this->warn('GEMINI_API_KEY пуст: бот будет передавать все вопросы операторам. Впишите ключ в .env и перезапустите сервис: docker compose restart bot.');
        }
        try {
            $me = $telegram->getMe();
        } catch (TelegramException $e) {
            $hint = in_array($e->getCode(), [401, 404], true) ? ' Похоже, TELEGRAM_BOT_TOKEN в .env неверный.' : '';
            $this->error('Telegram не отвечает: '.$e->getMessage().$hint);
            $this->pause(30);

            return self::FAILURE;
        }
        $this->info("Бот @{$me['username']} слушает сообщения.");

        $offset = (int) Cache::get('telegram.offset', 0);
        while ($this->running) {
            try {
                // 20 с, а не 30: более долгие соединения без данных иногда рвутся по пути (Docker Desktop, NAT).
                $updates = $telegram->getUpdates($offset, $this->option('once') ? 0 : 20);
            } catch (TelegramException $e) {
                if ($this->option('once')) {
                    $this->error('getUpdates: '.$e->getMessage());

                    return self::FAILURE;
                }
                if ($e->getCode() === 409) {
                    $this->error('409: другой процесс уже получает сообщения с этим токеном. Жду 30 с.');
                    $this->pause(30);
                } else {
                    $this->warn('getUpdates: '.$e->getMessage());
                    $this->pause(5);
                }

                continue;
            }

            foreach ($updates as $update) {
                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    // URL HTTP-клиента может содержать токен, а в QueryException — текст участника: для них только класс.
                    $quiet = $e instanceof ConnectionException || $e instanceof RequestException || $e instanceof QueryException;
                    $details = $quiet ? '' : ': '.$e->getMessage();
                    Log::error('Сбой обработки сообщения '.class_basename($e).$details);
                }
                // Offset сохраняем после обработки: если процесс упал посреди неё, сообщение придёт снова,
                // а дубль отсечёт уникальный индекс.
                $offset = $update['update_id'] + 1;
                Cache::forever('telegram.offset', $offset);
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

    /** Ожидание, которое прерывает SIGTERM: иначе остановка контейнера ждала бы весь stop_grace_period. */
    private function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds && $this->running; $i++) {
            sleep(1);
        }
    }
}
