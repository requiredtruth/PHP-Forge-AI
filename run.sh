#!/usr/bin/env sh
set -eu
command -v php >/dev/null 2>&1 || { echo 'ERROR: PHP 8.1+ is required.' >&2; exit 1; }
exec php -S "${PFA_HOST:-127.0.0.1}:${PFA_PORT:-8080}" php_forge_ai.php

