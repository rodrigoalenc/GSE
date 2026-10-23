#!/usr/bin/env bash
set -euo pipefail

# Private CI library: do not replace the runner's system SQLite or application data.
gse_build_root="${RUNNER_TEMP:?RUNNER_TEMP is required}/gse-sqlite-build"
gse_sqlite_prefix="${RUNNER_TEMP}/gse-sqlite"
mkdir -p "$gse_build_root"
cd "$gse_build_root"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
  https://www.sqlite.org/2026/sqlite-autoconf-3530400.tar.gz --output sqlite.tar.gz
printf '%s  sqlite.tar.gz\n' 0e9483900e92cd5de8fd48d16bf9200145a61f7fd5be542a5ac81d8a9516eb9c | sha256sum --check --strict
tar -xzf sqlite.tar.gz
cd sqlite-autoconf-3530400
./configure --prefix="$gse_sqlite_prefix" --disable-static
make -j2
make install
printf 'LD_LIBRARY_PATH=%s/lib%s\n' "$gse_sqlite_prefix" "${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}" >> "${GITHUB_ENV:?GITHUB_ENV is required}"
