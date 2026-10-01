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

    public function test_card_with_dashes_dots_nbsp_and_without_separators(): void
    {
        $this->assertSame('**** **** **** 3456', CardMasker::mask('2200-1234-5678-3456'));
        $this->assertSame('карта **** **** **** 3456.', CardMasker::mask('карта 2200123456783456.'));
        $this->assertSame('**** **** **** 9012 и **** **** **** 9012', CardMasker::mask("2200\u{00A0}1234\u{00A0}5678\u{00A0}9012 и 2200.1234.5678.9012"));
    }

    public function test_phone_numbers_are_not_masked(): void
    {
        $this->assertSame('+7 910 123-45-67', CardMasker::mask('+7 910 123-45-67'));
        $this->assertSame('+79101234567', CardMasker::mask('+79101234567'));
        $this->assertSame('мой номер 8 910 123 45 67 10 чеков висят', CardMasker::mask('мой номер 8 910 123 45 67 10 чеков висят'));
    }

    public function test_receipt_data_is_not_masked(): void
    {
        $this->assertSame('ФН 9960440300123456 ФД 12345', CardMasker::mask('ФН 9960440300123456 ФД 12345'));
        $this->assertSame('ФН чека 7380440700076549.', CardMasker::mask('ФН чека 7380440700076549.'));
        $this->assertSame('fn=9960440300123456', CardMasker::mask('fn=9960440300123456'));
        $this->assertSame('РН ККТ 0004567890123456, чек отклонили', CardMasker::mask('РН ККТ 0004567890123456, чек отклонили'));
        $this->assertSame('вот данные: 7380440700076549 45123 2849561734', CardMasker::mask('вот данные: 7380440700076549 45123 2849561734'));
        $this->assertSame('фн 9960440300123456', CardMasker::mask('фн 9960440300123456'));
        $this->assertSame('ФН чека: 9960440300123456', CardMasker::mask('ФН чека: 9960440300123456'));
        $this->assertSame('фискальный номер 9960440300123456', CardMasker::mask('фискальный номер 9960440300123456'));
    }

    public function test_card_next_to_receipt_data_is_still_masked(): void
    {
        $this->assertSame(
            'Карта для приза **** **** **** 5678, ФН чека 7380440700076549. Почему чек не приняли?',
            CardMasker::mask('Карта для приза 4276-1600-1234-5678, ФН чека 7380440700076549. Почему чек не приняли?'),
        );
    }

    public function test_too_short_or_too_long_sequences_stay(): void
    {
        $this->assertSame('123456789012', CardMasker::mask('123456789012'));
        $this->assertSame('12345678901234567890', CardMasker::mask('12345678901234567890'));
    }

    public function test_contains(): void
    {
        $this->assertTrue(CardMasker::contains('карта 2200 1234 5678 9012'));
        $this->assertFalse(CardMasker::contains('+7 910 123-45-67 и ФН 9960440300123456'));
    }
}
