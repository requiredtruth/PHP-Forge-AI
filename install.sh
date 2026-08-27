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


# Desktop control-panel dependency. Kept in the project venv.
GUI_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
GUI_VENV="$GUI_ROOT/.venv"
command -v python3 >/dev/null 2>&1 || { echo "python3 is required" >&2; exit 1; }
[ -x "$GUI_VENV/bin/python" ] || python3 -m venv "$GUI_VENV"
"$GUI_VENV/bin/python" -m pip install --disable-pip-version-check --upgrade PySide6
touch "$GUI_VENV/.repo-gui-ready"
