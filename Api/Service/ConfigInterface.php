<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;

/**
 * The module's admin configuration, read at store scope.
 */
interface ConfigInterface
{
    /**
     * Whether the module is enabled at the given scope.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * The rules that can actually run: configured in admin AND backed by an installed provider.
     *
     * @param int|null $storeId
     * @return array<string, ReminderRuleInterface> keyed by payment method code
     */
    public function getRules(?int $storeId = null): array;

    /**
     * Get the store email identity to send from.
     *
     * @param int|null $storeId
     * @return string the store email identity to send from
     */
    public function getSender(?int $storeId = null): string;

    /**
     * Get the list of BCC addresses.
     *
     * @param int|null $storeId
     * @return array<int, string>
     */
    public function getBcc(?int $storeId = null): array;
}
