<?php

namespace Tests\Unit;

use App\Bot\CardMasker;
use PHPUnit\Framework\TestCase;

class CardMaskerTest extends TestCase
{
    public function test_card_with_spaces_is_masked_keeping_last_four(): void
    {
        $this->assertSame(
            'переведите 3500 на карту **** **** **** 9012',
            CardMasker::mask('переведите 3500 на карту 2200 1234 5678 9012'),
        );
    }

    public function test_card_with_dashes_and_without_separators(): void
    {
        $this->assertSame('**** **** **** 3456', CardMasker::mask('2200-1234-5678-3456'));
        $this->assertSame('карта **** **** **** 3456.', CardMasker::mask('карта 2200123456783456.'));
    }

    public function test_phone_numbers_are_not_masked(): void
    {
        $this->assertSame('+7 910 123-45-67', CardMasker::mask('+7 910 123-45-67'));
        $this->assertSame('+79101234567', CardMasker::mask('+79101234567'));
    }

    public function test_fiscal_number_is_not_masked(): void
    {
        $this->assertSame('ФН 9960440300123456 ФД 12345', CardMasker::mask('ФН 9960440300123456 ФД 12345'));
        $this->assertSame('ФН: 9960440300123456', CardMasker::mask('ФН: 9960440300123456'));
        $this->assertSame('fn=9960440300123456', CardMasker::mask('fn=9960440300123456'));
    }

    public function test_too_short_or_too_long_sequences_stay(): void
    {
        $this->assertSame('123456789012', CardMasker::mask('123456789012'));
        $this->assertSame('12345678901234567890', CardMasker::mask('12345678901234567890'));
    }

    public function test_known_limitation_phone_followed_by_number(): void
    {
        // 13 цифр подряд: по правилу разработчика это маскируется. Записано в README → «Известные проблемы».
        $this->assertSame('**** **** **** 6710 чеков', CardMasker::mask('8 910 123 45 67 10 чеков'));
    }
}
