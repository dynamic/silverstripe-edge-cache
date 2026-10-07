#!/usr/bin/env bash
# Run the module's PHPUnit suite inside the DDEV dev harness (.dev/, an SS5 project
# that installs this module through a path repository).
# Usage: scripts/test.sh [phpunit args]   e.g. scripts/test.sh --filter RobotsTest
# After adding or moving classes run: scripts/test.sh --flush   (clears the harness's
# SilverStripe manifest cache first, then runs the suite; PHPUnit itself rejects a
# bare flush argument, so the cache is removed instead).
set -euo pipefail
cd "$(dirname "$0")/.."
args=()
for a in "$@"; do
  if [[ "$a" == "--flush" ]]; then
    ddev exec 'rm -rf /tmp/silverstripe-cache-*-.dev'
  else
    args+=("$a")
  fi
done
ddev exec "cd .dev && vendor/bin/phpunit ${args[*]:-}"
