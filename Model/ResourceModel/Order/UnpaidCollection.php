<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\Order;

use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;

/**
 * The collection the reminder criteria narrow.
 *
 * It adds nothing of its own. It exists so this module's criteria and an integrator's own criterion
 * agree on one collection type, and so narrowing it can never affect Magento's order grid.
 */
class UnpaidCollection extends OrderCollection
{
}
