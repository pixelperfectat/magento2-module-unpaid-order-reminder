<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Model\Data;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructions;

class PaymentInstructionsTest extends TestCase
{
    public function testEveryFieldIsOptionalAndDefaultsToNull(): void
    {
        $instructions = new PaymentInstructions();

        $this->assertNull($instructions->getInstructionsHtml());
        $this->assertNull($instructions->getBankName());
        $this->assertNull($instructions->getBankAccount());
        $this->assertNull($instructions->getBankBic());
        $this->assertNull($instructions->getReference());
        $this->assertNull($instructions->getExpiresAt());
        $this->assertNull($instructions->getPaymentUrl());
    }

    public function testCarriesStructuredBankDetails(): void
    {
        $instructions = new PaymentInstructions(
            bankName: 'Example Bank',
            bankAccount: 'NL00INGB0000000000',
            bankBic: 'INGBNL2A',
            reference: 'ABC-1234-DEF',
            expiresAt: '2026-09-15 04:00:00',
            paymentUrl: 'https://example.com/pay/abc'
        );

        $this->assertSame('Example Bank', $instructions->getBankName());
        $this->assertSame('NL00INGB0000000000', $instructions->getBankAccount());
        $this->assertSame('INGBNL2A', $instructions->getBankBic());
        $this->assertSame('ABC-1234-DEF', $instructions->getReference());
        $this->assertSame('2026-09-15 04:00:00', $instructions->getExpiresAt());
        $this->assertSame('https://example.com/pay/abc', $instructions->getPaymentUrl());
    }

    public function testCarriesAFreeTextBlockForOfflineMethods(): void
    {
        $instructions = new PaymentInstructions(
            instructionsHtml: 'Transfer to IBAN AT00 0000 0000 0000 0000.',
            reference: '000000123'
        );

        $this->assertSame('Transfer to IBAN AT00 0000 0000 0000 0000.', $instructions->getInstructionsHtml());
        $this->assertSame('000000123', $instructions->getReference());
    }

    /**
     * The template asks this question to decide whether to render the bank table or the free-text
     * block. An account number alone is not enough to pay with, so all three must be present.
     */
    public function testHasStructuredBankDetailsOnlyWhenNameAccountAndReferenceAreAllPresent(): void
    {
        $complete = new PaymentInstructions(
            bankName: 'Example Bank',
            bankAccount: 'NL00INGB0000000000',
            reference: 'ABC-1234-DEF'
        );
        $this->assertTrue($complete->hasStructuredBankDetails());

        $noReference = new PaymentInstructions(
            bankName: 'Example Bank',
            bankAccount: 'NL00INGB0000000000'
        );
        $this->assertFalse($noReference->hasStructuredBankDetails());

        $freeTextOnly = new PaymentInstructions(instructionsHtml: 'Pay us.');
        $this->assertFalse($freeTextOnly->hasStructuredBankDetails());
    }
}
