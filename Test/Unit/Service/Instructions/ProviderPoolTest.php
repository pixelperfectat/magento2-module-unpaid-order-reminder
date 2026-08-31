<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Instructions;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Service\Instructions\ProviderPool;

class ProviderPoolTest extends TestCase
{
    public function testReturnsTheProviderRegisteredForAMethod(): void
    {
        $offline = $this->createMock(PaymentInstructionsProviderInterface::class);
        $pool = new ProviderPool(['banktransfer' => $offline]);

        $this->assertSame($offline, $pool->getProvider('banktransfer'));
    }

    public function testReturnsNullForAMethodWithNoProvider(): void
    {
        $pool = new ProviderPool(['banktransfer' => $this->createMock(PaymentInstructionsProviderInterface::class)]);

        $this->assertNull($pool->getProvider('mollie_methods_banktransfer'));
        $this->assertFalse($pool->supports('mollie_methods_banktransfer'));
    }

    public function testSupportsAMethodThatHasAProvider(): void
    {
        $pool = new ProviderPool(['checkmo' => $this->createMock(PaymentInstructionsProviderInterface::class)]);

        $this->assertTrue($pool->supports('checkmo'));
    }

    /**
     * This list is the only registry of describable methods. The admin dropdown and the selection
     * criterion both read it, so it must be the method codes and nothing else.
     */
    public function testListsEverySupportedMethodCode(): void
    {
        $provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $pool = new ProviderPool([
            'banktransfer' => $provider,
            'checkmo' => $provider,
        ]);

        $this->assertSame(['banktransfer', 'checkmo'], $pool->getSupportedMethods());
    }

    /**
     * An integrator disables a shipped provider by setting its di.xml item to null. The pool must
     * then behave as though it was never registered.
     */
    public function testIgnoresAProviderDisabledWithANullItem(): void
    {
        $pool = new ProviderPool([
            'banktransfer' => null,
            'checkmo' => $this->createMock(PaymentInstructionsProviderInterface::class),
        ]);

        $this->assertSame(['checkmo'], $pool->getSupportedMethods());
        $this->assertNull($pool->getProvider('banktransfer'));
        $this->assertFalse($pool->supports('banktransfer'));
    }

    public function testAnEmptyPoolSupportsNothing(): void
    {
        $pool = new ProviderPool();

        $this->assertSame([], $pool->getSupportedMethods());
        $this->assertNull($pool->getProvider('banktransfer'));
    }
}
