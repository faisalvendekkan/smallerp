#!/usr/bin/env sh
#
# Start SmallERP locally.
#
#   ./start.sh            use the first free port from 8000
#   ./start.sh 9000       use a particular port
#
# Checks the things that actually go wrong on a first run -- PHP missing, PHP
# too old, wrong directory, port already busy -- and says which one it is.

set -eu

cd "$(dirname "$0")"

# --- Is PHP there at all? ----------------------------------------------

if ! command -v php > /dev/null 2>&1; then
    cat <<'MSG'
PHP is not installed, or is not on your PATH.

  macOS    brew install php
  Ubuntu   sudo apt install php-cli php-sqlite3 php-mbstring
  Windows  https://windows.php.net/download  (add the folder to PATH)

SmallERP needs PHP 8.1 or newer. Nothing else -- no Composer, no build step.
MSG
    exit 1
fi

# --- Is it new enough, with the extensions we need? --------------------

php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || {
    echo "PHP $(php -r 'echo PHP_VERSION;') is too old -- SmallERP needs 8.1 or newer."
    exit 1
}

for ext in pdo_sqlite mbstring; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || {
        echo "The PHP extension '$ext' is not enabled."
        echo "On Ubuntu: sudo apt install php-sqlite3 php-mbstring"
        echo "On Windows: uncomment extension=$ext in php.ini"
        exit 1
    }
done

# --- Are we in the right place? ----------------------------------------

if [ ! -f public/index.php ]; then
    echo "Run this from the SmallERP folder (the one holding README.md)."
    exit 1
fi

# --- Find a port ------------------------------------------------------

port="${1:-}"
if [ -z "$port" ]; then
    port=8000
    while [ "$port" -lt 8020 ]; do
        php -r "exit(@fsockopen('127.0.0.1', $port, \$e, \$s, 0.4) ? 1 : 0);" && break
        port=$((port + 1))
    done
fi

php -r "exit(@fsockopen('127.0.0.1', $port, \$e, \$s, 0.4) ? 1 : 0);" || {
    echo "Port $port is already in use. Try: ./start.sh 9000"
    exit 1
}

mkdir -p storage/logs storage/exports

printf '\n  SmallERP is running\n\n'
printf '    http://localhost:%s\n\n' "$port"
printf '  First run? The installer will ask you to create an administrator.\n'
printf '  Tick "load demo data" to explore a sample Doha trading company.\n'
printf '  Leave it unticked if these are going to be your real books.\n\n'
printf '  Press Ctrl+C to stop.\n\n'

exec php -S "localhost:$port" -t public
