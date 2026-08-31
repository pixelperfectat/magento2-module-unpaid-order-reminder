<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Model\Config\Backend;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Model\Config\Backend\Rules;

class RulesTest extends TestCase
{
    /**
     * Magento hands a dynamic-rows field an array keyed by throwaway row ids. Those ids must not
     * reach storage, or a saved value differs from an identical one entered a second time.
     */
    public function testSerialisesRowsAsAListKeyedByPaymentMethod(): void
    {
        $rules = $this->rulesWithValue([
            '_1700000000_1' => ['payment_method' => 'banktransfer', 'delay_days' => '7', 'email_template' => 'tpl_a'],
            '_1700000000_2' => ['payment_method' => 'checkmo', 'delay_days' => '5', 'email_template' => 'tpl_b'],
        ]);

        $rules->beforeSave();

        $this->assertSame(
            '{"banktransfer":{"delay_days":7,"email_template":"tpl_a"},'
            . '"checkmo":{"delay_days":5,"email_template":"tpl_b"}}',
            $rules->getValue()
        );
    }

    public function testDropsARowWithNoPaymentMethod(): void
    {
        $rules = $this->rulesWithValue([
            '_1' => ['payment_method' => '', 'delay_days' => '7', 'email_template' => 'tpl_a'],
            '_2' => ['payment_method' => 'checkmo', 'delay_days' => '5', 'email_template' => 'tpl_b'],
        ]);

        $rules->beforeSave();

        $this->assertSame('{"checkmo":{"delay_days":5,"email_template":"tpl_b"}}', $rules->getValue());
    }

    /**
     * A zero or negative delay would mail every unpaid order on the next cron run.
     */
    public function testRejectsADelayBelowOneDay(): void
    {
        $rules = $this->rulesWithValue([
            '_1' => ['payment_method' => 'checkmo', 'delay_days' => '0', 'email_template' => 'tpl_b'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Delay (days) must be a whole number of at least 1.');

        $rules->beforeSave();
    }

    public function testRejectsANonNumericDelay(): void
    {
        $rules = $this->rulesWithValue([
            '_1' => ['payment_method' => 'checkmo', 'delay_days' => 'soon', 'email_template' => 'tpl_b'],
        ]);

        $this->expectException(LocalizedException::class);

        $rules->beforeSave();
    }

    public function testRejectsTwoRowsForTheSamePaymentMethod(): void
    {
        $rules = $this->rulesWithValue([
            '_1' => ['payment_method' => 'checkmo', 'delay_days' => '5', 'email_template' => 'tpl_a'],
            '_2' => ['payment_method' => 'checkmo', 'delay_days' => '9', 'email_template' => 'tpl_b'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Each payment method may appear only once.');

        $rules->beforeSave();
    }

    public function testAnEmptyTableSerialisesToAnEmptyObject(): void
    {
        $rules = $this->rulesWithValue([]);

        $rules->beforeSave();

        $this->assertSame('{}', $rules->getValue());
    }

    /**
     * Magento re-saves an untouched field by handing back the stored string.
     */
    public function testLeavesAnAlreadySerialisedValueAlone(): void
    {
        $rules = $this->rulesWithValue('{"checkmo":{"delay_days":5,"email_template":"tpl_b"}}');

        $rules->beforeSave();

        $this->assertSame('{"checkmo":{"delay_days":5,"email_template":"tpl_b"}}', $rules->getValue());
    }

    /**
     * @param array<string, array<string, string>>|string $value
     * @return Rules
     */
    private function rulesWithValue(array|string $value): Rules
    {
        // getValue()/setValue() are magic methods (Magento\Framework\DataObject::__call), so they must
        // be added rather than configured via onlyMethods(), which requires a declared method.
        $rules = $this->getMockBuilder(Rules::class)
            ->addMethods(['getValue', 'setValue'])
            ->disableOriginalConstructor()
            ->getMock();

        // The constructor is disabled, so the injected serializer must be placed by hand.
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $property = new \ReflectionProperty(Rules::class, 'serializer');
        $property->setAccessible(true);
        $property->setValue($rules, $serializer);

        // The constructor is disabled, so beforeSave()'s call into the parent chain would otherwise
        // dispatch on a null event manager.
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManagerProperty = new \ReflectionProperty(AbstractModel::class, '_eventManager');
        $eventManagerProperty->setAccessible(true);
        $eventManagerProperty->setValue($rules, $eventManager);

        $stored = $value;
        $rules->method('getValue')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $rules->method('setValue')->willReturnCallback(static function ($new) use (&$stored, $rules) {
            $stored = $new;
            return $rules;
        });

        return $rules;
    }
}
