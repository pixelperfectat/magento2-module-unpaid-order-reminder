#!/usr/bin/env bash
# Assertion helpers for the unpaid order reminder end-to-end suite.

FAILURES=()

assert_equals() {
    local label="$1" expected="$2" actual="$3"
    if [ "$expected" != "$actual" ]; then
        FAILURES+=("$label: expected '$expected', got '$actual'")
    fi
}

assert_contains() {
    local label="$1" needle="$2" haystack="$3"
    case "$haystack" in
        *"$needle"*) ;;
        *) FAILURES+=("$label: '$needle' is absent") ;;
    esac
}

assert_not_contains() {
    local label="$1" needle="$2" haystack="$3"
    case "$haystack" in
        *"$needle"*) FAILURES+=("$label: '$needle' is present and must not be") ;;
    esac
}

add_failure() { FAILURES+=("$1"); }

reset_failures() { FAILURES=(); }

report_failures() {
    local name="$1"
    if [ ${#FAILURES[@]} -eq 0 ]; then
        printf '  PASS  %s\n' "$name"
        return 0
    fi
    printf '  FAIL  %s\n' "$name"
    local failure
    for failure in "${FAILURES[@]}"; do
        printf '          %s\n' "$failure"
    done
    return 1
}
