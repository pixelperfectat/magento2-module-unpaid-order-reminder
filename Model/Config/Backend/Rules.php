<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Serialises the reminder rules table into one configuration value.
 *
 * Magento's dynamic-rows field submits an array keyed by throwaway row ids. Those ids change on every
 * render, so keeping them would make an identical table serialise to a different string each save.
 * The payment method is the natural key, and re-keying on it also makes a duplicate method
 * detectable.
 */
class Rules extends Value
{
    /**
     * Magento\Framework\App\Config\Value does NOT supply a serializer - its constructor takes only
     * Context, Registry, ScopeConfigInterface, TypeListInterface and the optional resource arguments.
     * It is injected here, ahead of the optional arguments, following the Magento core pattern.
     *
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param SerializerInterface $serializer
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SerializerInterface $serializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Re-key the submitted rows by payment method and validate them before the value is persisted.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $value = $this->getValue();

        // An untouched field is handed back as the stored string; there is nothing to re-encode.
        if (!is_array($value)) {
            return parent::beforeSave();
        }

        $rules = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $method = trim((string)($row['payment_method'] ?? ''));
            if ($method === '') {
                continue;
            }

            if (isset($rules[$method])) {
                throw new LocalizedException(__('Each payment method may appear only once.'));
            }

            $delay = $row['delay_days'] ?? '';
            if (!is_numeric($delay) || (int)$delay < 1 || (string)(int)$delay !== trim((string)$delay)) {
                throw new LocalizedException(__('Delay (days) must be a whole number of at least 1.'));
            }

            $rules[$method] = [
                'delay_days' => (int)$delay,
                'email_template' => trim((string)($row['email_template'] ?? '')),
            ];
        }

        // json_encode([]) is "[]", not "{}" - an empty array must be forced to an object so an empty
        // table round-trips to the same value etc/config.xml ships as the default.
        $this->setValue($this->serializer->serialize($rules === [] ? new \stdClass() : $rules));

        return parent::beforeSave();
    }
}
