# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] - 2026-09-01

### Fixed

- The reminder email contained a template filter error instead of the payment details, while the run
  still recorded the reminder as sent. The bank-details block nested one `{{depend}}` inside another,
  which Magento cannot parse: its depend pattern is non-greedy, so the outer directive closes on the
  inner closing tag and the orphaned one raises "Undefined array key directiveName". Magento then
  replaces the whole body with that warning. The inner condition now uses `{{if}}`.

## [0.2.1] - 2026-09-01

### Fixed

- `bin/magento pixelperfect:unpaidorder:send-reminders` failed every order with "Area code is not set".
  The command-line interface starts with no area code, but the sender emulates the frontend per store on
  top of one. Cron was never affected, because Magento's scheduler sets the crontab area before it runs a
  job. The command now sets that same area itself.

## [0.2.0] - 2026-09-01

### Fixed

- The module selected no order for any of the offline payment methods it ships providers for. Everything
  that inspected order state assumed `pending_payment`, but Magento's offline methods set
  `order_status = pending`, which resolves to state `new`. On its own the module therefore reminded
  nobody; it worked only alongside a provider whose gateway uses `pending_payment`.
- The reported figures overstated conversion. The efficacy reader treated any state other than
  `pending_payment` or `canceled` as paid, so a reminded offline order that had **not** been paid was
  counted as paid. This was silent — the number simply read better than reality.
- The pre-send re-read rejected offline orders as "no longer pending" for the same reason.

### Changed

- The states treated as "awaiting payment" are now a list, `IsPendingPayment::PENDING_STATES`, holding
  `pending_payment` and `new`. It is wired from `etc/di.xml` into the selection criterion, the runner's
  re-read guard and the efficacy reader, so the three cannot drift apart. Override that one argument to
  suit a gateway that parks unpaid orders elsewhere.
- `IsPendingPayment`'s constructor takes `array $states` in place of `string $state`. The class name is
  unchanged, so any `di.xml` reference still resolves.

## [0.1.0] - 2026-09-01

### Added

- Sends one reminder for an order still awaiting payment, carrying the instructions needed to
  complete it, while the payment window is still open.
- Per-method admin rules: each payment method has its own delay in days and its own email template.
  The method dropdown is generated from the registered instructions providers, so a rule can never
  name a method the installation cannot describe.
- An instructions provider for Magento's offline payment methods, reading
  `payment/<code>/instructions`. Serves bank transfer, check or money order, cash on delivery and
  purchase order.
- A reminder log with a unique constraint on the order, so one order receives one reminder even if two
  runs overlap. The order total is frozen on the row, so the figures survive a later order edit.
- `pixelperfect:unpaidorder:send-reminders`, with `--dry-run`, and
  `pixelperfect:unpaidorder:reminder-stats`, reporting how many reminded orders were then paid. The
  four reported groups partition the reminded population exactly, so the figures always add up.
- A daily cron job running the same code.

The module ships disabled.
