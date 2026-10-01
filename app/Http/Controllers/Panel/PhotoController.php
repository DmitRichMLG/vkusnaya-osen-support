<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use finfo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Фото участника: панель забирает файл у Telegram по file_id и отдаёт оператору по своему адресу.
 * Токен бота в браузер не попадает, на диск ничего не пишем; браузер кеширует картинку сам.
 * Отдаём только изображения, тип определяем по байтам файла (Telegram присылает octet-stream): что бы ни прислал участник,
 * в браузере оператора это картинка, не HTML и не скрипт.
 * В модель картинка не уходит никогда (см. UpdateHandler): надпись на фото не может стать инструкцией для бота.
 */
class PhotoController extends Controller
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(Message $message, TelegramClient $telegram): Response
    {
        abort_if($message->telegram_file_id === null, 404);

        try {
            $content = $telegram->downloadFile($message->telegram_file_id);
        } catch (TelegramException $e) {
            Log::warning("Фото сообщения {$message->id} не получено из Telegram: ".$e->getMessage());
            abort(502, 'Не удалось получить фото из Telegram.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        abort_unless(in_array($mime, self::IMAGE_TYPES, true), 415, 'Файл не является изображением.');

        return response($content, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
