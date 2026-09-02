# End-to-end suite

Proves the reminder end to end against a real Magento installation: real orders, the real selection
run, the real templates. It never sends mail — a fixture module binds a transport that writes every
message to `var/tmp/e2e/mails/` instead.

Each case is a JSON file. The runner writes the case's configuration, places the fixture orders it
asks for, runs the send command, and asserts on the reminder rows, the number of captured messages,
the decoded mail body and the command's own exit status.

## Requirements

- Magento in **developer mode**. Both fixture commands and the collecting transport refuse to run in
  any other mode, because they write and delete orders in whatever database they find.
- `jq` on the machine that runs the script — the runner reads every case field with it.
- `perl` with the core `MIME::QuotedPrint` module. Magento encodes the HTML part as quoted-printable,
  so a URL or a date can be soft-wrapped across two lines; every body assertion runs on the decoded
  text.
- At least one enabled simple product that is in stock, has no required options, and is salable in the
  store the orders are placed in. The fixture picks the first such product itself, so no SKU is fixed.
- A shipping method that quotes for the fixture address. The address takes the store's own
  `general/country/default` and the postcode of the `--postcode` option (default `1010`); pass another
  one when that combination is not deliverable. The fixture takes `flatrate_flatrate` when it is
  offered and the first offered rate otherwise, and fails the case when nothing is offered.
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

These three commands are **the operator's, on purpose**. The runner never copies into `app/code` and
never runs `setup:upgrade`: both rewrite the installation's own configuration files —
`app/etc/config.php` records the enabled modules, and `setup:upgrade` regenerates far more than this
module — and a test runner has no business editing those behind the operator's back. Deciding to
enable a module that intercepts the whole application's mail is a deliberate act, so it stays one.

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

## Before anything runs

The runner checks two things once, before it reads or writes a single value, and exits `2` when
either fails:

- **The fixture module is enabled.** It is what binds the collecting transport. Without it the first
  send goes out through the installation's real transport, to whatever address the fixture order
  carries.
- **The installation is in developer mode.** Both fixture commands and the transport refuse anything
  else, so a run would fail case by case instead of saying why once.

Then, for every single case, after its configuration is written and **before the first fixture order
exists**, the runner asks the send command for a dry run. A case may only continue when that answers
`No order qualifies for a reminder.` The rule map of a case names a real payment method, so on another
database the same rule can select real unpaid orders of that method; an empty selection at that
moment is the proof that every order the case then sends to is one the case itself created. A case
that fails this check is reported and skipped, and nothing is placed or sent.

## Configuration

The runner records `general/enabled`, `general/max_age_days` and `rules/methods` before the first case
and restores all three after the last one. Every case then overwrites them with its own values, and
the runner reads all three back after writing them: a value that did not take fails the case before a
single order is placed.

`config:show` prints an empty string for a value that exists only in `config.xml`, so an empty read is
recorded as "no explicit value" rather than as the empty string. Restoring such a value writes the
module's own default from `etc/config.xml` (`general/enabled` `0`, `general/max_age_days` `30`,
`rules/methods` `{}`) and says so on stdout — writing the empty string back would turn an inherited
value into an explicit one that then shadows any future default.

With `--keep`, the configuration left behind is the **last case's**, not the shop's. That means the
reminder is left *enabled*, with that case's rule: an installation whose cron is running will then
remind real orders that use the method the rule names. The runner prints the recorded values so they
can be restored by hand — do that before the next cron tick, or do not use `--keep` on an installation
with a live cron.

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

Every field the runner reads is validated before the case touches the installation, so a missing one
is a reported failure and not a `null` written into the configuration.

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

Two things about time and a body assertion:

- **Dates in the mail are formatted for the store's locale**, and the locale is the installation's, not
  the suite's. A case therefore asserts the **year** of a payment deadline and never a full formatted
  date, which would only hold for one locale.
- **`age_days` is coarse on purpose.** The fixture writes `created_at` in UTC, while the selection
  compares it against the database's own clock, and the two are not the same on an installation whose
  MySQL runs in local time. Ages a whole day either side of a delay keep every case clear of that
  offset; an age chosen to land exactly on the boundary would pass or fail with the server's timezone.

## Adding a case

Drop a JSON file into `cases/`. The runner picks it up in file name order. Read an existing case for
the schema, and keep every value in it invented — see Safety.

## Coverage

The numbered cases come from the original test plan for this module. The suite carries the ones a
script can prove on any installation; the rest stay manual, each for a reason.

| Case | File | Notes |
|---|---|---|
| 1 — below the delay | `cases/01-below-the-delay.json` | |
| 2 — one day past the delay | `cases/02-one-day-past-the-delay.json` | |
| 3 — rule for an unused method | `cases/03-rule-for-unused-method.json` | |
| 4 — cancellation | — | The cancelled-order path is covered by the unit suite, and cancelling through the fixture would test Magento's cancel flow rather than the reminder. |
| 5 — expired payment window | `cases/05-expired-payment-window.json` | |
| 6 — offline method in state new | `cases/06-offline-method-state-new.json` | Runs the module's real offline provider. |
| 7 — wrong rule key | `cases/07-wrong-rule-key.json` | |
| 8 — maximum age | `cases/08a-max-age-unbounded.json`, `cases/08b-max-age-thirty-days.json` | The same old order, with the bound off and on. |
| 9 to 11 — three store views | — | Needs three configured store views, which no installation is guaranteed to have. |
| 12 — deadline and bank block | `cases/12-deadline-and-bank-block.json` | |
| 13 and 14 — rendering variants | — | The guest half is folded into case 12, which asserts the same rendering; the registered-customer half is not covered, because the fixture places guest orders only. |
| 15 — free-text instructions | `cases/15-free-text-instructions.json` | |
| 16 — no unrendered directives | `cases/16-no-unrendered-directives.json` | |
| 17 — one mail per order ever | `cases/17-one-mail-per-order-ever.json` | |
| 18 — gateway failure | `cases/18-gateway-failure-writes-nothing.json` | |
| 19 — blind-copy header | — | Needs `general/bcc` set, and the runner neither records nor restores that setting. |
| 20 — efficacy | — | Needs an order to actually be paid, which no script can do without a gateway. |

## Safety

The reset command deletes an order only when its customer email ends in a reserved domain
(`example.com`, `example.org`, `example.net`, `example.test`) **and** its state is `new` or
`pending_payment`, which are the only states the suite ever produces. It deletes the quotes behind
those orders on the same email rule. Nothing else is ever removed. That rule is what makes the suite
safe against a database copied from production.

Nothing may leave the installation while the fixture module is enabled: it replaces the mail transport
for the whole application, not only for this module's mail. That is a safety property here and a
catastrophe anywhere else, so **never enable the fixture module on a production installation.** Both
fixture commands and the transport itself refuse to run outside developer mode as a second line of
defence.

Two more things a run touches that are easy to miss:

- **Placing an order fires every order-placement observer the installation has** — order exports,
  webhooks, analytics, marketing-platform syncs, external fulfilment. The fixture places real orders
  through the real placement path, so all of it runs. Run the suite only where those integrations are
  switched off or pointed at a sink.
- **The reset deletes orders instead of cancelling them.** On an installation with Multi-Source
  Inventory that leaves the stock reservations behind. Check with
  `bin/magento inventory:reservation:list-inconsistencies` after a run, and clear what it reports.

No real personal data belongs in a case file. Emails use reserved domains, names are obvious
placeholders, and bank details and references are invented.
