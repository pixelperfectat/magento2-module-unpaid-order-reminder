#!/usr/bin/env bash
# End-to-end suite for the unpaid order reminder.
#
# It never sends mail: the fixture module binds a transport that writes every message to a file.
# It never deletes an order whose email is not on a reserved domain.
# It never runs a case whose configuration did not apply: the shop's own rule would then still be in
# force, and the case would place orders and send against whatever real orders that rule selects.
#
# Requires jq, and perl with the core MIME::QuotedPrint module for decoding the captured mail.
#
# Environment:
#   MAGENTO_ROOT   directory the commands run from (default: the current directory)
#   MAGENTO_CLI    the Magento console, relative to MAGENTO_ROOT (default: bin/magento)
#   MAGENTO_SHELL  how to run a shell string in the Magento root (default: sh -c)
#   E2E_STORE      store id for cases that do not name one (default: the default store view)
# A containerised installation points MAGENTO_CLI and MAGENTO_SHELL at its exec wrapper, for
# example MAGENTO_CLI="bin/magento" and MAGENTO_SHELL="bin/cli sh -c", because the Magento var/
# directory then exists only inside the container.
#
# Usage: dev/tests/e2e/run.sh [--keep]
# --keep leaves the fixture orders, the captured mail and the last case's configuration in place.
#
# Case fields:
#   name           what the case proves, printed with its result
#   config         enabled, max_age_days and rules, written before the case runs
#   scenario       the instructions scenario the fixture provider answers with
#   orders         the fixture orders to place: method, age_days, count, and an optional store
#   runs           how many times to run the send command (default: 1)
#   expect         reminder_rows, mails, and the optional body_contains / body_not_contains lists,
#                  plus exit_status (optional, default 0): the status the send command itself must
#                  return. It exits non-zero whenever it skipped an order, which a case may assert.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/assert.sh
source "$HERE/lib/assert.sh"

MAGENTO_ROOT="${MAGENTO_ROOT:-$(pwd)}"
MAGENTO_CLI="${MAGENTO_CLI:-bin/magento}"
MAGENTO_SHELL="${MAGENTO_SHELL:-sh -c}"
E2E_STORE="${E2E_STORE:-}"
KEEP="${KEEP:-0}"
[ "${1:-}" = "--keep" ] && KEEP=1

E2E_DIR="var/tmp/e2e"
CFG_PREFIX="pixelperfect_unpaid_order_reminder"
CFG_PATHS=("general/enabled" "general/max_age_days" "rules/methods")
# The defaults in etc/config.xml, in the order of CFG_PATHS.
CFG_DEFAULTS=("0" "30" "{}")
# config:show prints an empty string for a value that lives only in config.xml, and writing that
# empty string back would turn an inherited value into an explicit one.
CFG_UNSET="__unset__"
SAVED_CONFIG=()

# MAGENTO_CLI and MAGENTO_SHELL carry their own arguments, so both stay unquoted on purpose.
# shellcheck disable=SC2086
magento() { ( cd "$MAGENTO_ROOT" && $MAGENTO_CLI "$@" ); }
# shellcheck disable=SC2086
mshell() { ( cd "$MAGENTO_ROOT" && $MAGENTO_SHELL "$1" ); }

require_tools() {
    command -v jq >/dev/null 2>&1 || { echo "jq is required"; exit 2; }
    command -v perl >/dev/null 2>&1 || { echo "perl is required"; exit 2; }
}

# Both conditions are what keeps the run inside this installation: the fixture module binds the
# transport that captures the mail, and every fixture command refuses to run outside developer mode.
# Without the module the first send would reach real recipients, so this is checked before anything
# else touches the installation.
preflight() {
    local output
    output="$(magento module:status PixelPerfect_UnpaidOrderReminderE2e 2>&1)"
    case "$output" in
        *disabled*)
            echo "The fixture module PixelPerfect_UnpaidOrderReminderE2e is disabled; enable it first."
            exit 2
            ;;
    esac
    case "$output" in
        *enabled*) ;;
        *)
            echo "The fixture module PixelPerfect_UnpaidOrderReminderE2e is not installed."
            exit 2
            ;;
    esac

    output="$(magento deploy:mode:show 2>&1)"
    case "$output" in
        *developer*) ;;
        *)
            echo "The installation is not in developer mode; the fixture commands refuse to run."
            exit 2
            ;;
    esac
}

# Runs a Magento command and records a case failure when it fails, so the operator sees the real
# cause instead of an assertion about a count that was never going to be right.
run_command() {
    local label="$1"
    shift
    local output status
    output="$(magento "$@" 2>&1)"
    status=$?
    if [ "$status" -ne 0 ]; then
        add_failure "$label: exit $status: $(printf '%s\n' "$output" | head -1)"
    fi
    return "$status"
}

