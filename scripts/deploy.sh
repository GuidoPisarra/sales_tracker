#!/usr/bin/env bash
# Deploy del backend en el VPS (sin Docker), desde la carpeta del proyecto:
#   ./scripts/deploy.sh [rama]      (por defecto: main)
# Hace backup de la base, trae el código, instala dependencias, corre las
# migraciones pendientes y limpia la caché. Si algo falla, se detiene.
set -euo pipefail

RAMA="${1:-main}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

echo "==> Backup previo de la base"
"$DIR/scripts/backup-db.sh" pre-deploy

echo "==> Código ($RAMA)"
git fetch origin "$RAMA"
git checkout "$RAMA"
git pull --ff-only origin "$RAMA"

echo "==> Dependencias"
if command -v composer >/dev/null 2>&1; then COMPOSER=composer; else COMPOSER="php composer.phar"; fi
APP_ENV=prod $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-scripts

echo "==> Migraciones"
APP_ENV=prod php bin/console app:migraciones

echo "==> Caché"
APP_ENV=prod php bin/console cache:clear --no-warmup
APP_ENV=prod php bin/console cache:warmup

echo "Deploy terminado: $(git log --oneline -1)"
