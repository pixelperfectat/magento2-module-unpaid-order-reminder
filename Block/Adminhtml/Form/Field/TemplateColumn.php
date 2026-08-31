<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Block\Adminhtml\Form\Field;

use Magento\Config\Model\Config\Source\Email\Template as TemplateSource;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * The email template cell of the rules table.
 */
class TemplateColumn extends Select
{
    /**
     * The id declared in etc/email_templates.xml (Task 8). Magento's template source turns its `path`
     * into a template id by replacing "/" with "_", then asks Email\Template\Config for that id's
     * label - and that lookup THROWS UnexpectedTemplateIdValueException on an id it does not know,
     * making the whole configuration page unrenderable. This string must therefore stay identical to
     * the template id in etc/email_templates.xml.
     */
    private const DEFAULT_TEMPLATE_ID = 'unpaid_order_reminder_default';

    /**
     * Construct the column.
     *
     * @param Context $context
     * @param TemplateSource $source
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly TemplateSource $source,
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
     * Offers every saved template, plus this module's own file-based template as "(Default)".
     *
     * Without the setPath call the shipped template is unselectable on a fresh install, because the
     * source's collection reads only templates saved in the database.
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->source->setPath(self::DEFAULT_TEMPLATE_ID);
            $this->setOptions($this->source->toOptionArray());
        }

        return parent::_toHtml();
    }
}
