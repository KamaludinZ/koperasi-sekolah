#!/usr/bin/env bash
# Buat paket rilis siap unggah untuk hosting tanpa Composer/Node (shared hosting/cPanel):
#   scripts/build-release.sh            → dist/koperasi-sekolah-<commit>.zip
# Isi paket: kode dari commit HEAD (perubahan yang belum di-commit TIDAK ikut), vendor/ tanpa paket dev,
# dan aset public/build hasil Vite. Tidak berisi .env, tests, maupun berkas Docker.
# Butuh: git, PHP ≥ 8.4 + Composer, Node.js ≥ 20 + npm, zip.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

for bin in git composer npm zip; do
    command -v "$bin" >/dev/null || { echo "Program '$bin' tidak ditemukan." >&2; exit 1; }
done

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Peringatan: ada perubahan yang belum di-commit — paket dibuat dari commit HEAD saja." >&2
fi

VERSION="$(git rev-parse --short HEAD)"
NAME="koperasi-sekolah-${VERSION}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/$NAME"
mkdir -p "$APP" dist

echo "→ Menyalin kode commit ${VERSION}…"
git archive HEAD | tar -x -C "$APP"

cd "$APP"
echo "→ Memasang dependensi PHP (tanpa paket dev)…"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --no-scripts
composer dump-autoload --no-dev --optimize --no-interaction --no-scripts

echo "→ Membangun aset (Vite/Tailwind)…"
npm ci --no-audit --no-fund
npm run build

echo "→ Membersihkan berkas yang tidak dibutuhkan di server…"
rm -rf node_modules tests phpunit.xml public/hot .ngodingpakeai .claude AGENTS.md \
    Dockerfile .dockerignore docker docker-compose*.yml
rm -f bootstrap/cache/*.php

cd "$WORK"
zip -qr -X "$ROOT/dist/${NAME}.zip" "$NAME"
echo "✓ Paket rilis: dist/${NAME}.zip ($(du -h "$ROOT/dist/${NAME}.zip" | cut -f1))"
echo "  Unggah & ekstrak di server, lalu ikuti docs/deploy.md bagian 'Hosting biasa / cPanel'."
