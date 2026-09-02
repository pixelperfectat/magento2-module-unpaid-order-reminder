# End-to-end suite

Proves the reminder end to end against a real Magento installation: real orders, the real selection
run, the real templates. It never sends mail — a fixture module binds a transport that writes every
message to `var/tmp/e2e/mails/` instead.

Each case is a JSON file. The runner writes the case's configuration, places the fixture orders it
asks for, runs the send command, and asserts on the reminder rows, the number of captured messages,
the decoded mail body and the command's own exit status.

## Requirements

- Magento in **developer mode**. Both fixture commands refuse to run in any other mode, because they
  write and delete orders in whatever database they find.
- `jq` on the machine that runs the script — the runner reads every case field with it.
- `perl` with the core `MIME::QuotedPrint` module. Magento encodes the HTML part as quoted-printable,
  so a URL or a date can be soft-wrapped across two lines; every body assertion runs on the decoded
  text.
- At least one enabled simple product that is in stock, has no required options, and is salable in the
  store the orders are placed in. The fixture picks the first such product itself, so no SKU is fixed.
- A shipping method that quotes for the fixture address. The fixture takes `flatrate_flatrate` when it
  is offered and the first offered rate otherwise, and fails the case when nothing is offered.
- The `checkmo` and `banktransfer` payment methods **active, and with instruction text** on the website
  the target store view belongs to. Case 6 runs `banktransfer` through the module's real offline
  provider, and that provider returns nothing when `payment/<code>/instructions` is empty — the case
  would then fail for a reason that has nothing to do with the code under test.
- A configuration file (`app/etc/config.php`) that does not lock what the run has to change. Magento
  refuses `config:set` outright while `app/etc/config.php` is out of step with the imported snapshot,
  and a locked `payment/*/active` value means the two payment methods cannot be switched on at all.

## Install the fixture module

The suite exists in a git checkout only: `.gitattributes` excludes `dev/` from the Composer dist
archive, so a package installed with Composer does not carry it.

    cp -R dev/fixtures/PixelPerfect <magento-root>/app/code/
    <magento-root>/bin/magento module:enable PixelPerfect_UnpaidOrderReminderE2e
    <magento-root>/bin/magento setup:upgrade

Disable the module and delete the copied directory when you are done.

## Run

    MAGENTO_ROOT=<magento-root> MAGENTO_CLI=bin/magento dev/tests/e2e/run.sh

| Variable | Meaning | Default |
|---|---|---|
| `MAGENTO_ROOT` | Directory the commands run from. | the current directory |
| `MAGENTO_CLI` | The Magento console, relative to `MAGENTO_ROOT`. | `bin/magento` |
| `MAGENTO_SHELL` | How to run a shell string in the Magento root. | `sh -c` |
| `E2E_STORE` | Store id for cases that do not name one. | the default store view |

On a containerised installation the Magento `var/` directory exists only inside the container, so both
the console and the shell have to be pointed at the container. With a wrapper that execs into it — for
example, and only as an example:

    MAGENTO_ROOT=<magento-root> MAGENTO_CLI="bin/magento" MAGENTO_SHELL="bin/cli sh -c" \
        dev/tests/e2e/run.sh

Pass `--keep` to leave the fixture orders, the captured mail and the last case's configuration in
place after a run.

## Configuration

The runner records `general/enabled`, `general/max_age_days` and `rules/methods` before the first case
and restores all three after the last one. Every case then overwrites them with its own values.

A case that cannot write its configuration **aborts before it places a single order**. The shop's own
rule would otherwise still be in force, and the case would send against whatever real orders that rule
selects. The runner therefore reads `rules/methods` back after writing it and fails the case when the
value did not take.

With `--keep`, the configuration left behind is the **last case's**, not the shop's. The runner prints
the recorded values so they can be restored by hand.

## Case schema

| Field | Meaning |
|---|---|
| `name` | What the case proves. Printed with its result. |
| `config.enabled` | The module's master switch. |
| `config.max_age_days` | The maximum age bound. `0` means no limit. |
| `config.rules` | The reminder rules, keyed by payment method code. |
| `scenario` | What the fixture instructions provider answers with. |
| `orders[]` | The fixture orders to place: `method`, `age_days`, `count`, and an optional `store`. |
| `runs` | How many times to run the send command. Optional, default `1`. |
| `expect.reminder_rows` | How many reminder rows the run must add. |
| `expect.mails` | How many messages must have been captured. |
| `expect.body_contains` | Strings the decoded mail body must contain. Optional. |
| `expect.body_not_contains` | Strings the decoded mail body must not contain. Optional. |
| `expect.exit_status` | The status the send command itself must return. Optional, default `0`. |

`reminder_rows` is a **difference**, not a total: the database may already hold reminders for orders the
suite never created.

The exit-status contract is plain. The send command exits non-zero whenever it skipped an order, so a
case that expects a skip asserts exactly that with `exit_status`, and does not treat a non-zero status
as a broken run.

The `scenario` object drives the fixture provider that stands in for a hosted gateway. `kind: "bank"`
returns bank details (`bank_name`, `bank_account`, `bank_bic`, `reference`, `expires_at`,
`payment_url`); `kind: "text"` returns `instructions_html`; any other kind returns nothing, which is how
a gateway that answered with nothing is modelled. The fixture replaces the provider for `checkmo` only —
`banktransfer` keeps the module's real offline provider, so production code is still exercised.

## Adding a case

Drop a JSON file into `cases/`. The runner picks it up in file name order. Read an existing case for
the schema, and keep every value in it invented — see Safety.

## What the suite does not cover

These stay manual, each for a reason:

| Case | Why it is not scripted |
|---|---|
| 4 — cancellation | The cancelled-order path is covered by the unit suite. |
| 9 to 11 — three store views | Needs three configured store views, which no installation is guaranteed to have. |
| 13 and 14 | Folded into case 12, which asserts the same rendering. |
| 19 — blind-copy header | Needs `general/bcc` set, and the runner neither records nor restores that setting. |
| 20 — efficacy | Needs an order to actually be paid, which no script can do without a gateway. |

## Safety

The reset command deletes an order only when its customer email ends in a reserved domain
(`example.com`, `example.org`, `example.net`, `example.test`), and the fixture command refuses to place
an order with any other address. Nothing else is ever removed. That rule is what makes the suite safe
against a database copied from production.

Nothing may leave the installation while the fixture module is enabled: it replaces the mail transport
for the whole application, not only for this module's mail. That is a safety property here and a
catastrophe anywhere else, so **never enable the fixture module on a production installation.** Both
fixture commands refuse to run outside developer mode as a second line of defence.

No real personal data belongs in a case file. Emails use reserved domains, names are obvious
placeholders, and bank details and references are invented.
