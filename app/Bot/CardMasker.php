<?php

namespace App\Bot;

/**
 * Прячет номера карт до записи в базу и до отправки в модель.
 * Правило разработчика: любые 13–19 цифр подряд, с пробелами, дефисами или точками,
 * без проверки по Луну. Не трогаем:
 * - российские мобильные номера («+7 910 123-45-67», «8 910 123 45 67»): их сначала прячем за плейсхолдер;
 * - реквизиты чека (ФН, ФД, ФП, РН ККТ): по подписи в 20 символах перед числом
 *   или по виду — несколько групп цифр, среди которых есть длиннее 4 (карты пишут группами по 4).
 */
final class CardMasker
{
    private const PHONE = '/(?<!\d)(?:\+7|8)[ \-]?\(?9\d{2}\)?[ \-]?\d{3}[ \-]?\d{2}[ \-]?\d{2}(?!\d)/u';

    private const CANDIDATE = '/(?<![\d\p{L}])\d(?:[ \-.\x{00A0}\x{202F}\x{2009}]?\d){12,18}(?![\d\p{L}])/u';

    private const RECEIPT_LABEL = '/(?:ФН|FN|ФД|ФП|ФПД|ККТ|фискальн\w*\s+(?:номер|признак|документ))\b[^\d]{0,12}$/iu';

    public static function mask(string $text): string
    {
        $phones = [];
        $text = (string) preg_replace_callback(self::PHONE, function (array $m) use (&$phones) {
            $phones[] = $m[0];

            return "\u{E000}".(count($phones) - 1)."\u{E001}";
        }, $text);

        $text = (string) preg_replace_callback(self::CANDIDATE, function (array $m) use ($text) {
            [$candidate, $offset] = $m[0];
            $digits = (string) preg_replace('/\D/', '', $candidate);
            if (strlen($digits) < 13 || strlen($digits) > 19 || self::looksLikeReceiptData($candidate)) {
                return $candidate;
            }
            $before = mb_substr(mb_strcut($text, 0, $offset), -20);
            $after = mb_substr(substr($text, $offset + strlen($candidate)), 0, 2);
            // Подпись реквизита перед числом или ещё группы цифр по соседству: это данные чека, а не карта.
            if (preg_match(self::RECEIPT_LABEL, $before) || preg_match('/\d[ \-.]$/u', $before) || preg_match('/^[ \-.]\d/u', $after)) {
                return $candidate;
            }

            return '**** **** **** '.substr($digits, -4);
        }, $text, -1, $count, PREG_OFFSET_CAPTURE);

        return (string) preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn (array $m) => $phones[(int) $m[1]], $text);
    }

    public static function contains(string $text): bool
    {
        return self::mask($text) !== $text;
    }

    /** «7380440700076549 45123 2849561734» — ФН, ФД и ФП одной строкой, а не карта. */
    private static function looksLikeReceiptData(string $candidate): bool
    {
        $groups = preg_split('/[^\d]+/', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($groups) < 2) {
            return false;
        }
        foreach ($groups as $group) {
            if (strlen($group) > 4) {
                return true;
            }
        }

        return false;
    }
}
