<?php

namespace App\Telegram;

use RuntimeException;

/** Код исключения — HTTP-статус Telegram (409 — другой poller с тем же токеном). */
final class TelegramException extends RuntimeException {}