# The send command exits non-zero whenever it skipped an order, and a case may assert exactly that,
# so its status is compared with the expectation rather than required to be zero.
run_send_reminders() {
    local expected="$1"
    local output status
    output="$(magento pixelperfect:unpaidorder:send-reminders 2>&1)"
    status=$?
    if [ "$status" -ne "$expected" ]; then
        add_failure "send-reminders: expected exit $expected, got $status: $(printf '%s\n' "$output" | head -1)"
    fi
}

record_config() {
    local path value status
    for path in "${CFG_PATHS[@]}"; do
        value="$(magento config:show "$CFG_PREFIX/$path" 2>/dev/null | head -1)"
        status=$?
        if [ "$status" -ne 0 ] || [ -z "$value" ]; then
            value="$CFG_UNSET"
        fi
        SAVED_CONFIG+=("$value")
    done
}

restore_config() {
    local index path value failed=0
    for index in "${!CFG_PATHS[@]}"; do
        path="${CFG_PATHS[$index]}"
        value="${SAVED_CONFIG[$index]}"
        if [ "$value" = "$CFG_UNSET" ]; then
            value="${CFG_DEFAULTS[$index]}"
            printf 'Restored %s/%s to the module default %s; the installation had no explicit value.\n' \
                "$CFG_PREFIX" "$path" "$value"
        fi
        if ! run_command "config:set $path" config:set "$CFG_PREFIX/$path" "$value"; then
            printf 'Restore failed for %s/%s; set it yourself:\n' "$CFG_PREFIX" "$path" >&2
            print_saved_config >&2
            failed=1
        fi
    done
    run_command "cache:flush" cache:flush || failed=1

    return "$failed"
}

print_saved_config() {
    local index value
    for index in "${!CFG_PATHS[@]}"; do
        value="${SAVED_CONFIG[$index]}"
        [ "$value" = "$CFG_UNSET" ] && value="(module default)"
        printf '  %s/%s = %s\n' "$CFG_PREFIX" "${CFG_PATHS[$index]}" "$value"
    done
}

# config:set can refuse the whole shop at once - it is disabled outright while app/etc/config.php is
# out of step with the imported snapshot - and it says so on stdout with nothing on stderr. Discarding
# that left every case running against the shop's own rule and its real orders, so each write is
# checked and the rule is read back before the case is allowed to proceed.
apply_config() {
    local case_file="$1"
    local index path actual failed=0
    local -a wanted

    # Same order as CFG_PATHS.
    wanted=(
        "$(jq -r '.config.enabled' "$case_file")"
        "$(jq -r '.config.max_age_days' "$case_file")"
        "$(jq -c '.config.rules' "$case_file")"
    )

    for index in "${!CFG_PATHS[@]}"; do
        run_command "config:set ${CFG_PATHS[$index]}" config:set \
            "$CFG_PREFIX/${CFG_PATHS[$index]}" "${wanted[$index]}" || failed=1
    done
    run_command "cache:flush" cache:flush || failed=1

    for index in "${!CFG_PATHS[@]}"; do
        path="${CFG_PATHS[$index]}"
        actual="$(magento config:show "$CFG_PREFIX/$path" 2>/dev/null | head -1)"
        if [ "$actual" != "${wanted[$index]}" ]; then
            add_failure "config: $path is '$actual', expected '${wanted[$index]}'"
            failed=1
        fi
    done

    return "$failed"
}

# The rule map of a case names a real payment method, so on another database copy the same rule can
# select real unpaid orders of that method. The dry run decides everything and sends nothing, so it
# is safe to ask before a single fixture order exists: an empty selection at that moment proves
# every order the case then sends to is one the case itself created.
assert_no_real_order_selected() {
    local output status
    output="$(magento pixelperfect:unpaidorder:send-reminders --dry-run 2>&1)"
    status=$?
    if [ "$status" -eq 0 ]; then
        case "$output" in
            *"No order qualifies for a reminder."*) return 0 ;;
        esac
    fi

    add_failure "config: the case rule selects orders the suite did not create; refusing to run"
    return 1
}

write_scenario() {
    jq -c '.scenario' "$1" | mshell "mkdir -p $E2E_DIR && cat > $E2E_DIR/scenario.json"
}

