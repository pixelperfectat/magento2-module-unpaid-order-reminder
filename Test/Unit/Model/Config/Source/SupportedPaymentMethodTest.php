<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Model\Config\Source;

use Magento\Payment\Model\Config as PaymentConfig;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Config\Source\SupportedPaymentMethod;

class SupportedPaymentMethodTest extends TestCase
{
    /**
     * The dropdown must be generated from the provider pool, never from a second list. An
     * administrator can then never enable a method this installation cannot describe.
     */
    public function testOffersOnlyMethodsThatHaveAProvider(): void
    {
        $source = new SupportedPaymentMethod(
            $this->poolSupporting(['banktransfer', 'checkmo']),
            $this->paymentConfigWithTitles([
                'banktransfer' => 'Bank Transfer Payment',
                'checkmo' => 'Check / Money order',
                'free' => 'No Payment Information Required',
            ])
        );

        $this->assertSame(
            [
                ['value' => 'banktransfer', 'label' => 'Bank Transfer Payment'],
                ['value' => 'checkmo', 'label' => 'Check / Money order'],
            ],
            $source->toOptionArray()
        );
    }

    /**
     * A provider can exist for a method the shop has not configured a title for. Showing the raw code
     * is better than hiding a method that would in fact work.
     */
    public function testFallsBackToTheMethodCodeWhenNoTitleIsConfigured(): void
    {
        $source = new SupportedPaymentMethod(
            $this->poolSupporting(['mollie_methods_banktransfer']),
            $this->paymentConfigWithTitles([])
        );

        $this->assertSame(
            [['value' => 'mollie_methods_banktransfer', 'label' => 'mollie_methods_banktransfer']],
            $source->toOptionArray()
        );
    }

    public function testAnEmptyPoolOffersNothing(): void
    {
        $source = new SupportedPaymentMethod($this->poolSupporting([]), $this->paymentConfigWithTitles([]));

        $this->assertSame([], $source->toOptionArray());
    }

    /**
     * @param array<int, string> $methods
     * @return ProviderPoolInterface
     */
    private function poolSupporting(array $methods): ProviderPoolInterface
    {
        $pool = $this->createMock(ProviderPoolInterface::class);
        $pool->method('getSupportedMethods')->willReturn($methods);

        return $pool;
    }

    /**
     * @param array<string, string> $titles
     * @return PaymentConfig
     */
    private function paymentConfigWithTitles(array $titles): PaymentConfig
    {
        // Magento\Payment\Model\Config has no getAllMethods(); real callers read getMethodsInfo(),
        // the method-code -> declared-info map assembled from every module's etc/methods.xml.
        $methods = [];
        foreach ($titles as $code => $title) {
            $methods[$code] = ['label' => $title];
        }

        $config = $this->createMock(PaymentConfig::class);
        $config->method('getMethodsInfo')->willReturn($methods);

        return $config;
    }
}
