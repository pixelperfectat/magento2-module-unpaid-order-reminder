#!/usr/bin/env bash
# End-to-end suite for the unpaid order reminder.
#
# It never sends mail: the fixture module binds a transport that writes every message to a file.
# It never deletes an order whose email is not on a reserved domain.
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
SAVED_CONFIG=()

# MAGENTO_CLI and MAGENTO_SHELL carry their own arguments, so both stay unquoted on purpose.
# shellcheck disable=SC2086
magento() { ( cd "$MAGENTO_ROOT" && $MAGENTO_CLI "$@" ); }
# shellcheck disable=SC2086
mshell() { ( cd "$MAGENTO_ROOT" && $MAGENTO_SHELL "$1" ); }

require_jq() {
    command -v jq >/dev/null 2>&1 || { echo "jq is required"; exit 2; }
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
    local path value
    for path in "${CFG_PATHS[@]}"; do
        value="$(magento config:show "$CFG_PREFIX/$path" 2>/dev/null | head -1)"
        SAVED_CONFIG+=("$value")
    done
}

restore_config() {
    local index
    for index in "${!CFG_PATHS[@]}"; do
        magento config:set "$CFG_PREFIX/${CFG_PATHS[$index]}" "${SAVED_CONFIG[$index]}" >/dev/null
    done
    magento cache:flush >/dev/null
}

print_saved_config() {
    local index
    for index in "${!CFG_PATHS[@]}"; do
        printf '  %s/%s = %s\n' "$CFG_PREFIX" "${CFG_PATHS[$index]}" "${SAVED_CONFIG[$index]}"
    done
}

apply_config() {
    local case_file="$1"
    magento config:set "$CFG_PREFIX/general/enabled" \
        "$(jq -r '.config.enabled' "$case_file")" >/dev/null
    magento config:set "$CFG_PREFIX/general/max_age_days" \
        "$(jq -r '.config.max_age_days' "$case_file")" >/dev/null
    magento config:set "$CFG_PREFIX/rules/methods" \
        "$(jq -c '.config.rules' "$case_file")" >/dev/null
    magento cache:flush >/dev/null
}

write_scenario() {
    jq -c '.scenario' "$1" | mshell "mkdir -p $E2E_DIR && cat > $E2E_DIR/scenario.json"
}

place_orders() {
    local case_file="$1" line store
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
            pixelperfect:unpaidorder:e2e-create-orders "${options[@]}"
    done < <(jq -c '.orders[]' "$case_file")
}

# The stats command prints nothing countable until the first reminder exists, which reads as zero.
count_reminder_rows() {
    local count
    count="$(magento pixelperfect:unpaidorder:reminder-stats 2>/dev/null \
        | grep -oE 'Reminded[^0-9]*[0-9]+' | grep -oE '[0-9]+' | head -1)"
    printf '%s' "${count:-0}"
}

collected_mail() { mshell "cat $E2E_DIR/mails/*.eml 2>/dev/null"; }

count_mail() { mshell "ls $E2E_DIR/mails/*.eml 2>/dev/null | wc -l" | tr -d ' '; }

run_case() {
    local case_file="$1"
    local name runs i before after exit_status
    name="$(jq -r '.name' "$case_file")"
    runs="$(jq -r '.runs // 1' "$case_file")"
    exit_status="$(jq -r '.expect.exit_status // 0' "$case_file")"

    reset_failures
    run_command "e2e-reset" pixelperfect:unpaidorder:e2e-reset
    write_scenario "$case_file"
    apply_config "$case_file"
    place_orders "$case_file"

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
    local output status
    output="$(magento pixelperfect:unpaidorder:e2e-reset 2>&1)"
    status=$?
    restore_config
    if [ "$status" -ne 0 ]; then
        printf 'Teardown failed: e2e-reset: exit %s: %s\n' \
            "$status" "$(printf '%s\n' "$output" | head -1)" >&2
        printf 'Fixture orders and captured mail may still be present.\n' >&2
        return 1
    fi
    return 0
}

main() {
    require_jq
    local failed=0 case_file
    printf 'Unpaid order reminder end-to-end suite\n\n'
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
