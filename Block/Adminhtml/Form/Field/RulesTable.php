<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Exception\LocalizedException;

/**
 * The reminder rules table. One row per payment method.
 *
 * A row's presence means the method is enabled. There is no separate on/off column, because two ways
 * to switch one thing off invites the two to disagree.
 */
class RulesTable extends AbstractFieldArray
{
    /**
     * @var PaymentMethodColumn|null
     */
    private ?PaymentMethodColumn $methodRenderer = null;

    /**
     * @var TemplateColumn|null
     */
    private ?TemplateColumn $templateRenderer = null;

    /**
     * Define the table's columns.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->addColumn('payment_method', [
            'label' => __('Payment method'),
            'renderer' => $this->getMethodRenderer(),
        ]);
        $this->addColumn('delay_days', [
            'label' => __('Delay (days)'),
            'class' => 'validate-digits validate-digits-range digits-range-1-365',
        ]);
        $this->addColumn('email_template', [
            'label' => __('Email template'),
            'renderer' => $this->getTemplateRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add rule');

        parent::_construct();
    }

    /**
     * Get the payment method column renderer.
     *
     * @return PaymentMethodColumn
     * @throws LocalizedException
     */
    private function getMethodRenderer(): PaymentMethodColumn
    {
        if ($this->methodRenderer === null) {
            $this->methodRenderer = $this->getLayout()->createBlock(
                PaymentMethodColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->methodRenderer;
    }

    /**
     * Get the email template column renderer.
     *
     * @return TemplateColumn
     * @throws LocalizedException
     */
    private function getTemplateRenderer(): TemplateColumn
    {
        if ($this->templateRenderer === null) {
            $this->templateRenderer = $this->getLayout()->createBlock(
                TemplateColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->templateRenderer;
    }

    /**
     * Marks the stored option as selected when an existing row is re-rendered.
     *
     * @param \Magento\Framework\DataObject $row
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(\Magento\Framework\DataObject $row): void
    {
        $method = $row->getData('payment_method');
        $template = $row->getData('email_template');

        $row->setData('option_extra_attrs', [
            'option_' . $this->getMethodRenderer()->calcOptionHash((string)$method) => 'selected="selected"',
            'option_' . $this->getTemplateRenderer()->calcOptionHash((string)$template) => 'selected="selected"',
        ]);
    }
}
