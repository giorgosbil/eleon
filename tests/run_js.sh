#!/bin/bash
# The screen-code tests (tests/*_test.cjs, in node:vm) and the browser tests (tests/*_browser.cjs,
# Playwright with a fake server: no app, data or network needed). On the PC, Git Bash:
#   bash tests/run_js.sh            both
#   bash tests/run_js.sh vm         only the screen-code tests (no browser)
# The browser tests need Playwright once: npm install --prefix tests/browser (they use
# Microsoft Edge when Playwright's own Chromium is not installed). A test fails on a nonzero
# exit; a browser test that cannot find Playwright (exit 2) is reported as SKIPPED, never ok.
cd "$(dirname "$0")/.." || exit 1
which="${1:-all}"
failed=0; skipped=0
run() {
  out=$(timeout 300 node "$1" 2>&1); code=$?
  if [ $code -eq 0 ]; then echo "ok      $1  $(printf '%s\n' "$out" | grep -c '^PASS') passed"
  elif [ $code -eq 2 ] && printf '%s' "$out" | grep -q '^SKIP'; then skipped=$((skipped + 1)); echo "SKIPPED $1  $(printf '%s\n' "$out" | grep '^SKIP' | head -n 1)"
  else failed=$((failed + 1)); echo "FAILED  $1 (exit $code)"; printf '%s\n' "$out" | grep -v '^\s*at ' | grep -v '^$' | tail -n 8 | sed 's/^/        /'
  fi
}
for t in tests/*_test.cjs; do run "$t"; done
if [ "$which" != "vm" ]; then
  for t in tests/*_browser.cjs; do run "$t"; done
fi
echo
[ $skipped -gt 0 ] && echo "$skipped skipped (Playwright not installed: npm install --prefix tests/browser)."
if [ $failed -eq 0 ]; then echo "All screen tests passed."; else echo "$failed screen test file(s) failed."; fi
[ $failed -eq 0 ]
