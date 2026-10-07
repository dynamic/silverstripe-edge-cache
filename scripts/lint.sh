#!/usr/bin/env bash
# PHPCS (PSR-12) and PHPStan over src/ and tests/, using the dev harness tooling.
set -euo pipefail
cd "$(dirname "$0")/.."
ddev exec '.dev/vendor/bin/phpcs --standard=phpcs.xml.dist'
ddev exec '.dev/vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress' || true
