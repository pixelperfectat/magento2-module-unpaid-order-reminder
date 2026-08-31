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
 * with "get". PaymentInstructions is a plain typed value object - it is not a DataObject, not an
 * AbstractTemplate, and not an array - so it fails the first gate regardless of method name: every
 * `instructions.getXxx()` call in the template silently resolves to null, not only a non-"get"
 * one. `order.*` is unaffected because Order extends AbstractModel, which is a DataObject.
 *
 * The fix is to precompute every instructions field in PHP and pass it as its own scalar template
 * variable, never the instructions object itself. These two checks guard against reintroducing
 * either layer of the bug, in this template or a later one.
 */
class UnpaidOrderReminderTemplateTest extends TestCase
{
    public function testTemplateNeverAccessesTheInstructionsObjectDirectly(): void
    {
        $this->assertFileExists($this->templatePath());
        $contents = (string)file_get_contents($this->templatePath());

        $this->assertStringNotContainsString(
            'instructions.',
            $contents,
            'The instructions object fails StrictResolver::shouldHandleDataAccess() (it is not a '
            . 'DataObject, an AbstractTemplate, or an array), so any instructions.* access would '
            . 'silently resolve to null. Every instructions field must be passed as its own '
            . 'precomputed scalar template variable instead.'
        );
    }

    public function testTemplateCallsNoMethodWhoseNameDoesNotStartWithGet(): void
    {
        $contents = (string)file_get_contents($this->templatePath());

        // order.* is exempt: Order extends AbstractModel, which satisfies the DataObject gate,
        // so its getters DO resolve - this check is only about the get-prefix requirement that
        // applies once that first gate is passed.
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
