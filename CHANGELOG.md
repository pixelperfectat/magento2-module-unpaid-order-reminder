# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