place_orders() {
    local case_file="$1" line store failed=0
    local -a options
    while read -r line; do
        [ -z "$line" ] && continue
        store="$(printf '%s' "$line" | jq -r '.store // empty')"
        [ -z "$store" ] && store="$E2E_STORE"
        options=(
            "--count=$(printf '%s' "$line" | jq -r '.count')"
            "--method=$(printf '%s' "$line" | jq -r '.method')"
            "--age-days=$(printf '%s' "$line" | jq -r '.age_days')"
        )
        [ -n "$store" ] && options+=("--store=$store")
        run_command "e2e-create-orders" \
            pixelperfect:unpaidorder:e2e-create-orders "${options[@]}" || failed=1
    done < <(jq -c '.orders[]' "$case_file")

    return "$failed"
}

# The stats command prints nothing countable until the first reminder exists, which reads as zero.
count_reminder_rows() {
    local count
    count="$(magento pixelperfect:unpaidorder:reminder-stats 2>/dev/null \
        | grep -oE 'Reminded[^0-9]*[0-9]+' | grep -oE '[0-9]+' | head -1)"
    printf '%s' "${count:-0}"
}

# Magento encodes the HTML part as quoted-printable, so a URL or a date can be soft-wrapped across
# two lines. Every body assertion runs on the decoded text instead.
collected_mail() {
    mshell "cat $E2E_DIR/mails/*.eml 2>/dev/null" | perl -MMIME::QuotedPrint -pe '$_ = decode_qp($_)'
}

count_mail() { mshell "ls $E2E_DIR/mails/*.eml 2>/dev/null | wc -l" | tr -d ' '; }

run_case() {
    local case_file="$1"
    local name runs i before after exit_status
    name="$(jq -r '.name' "$case_file")"
    runs="$(jq -r '.runs // 1' "$case_file")"
    exit_status="$(jq -r '.expect.exit_status // 0' "$case_file")"

    reset_failures
    # A case that starts on the previous case's orders and mail proves nothing, so a failed reset
    # ends it here rather than at an assertion about a count that was never going to be right.
    if ! run_command "e2e-reset" pixelperfect:unpaidorder:e2e-reset; then
        report_failures "$name"
        return 1
    fi
    write_scenario "$case_file"
    # Placing orders or sending now would run the case against whatever the shop's own rule selects.
    if ! apply_config "$case_file"; then
        report_failures "$name"
        return 1
    fi
    if ! assert_no_real_order_selected; then
        report_failures "$name"
        return 1
    fi
    # A case that placed fewer orders than it asked for would send against an unknown set.
    if ! place_orders "$case_file"; then
        report_failures "$name"
        return 1
    fi

    # Placing an order sends the order confirmation through the same collecting transport, and the
    # suite counts reminder mail only. This is the only file the runner removes and it is the
    # suite's own.
    mshell "rm -f $E2E_DIR/mails/*.eml"

    # The database may already hold reminders for orders the suite never created, so the
    # expectation is a difference and not an absolute count.
    before="$(count_reminder_rows)"
    for ((i = 0; i < runs; i++)); do
        run_send_reminders "$exit_status"
    done
    after="$(count_reminder_rows)"

    assert_equals "reminder rows" "$(jq -r '.expect.reminder_rows' "$case_file")" "$((after - before))"
    assert_equals "mails" "$(jq -r '.expect.mails' "$case_file")" "$(count_mail)"

    local body needle
    body="$(collected_mail)"
    while read -r needle; do
        [ -z "$needle" ] && continue
        assert_contains "body" "$needle" "$body"
    done < <(jq -r '.expect.body_contains // [] | .[]' "$case_file")
    while read -r needle; do
        [ -z "$needle" ] && continue
        assert_not_contains "body" "$needle" "$body"
    done < <(jq -r '.expect.body_not_contains // [] | .[]' "$case_file")

    report_failures "$name"
}

teardown() {
    local output status restore_status
    output="$(magento pixelperfect:unpaidorder:e2e-reset 2>&1)"
    status=$?
    restore_config
    restore_status=$?
    if [ "$status" -ne 0 ]; then
        printf 'Teardown failed: e2e-reset: exit %s: %s\n' \
            "$status" "$(printf '%s\n' "$output" | head -1)" >&2
        printf 'Fixture orders and captured mail may still be present.\n' >&2
        return 1
    fi

    return "$restore_status"
}

main() {
    require_tools
    local failed=0 case_file
    printf 'Unpaid order reminder end-to-end suite\n\n'
    preflight
    record_config
    for case_file in "$HERE"/cases/*.json; do
        run_case "$case_file" || failed=1
    done
    if [ "$KEEP" = "1" ]; then
        printf '\nState kept. Run the reset command yourself when you are done.\n'
        printf 'The configuration is the last case, not the shop. Restore it with:\n'
        print_saved_config
    else
        teardown || failed=1
    fi
    printf '\n'
    [ "$failed" = "0" ] && printf 'All cases passed.\n' || printf 'Some cases failed.\n'
    return "$failed"
}

main "$@"
