<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\DistinctRenderer;
use Magento\Framework\DB\Select\ForUpdateRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\HavingRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\UnionRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PHPUnit\Framework\MockObject\MockObject;

trait RealSelectTrait
{
    /**
     * Select::where() calls the adapter's quote() even for a condition with no placeholder, and a
     * null return trips a PHP 8.3 deprecation, so the stub must always answer.
     */
    private function createRealSelect(): Select
    {
        $adapter = $this->createPartialMock(Mysql::class, ['supportStraightJoin', 'quote']);
        $adapter->method('quote')->willReturnCallback(
            static fn (mixed $value): string => "'" . (string)$value . "'"
        );

        $quote = new Quote();
        // SelectRenderer's docblock claims RendererInterface[], but its own sort()/render() read the
        // shape built here. This mirrors Magento\Framework\DB\Test\Unit\SelectTest exactly.
        // @phpstan-ignore-next-line argument.type
        $renderer = new SelectRenderer([
            'distinct' => ['renderer' => new DistinctRenderer(), 'sort' => 100, 'part' => 'distinct'],
            'columns' => ['renderer' => new ColumnsRenderer($quote), 'sort' => 200, 'part' => 'columns'],
            'union' => ['renderer' => new UnionRenderer(), 'sort' => 300, 'part' => 'union'],
            'from' => ['renderer' => new FromRenderer($quote), 'sort' => 400, 'part' => 'from'],
            'where' => ['renderer' => new WhereRenderer(), 'sort' => 500, 'part' => 'where'],
            'group' => ['renderer' => new GroupRenderer($quote), 'sort' => 600, 'part' => 'group'],
            'having' => ['renderer' => new HavingRenderer(), 'sort' => 700, 'part' => 'having'],
            'order' => ['renderer' => new OrderRenderer($quote), 'sort' => 800, 'part' => 'order'],
            'limit' => ['renderer' => new LimitRenderer(), 'sort' => 900, 'part' => 'limitcount'],
            'for_update' => ['renderer' => new ForUpdateRenderer(), 'sort' => 1000, 'part' => 'forupdate'],
        ]);

        $select = new Select($adapter, $renderer);
        $select->from(['main_table' => 'sales_order']);

        return $select;
    }

    /**
     * @param Select $select
     * @param string|null $resolvedTable value getTable() should return, when the criterion needs one
     * @return AbstractCollection&MockObject
     */
    private function createCollectionWithSelect(Select $select, ?string $resolvedTable = null): AbstractCollection
    {
        $collection = $this->createMock(AbstractCollection::class);
        $collection->method('getSelect')->willReturn($select);
        if ($resolvedTable !== null) {
            $collection->method('getTable')->willReturn($resolvedTable);
        }

        return $collection;
    }
}
