<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use PixelPerfect\UnpaidOrderReminder\Model\Config\Source\SupportedPaymentMethod;

/**
 * The payment method cell of the rules table.
 *
 * A Block, not a ViewModel, because Magento's dynamic-rows field renders each column by instantiating
 * a block and calling its Template lifecycle. That is the documented exception.
 */
class PaymentMethodColumn extends Select
{
    /**
     * Construct the column.
     *
     * @param Context $context
     * @param SupportedPaymentMethod $source
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly SupportedPaymentMethod $source,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set the element's input name.
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        // Returning the magic setName() call directly types as Select in PHPStan's eyes, not self;
        // returning $this keeps the declared return type honest.
        $this->setName($value);

        return $this;
    }

    /**
     * Set the element's input id.
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        $this->setId($value);

        return $this;
    }

    /**
     * Render the select element.
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->source->toOptionArray());
        }

        return parent::_toHtml();
    }
}
