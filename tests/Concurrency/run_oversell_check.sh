#!/usr/bin/env bash
#
# Proves two tills cannot both sell the last unit.
#
# PHPUnit cannot express this: RefreshDatabase wraps each test in a transaction, so
# a second process never sees the seeded row to contend for it. This seeds for real,
# then races two separate OS processes into the checkout at the same instant.
#
# Expected: one 200, one refusal, exactly one sale, and stock floored at zero.
#
# usage: tests/Concurrency/run_oversell_check.sh [test-database]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DB="${1:-post_manage_testing}"

if [[ "$DB" != *test* ]]; then
  echo "Refusing to run against '$DB': the seeder truncates sales. Use a test database." >&2
  exit 1
fi

export DB_DATABASE="$DB"
export APP_ENV=testing

echo "Seeding a single sellable unit into $DB ..."
SEED="$(php "$ROOT/tests/Concurrency/seed_last_unit.php" | tail -1)"

USER_ID="$(echo "$SEED" | python3 -c 'import json,sys; print(json.load(sys.stdin)["user_id"])')"
STOCK_ID="$(echo "$SEED" | python3 -c 'import json,sys; print(json.load(sys.stdin)["stock_id"])')"

# A shared instant a few seconds out, so both processes are already running and
# spinning when they enter the checkout.
START="$(python3 -c 'import time; print(time.time() + 3)')"

echo "Racing two tills for stock $STOCK_ID ..."
php "$ROOT/tests/Concurrency/sell_one_unit.php" "$USER_ID" "$STOCK_ID" "$START" &
PID_A=$!
php "$ROOT/tests/Concurrency/sell_one_unit.php" "$USER_ID" "$STOCK_ID" "$START" &
PID_B=$!

wait $PID_A $PID_B

echo
echo "Resulting state:"
mysql -h127.0.0.1 -uroot -ppassword -N -e "
  select 'sales_created', count(*) from sales
  union all select 'min_stock', min(productStock) from stocks
  union all select 'min_batch_qty', min(conversionQuantity) from purchase_details;" "$DB"

echo
echo "Pass condition: sales_created = 1, and neither minimum is negative."
