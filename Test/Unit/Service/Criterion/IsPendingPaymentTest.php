<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use Magento\Framework\DB\Select;
use PDO;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\IsPendingPayment;

class IsPendingPaymentTest extends TestCase
{
    use RealSelectTrait;

    /**
     * Spec regression: Magento's offline payment methods (banktransfer, checkmo, cashondelivery,
     * purchaseorder) never reach STATE_PENDING_PAYMENT - they sit in STATE_NEW. This executes the
     * criterion's own generated WHERE fragment - not a reimplementation of it - against a fixture of
     * five states, and proves it selects exactly the two pending ones and nothing else.
     */
    public function testMatchesBothDefaultStatesAndExcludesEveryOtherState(): void
    {
        $select = $this->createRealSelect();
        (new IsPendingPayment())->apply($this->createCollectionWithSelect($select), 1);

        $matched = $this->runWhereClauseAgainstFixture(
            $select,
            ['pending_payment', 'new', 'processing', 'canceled', 'complete']
        );

        $this->assertSame(['new', 'pending_payment'], $matched);
    }

    /**
     * The state list is a constructor argument so an integrator whose gateway parks orders in a
     * custom state can point the rule at it from di.xml.
     */
    public function testUsesTheInjectedStates(): void
    {
        $select = $this->createRealSelect();
        (new IsPendingPayment(['awaiting_transfer']))
            ->apply($this->createCollectionWithSelect($select), 1);

        $matched = $this->runWhereClauseAgainstFixture(
            $select,
            ['awaiting_transfer', 'pending_payment', 'new']
        );

        $this->assertSame(['awaiting_transfer'], $matched);
    }

    /**
     * Executes the WHERE fragment the criterion just built against a real SQLite table seeded with
     * one row per given state, and returns the states that matched, sorted.
     *
     * @param Select $select
     * @param string[] $fixtureStates
     * @return string[]
     */
    private function runWhereClauseAgainstFixture(Select $select, array $fixtureStates): array
    {
        $sql = $select->assemble();
        $wherePos = strpos($sql, 'WHERE ');
        $this->assertNotFalse($wherePos, 'apply() must add a WHERE clause');
        $whereClause = substr($sql, $wherePos + strlen('WHERE '));

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE main_table (state TEXT)');
        $insert = $pdo->prepare('INSERT INTO main_table (state) VALUES (?)');
        foreach ($fixtureStates as $state) {
            $insert->execute([$state]);
        }

        $matched = $pdo->query(sprintf('SELECT state FROM main_table WHERE %s', $whereClause))
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($matched);

        return $matched;
    }
}
