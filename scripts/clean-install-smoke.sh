#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
server_pid=""
sdk_version="${SDK_VERSION:-2.1.0}"

cleanup() {
	if [[ -n "$server_pid" ]]; then
		kill "$server_pid" 2>/dev/null || true
		wait "$server_pid" 2>/dev/null || true
	fi
	rm -rf "$tmp_dir"
}
trap cleanup EXIT

mkdir -p "$tmp_dir/artifacts" "$tmp_dir/consumer"
COMPOSER_ROOT_VERSION="$sdk_version" composer archive \
	--working-dir="$root_dir" \
	--format=zip \
	--dir="$tmp_dir/artifacts" \
	--file=oilpriceapi \
	--no-interaction \
	--quiet
archive_path="$tmp_dir/artifacts/oilpriceapi.zip"
[[ -s "$archive_path" ]] || { echo "Composer archive was not created" >&2; exit 1; }

package_json="$(php -r '
	$package = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
	$package["version"] = $argv[2];
	$package["dist"] = ["url" => "file://" . $argv[3], "type" => "zip"];
	unset($package["require-dev"], $package["autoload-dev"], $package["scripts"]);
	echo json_encode(["type" => "package", "package" => $package], JSON_THROW_ON_ERROR);
' "$root_dir/composer.json" "$sdk_version" "$archive_path")"

pushd "$tmp_dir/consumer" >/dev/null
export COMPOSER_ROOT_VERSION=1.0.0
composer init --name=oilpriceapi/example-smoke --no-interaction --quiet
composer config --quiet repositories.oilpriceapi "$package_json"
composer require "oilpriceapi/oilpriceapi:$sdk_version" --no-interaction --prefer-dist --no-progress --quiet
quickstart="$tmp_dir/consumer/vendor/oilpriceapi/oilpriceapi/examples/quickstart.php"
[[ -f "$quickstart" ]] || { echo "packaged quickstart is missing" >&2; exit 1; }

port="$(php -r 'echo random_int(20000, 45000);')"
base_url="http://127.0.0.1:$port"
php -S "127.0.0.1:$port" "$root_dir/tests/fixtures/router.php" >"$tmp_dir/server.log" 2>&1 &
server_pid=$!
for _ in {1..50}; do
	if curl -sS "$base_url/v1/prices/latest?by_code=BRENT_CRUDE_USD" >/dev/null 2>&1; then
		break
	fi
	sleep 0.1
done
kill -0 "$server_pid" 2>/dev/null || { echo "fixture server did not start" >&2; exit 1; }

OILPRICEAPI_BASE_URL="$base_url" OILPRICEAPI_KEY="valid-smoke-key" php "$quickstart" >"$tmp_dir/success.out" 2>&1
grep -q '^BRENT_CRUDE_USD 71.80 USD/barrel as of 2026-07-19T12:00:00+00:00 (source: market_reporting)$' "$tmp_dir/success.out"

set +e
env -u OILPRICEAPI_KEY OILPRICEAPI_BASE_URL="$base_url" php "$quickstart" >"$tmp_dir/missing.out" 2>&1
missing_status=$?
OILPRICEAPI_BASE_URL="$base_url" OILPRICEAPI_KEY="invalid-smoke-key" php "$quickstart" >"$tmp_dir/auth.out" 2>&1
auth_status=$?
OILPRICEAPI_BASE_URL="$base_url" OILPRICEAPI_KEY="locked-smoke-key" php "$quickstart" >"$tmp_dir/locked.out" 2>&1
locked_status=$?
OILPRICEAPI_BASE_URL="$base_url" OILPRICEAPI_KEY="limited-smoke-key" php "$quickstart" >"$tmp_dir/limited.out" 2>&1
limited_status=$?
set -e

[[ "$missing_status" -ne 0 && "$auth_status" -ne 0 && "$locked_status" -ne 0 && "$limited_status" -ne 0 ]]
grep -q 'OILPRICEAPI_KEY is required' "$tmp_dir/missing.out"
grep -q 'Authentication failed; replace OILPRICEAPI_KEY' "$tmp_dir/auth.out"
grep -q 'cannot access the requested dataset' "$tmp_dir/locked.out"
grep -q 'Retry after 3 seconds' "$tmp_dir/limited.out"

if grep -E 'valid-smoke-key|invalid-smoke-key|locked-smoke-key|limited-smoke-key' "$tmp_dir"/*.out; then
	echo "quickstart output exposed a credential" >&2
	exit 1
fi
popd >/dev/null

echo "packaged Composer smoke passed (success, missing config, 401, 403, 429)"
