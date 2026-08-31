<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\View\Frontend\Email;

use PHPUnit\Framework\TestCase;

/**
 * Guards against a two-layer bug found in code review.
 *
 * Magento\Framework\Filter\VariableResolver\StrictResolver gates template member access twice:
 * shouldHandleDataAccess() first allows only a DataObject, an AbstractTemplate, or an array as the
 * left-hand side, and only then does handleDataAccess() further require a method name starting
 * with "get". A plain typed value object - e.g. PaymentInstructions - is none of those, so it
 * fails the first gate regardless of method name: every dotted call on it silently resolves to
 * null. The fix is to precompute such fields in PHP and pass them as scalar template variables,
 * never the object itself.
 *
 * The two checks below are complementary and each guards one gate: which variables may be
 * dotted at all, and which methods may be called once a variable clears that gate.
 */
class UnpaidOrderReminderTemplateTest extends TestCase
{
    /**
     * Variables known to satisfy StrictResolver::shouldHandleDataAccess() - i.e. known to be a
     * DataObject, an AbstractTemplate, or an array - and therefore the only variables this
     * template may access with dotted (property or method) syntax. `order` is a
     * Magento\Sales\Api\Data\OrderInterface implementation, which extends AbstractModel, which
     * extends DataObject. `store` is the store view object the email header/footer templates
     * resolve through, also a DataObject.
     *
     * Anything else - any other value object a future field might introduce - fails that gate
     * regardless of method name and must be flattened into a scalar template variable by the
     * sender first. This allowlist encodes the rule so the guard survives a future variable
     * with a different name than "instructions".
     *
     * @var array<int, string>
     */
    private const VARIABLES_SATISFYING_THE_DATA_OBJECT_GATE = ['order', 'store'];

    public function testTemplateOnlyDotsVariablesKnownToSatisfyTheDataObjectGate(): void
    {
        $contents = (string)file_get_contents($this->templatePath());

        preg_match_all('/\$?\b([A-Za-z_][A-Za-z0-9_]*)\.[A-Za-z_][A-Za-z0-9_]*/', $contents, $matches);
        $dottedVariables = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($dottedVariables, 'Expected the template to dot-access at least one variable.');

        $offenders = array_values(array_diff($dottedVariables, self::VARIABLES_SATISFYING_THE_DATA_OBJECT_GATE));

        $this->assertSame(
            [],
            $offenders,
            'A variable may only be dot-accessed in this template when it is a DataObject, an '
            . 'AbstractTemplate, or an array (StrictResolver::shouldHandleDataAccess()) - '
            . 'otherwise every dotted access on it silently resolves to null. Found a dotted '
            . 'variable outside the known-safe allowlist: ' . implode(', ', $offenders)
        );
    }

    public function testTemplateCallsNoMethodWhoseNameDoesNotStartWithGet(): void
    {
        $contents = (string)file_get_contents($this->templatePath());

        // order.* (and store.*) are exempt from nothing here: this check is only about the
        // get-prefix requirement that applies once a variable has already cleared the DataObject
        // gate asserted above.
        preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\.([A-Za-z_][A-Za-z0-9_]*)\(\)/', $contents, $matches);
        $methodNames = $matches[1];

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

    private function templatePath(): string
    {
        return __DIR__ . '/../../../../../view/frontend/email/unpaid_order_reminder.html';
    }
}
