#!/bin/sh
# Every PHP test (the core's and each module's), one summary. In the app container:
#   docker compose run --rm --no-deps -T web sh tests/run_all.sh
# A test file counts as failed when it prints FAIL or a fatal error, when PHP exits
# with an error (also a silent one: then its last lines are shown), or when it
# prints no summary at all (it stopped early). Parts a test skipped (a line
# starting SKIP) show next to it and in the total. Browser tests (*.cjs, *.py) need a
# browser and run separately.
cd "$(dirname "$0")/.." || exit 1
total_failed=0
total_skipped=0
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

# run_one <test file> <private mailboxes 0|1> <label suffix>
run_one() {
  # Without this PC's data password (.env, DataPush): the backup tests set their own.
  env -u ECW_BACKUP_PASSWORD ECW_PRIVATE_MAILBOXES="$2" php "$1" >"$tmp" 2>&1
  code=$?   # PHP's own exit status, not a filter's
  out=$(grep -v '^\[ecw\]' "$tmp")
  bad=$(printf '%s\n' "$out" | grep -cE '^\s*FAIL|PHP Fatal|Uncaught ')
  summary=$(printf '%s\n' "$out" | grep -E 'passed|wired|^PASS' | tail -n 1)
  if [ "$bad" -gt 0 ] || [ "$code" -ne 0 ] || [ -z "$summary" ]; then
    total_failed=$((total_failed + 1))
    why=""
    [ "$code" -ne 0 ] && why=" (exit $code)"
    [ -z "$summary" ] && why="$why (no summary: it stopped early)"
    echo "FAILED  $1$3$why"
    if [ "$bad" -gt 0 ]; then
      printf '%s\n' "$out" | grep -E '^\s*FAIL|PHP Fatal|Uncaught ' | head -n 8 | sed 's/^/        /'
    else
      printf '%s\n' "$out" | tail -n 5 | sed 's/^/        /'
    fi
  else
    skips=$(printf '%s\n' "$out" | grep -E '^\s*SKIP' | sed 's/^\s*//' | paste -sd ';' -)
    echo "ok      $1$3  ${summary}${skips:+  [$skips]}"
    [ -n "$skips" ] && total_skipped=$((total_skipped + 1))
  fi
}

for t in tests/*_test.php modules/*/tests/*_test.php; do
  [ -f "$t" ] || continue
  run_one "$t" 0 ""
done
# Who sees what, also as most installations run: mailboxes private to their people
# (ECW_PRIVATE_MAILBOXES=1). Above they run with it off; a gap that shows only with it on
# was missed that way (a test that thought a file in a company-wide case was private).
for t in tests/collaboration_test.php tests/account_scope_test.php tests/security_test.php tests/ai_actions_test.php; do
  run_one "$t" 1 " (private mailboxes)"
done
echo
[ "$total_skipped" -gt 0 ] && echo "$total_skipped test file(s) skipped a part (see [SKIP …] above)."
if [ "$total_failed" -eq 0 ]; then echo "All test files passed."; else echo "$total_failed test file(s) failed."; fi
[ "$total_failed" -eq 0 ]
