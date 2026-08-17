#!/usr/bin/env sh
#
# Runs every test in this directory and reports one line each.
#
# Same convention and same runner shape as fogproject's tests/run-all.sh:
# standalone scripts, exit 0 for pass and non-zero for fail, no framework and
# no database. Kept as a separate copy rather than shared because this
# repository is fetched on its own (bin/fetch-plugins.sh) and cannot assume a
# fogproject checkout is anywhere nearby.
#
# There is no *.test.sh arm here yet; add one from fogproject's copy if a
# shell test ever lands.
#
# Usage: sh tests/run-all.sh
# Exit status 0 = every test passed, 1 = at least one failed.

testdir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

pass=0
fail=0
failed=''

for t in "$testdir"/*.test.php; do
    [ -f "$t" ] || continue
    name=$(basename "$t")

    # Captured rather than streamed, so a passing test contributes one line
    # and a failing one gets its output replayed in full, which is when it is
    # actually wanted.
    out=$(php "$t" 2>&1)
    status=$?

    if [ $status -eq 0 ]; then
        pass=$((pass + 1))
        printf 'ok    %s\n' "$name"
    else
        fail=$((fail + 1))
        failed="$failed $name"
        printf 'FAIL  %s (exit %d)\n' "$name" "$status"
        printf '%s\n' "$out" | sed 's/^/      /'
    fi
done

printf '\n%d passed, %d failed\n' "$pass" "$fail"

if [ $fail -gt 0 ]; then
    printf 'failed:%s\n' "$failed" >&2
    exit 1
fi

exit 0
