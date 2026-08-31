<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\View\Frontend\Email;

use PHPUnit\Framework\TestCase;

/**
 * Guards against a class of bug found in code review: Magento's
 * Magento\Framework\Filter\VariableResolver\StrictResolver::handleDataAccess() only resolves a
 * template method call whose name starts with "get" (it checks `substr($name, 0, 3) == 'get'`).
 * A call to any other method - e.g. instructions.hasStructuredBankDetails() - silently resolves
 * to null, so a {{depend}} guarded by it is always false and the guarded block never renders,
 * with no error anywhere. Any such value must be precomputed in PHP and passed in as a plain
 * template variable instead.
 */
class UnpaidOrderReminderTemplateTest extends TestCase
{
    public function testTemplateCallsNoMethodWhoseNameDoesNotStartWithGet(): void
    {
        $path = __DIR__ . '/../../../../../view/frontend/email/unpaid_order_reminder.html';
        $this->assertFileExists($path);

        $contents = (string)file_get_contents($path);

        preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\.([A-Za-z_][A-Za-z0-9_]*)\(\)/', $contents, $matches);
        $methodNames = $matches[1];

        $this->assertNotEmpty($methodNames, 'Expected the template to contain at least one method call.');

        $offenders = array_values(array_filter(
            $methodNames,
            static fn (string $name): bool => strncmp($name, 'get', 3) !== 0
        ));

        $this->assertSame(
            [],
            $offenders,
            'Magento\'s StrictResolver only resolves template method calls starting with "get"; '
            . 'found a call that would silently resolve to null: ' . implode(', ', $offenders)
        );
    }
}
