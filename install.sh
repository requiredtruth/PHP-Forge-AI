#!/usr/bin/env sh
set -eu
command -v php >/dev/null 2>&1 || { echo 'ERROR: PHP 8.1+ is required.' >&2; exit 1; }
php -r 'if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "ERROR: PHP 8.1+ is required.\n"); exit(1); }'
php -m | grep -qx curl || { echo 'ERROR: PHP ext-curl is required.' >&2; exit 1; }
php -m | grep -qx json || { echo 'ERROR: PHP ext-json is required.' >&2; exit 1; }
php -l php_forge_ai.php
php tests/smoke.php
python3 -m unittest discover -s tests -p 'static_*.py' -v
printf '%s\n' 'PHP Forge AI verification: PASS'

