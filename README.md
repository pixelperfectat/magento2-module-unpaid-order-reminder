# Unpaid Order Reminder for Magento 2

Sends one reminder for an order that is still awaiting payment, carrying the instructions the shopper
needs to complete it, while the payment window is still open. Records that the reminder was sent, and
reports how many reminded orders were then paid.

Magento cancels an unpaid order when it expires. Nothing in Magento asks the shopper to pay first.

## What it covers

Out of the box this package handles Magento's offline payment methods — bank transfer, check or money
order, cash on delivery and purchase order — whose instructions live in store configuration.

Payment providers that host their own transfer instructions need a companion package, because those
instructions are per-payment and must be fetched from the provider:

| Provider | Package |
|---|---|
| Mollie bank transfer | `pixelperfectat/magento2-module-unpaid-order-reminder-mollie` |

## Install

```bash
composer require pixelperfectat/magento2-module-unpaid-order-reminder
bin/magento module:enable PixelPerfect_UnpaidOrderReminder
bin/magento setup:upgrade
```

The module ships **disabled**. Nothing is sent until you enable it.

## Configure

`Stores → Configuration → Sales → Unpaid Order Reminder`

| Setting | Meaning |
|---|---|
| Enabled | Master switch. Default: No. |
| Sender | The store email identity the reminder is sent from. |
| Send copy to | Optional BCC address. |
| Do not remind orders older than (days) | Upper age bound. Default: 30. Use 0 for no limit. |
| Reminder rules | One row per payment method: the delay in days, and the email template. |

The payment-method dropdown lists only methods that have a registered instructions provider, so a rule
can never point at a method this module cannot describe.

**Set the age bound before you switch the module on.** Without it, the first run selects every unpaid
order the shop has ever taken, and mails people about orders they placed months ago. An order that has
a payment deadline is already protected, because a reminder is never sent once that window has closed.
A method with no deadline at all - an offline bank transfer, for instance - has nothing to protect it
but this bound.

## Commands

```bash
bin/magento pixelperfect:unpaidorder:send-reminders --dry-run   # list, send nothing
bin/magento pixelperfect:unpaidorder:send-reminders
bin/magento pixelperfect:unpaidorder:reminder-stats
```

A cron job runs the same code daily.

`reminder-stats` reports four groups: reminded, paid, still unpaid, and expired unpaid. The expired
group also fires for a reminder whose payment window closed without payment — but the offline
providers this package ships (bank transfer, check/money order, cash on delivery, purchase order)
never set an expiry, so out of the box that branch is dormant and every expired row comes from an
order that was cancelled. A provider that does set an expiry (see the Mollie companion package) will
start populating it.

## Adding a payment method

Implement `PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface` and add it
to the provider pool in your own `di.xml`. See the Mollie package for a worked example.

## Licence

MIT.
