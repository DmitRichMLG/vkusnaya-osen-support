<?php

namespace App\Bot;

/**
 * Прячет номера карт до записи в базу и до отправки в модель.
 * Правило разработчика: любые 13–19 цифр подряд, с пробелами или дефисами,
 * без проверки по Луну. ФН чека (16 цифр) отличаем по подписи «ФН» рядом.
 */
final class CardMasker
{
    private const CANDIDATE = '/(?<![\d\p{L}])\d(?:[ \-\x{00A0}]?\d){12,18}(?![\d\p{L}])/u';

    private const FN_LABEL = '/(?:ФН|FN|fn)\s*[:№#=]?\s*$/u';

    public static function mask(string $text): string
    {
        return (string) preg_replace_callback(self::CANDIDATE, function (array $m) use ($text) {
            $digits = preg_replace('/\D/', '', $m[0]);
            if (strlen($digits) < 13 || strlen($digits) > 19) {
                return $m[0];
            }
            $before = mb_substr(mb_strcut($text, 0, strpos($text, $m[0])), -8);
            if (preg_match(self::FN_LABEL, $before)) {
                return $m[0];
            }

            return '**** **** **** '.substr($digits, -4);
        }, $text);
    }

    public static function contains(string $text): bool
    {
        return self::mask($text) !== $text;
    }
}
