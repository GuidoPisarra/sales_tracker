#!/usr/bin/env bash
# Backup de MySQL: dump consistente, comprimido y (opcional) cifrado y copiado
# fuera del VPS. Pensado para cron diario.
#
#   ./scripts/backup-db.sh [etiqueta]
#
# Configuración en scripts/backup.conf (no se sube al repo), por ejemplo:
#   BD_NOMBRE=kontia
#   MYSQL_CNF=/home/usuario/.my.cnf     # [client] user=... password=... host=...
#   BACKUP_DIR=/var/backups/kontia
#   BACKUP_GPG_PASS=una-clave-larga      # vacío = sin cifrar
#   RCLONE_DESTINO=r2:kontia-backups     # vacío = solo copia local
#   RETENCION_DIARIA=30
#   RETENCION_MENSUAL=12
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$DIR/backup.conf" ] && . "$DIR/backup.conf"

BD_NOMBRE="${BD_NOMBRE:-kontia}"
MYSQL_CNF="${MYSQL_CNF:-$HOME/.my.cnf}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/kontia}"
RETENCION_DIARIA="${RETENCION_DIARIA:-30}"
RETENCION_MENSUAL="${RETENCION_MENSUAL:-12}"
ETIQUETA="${1:-diario}"

mkdir -p "$BACKUP_DIR/diario" "$BACKUP_DIR/mensual"
FECHA="$(date +%Y-%m-%d_%H%M)"
ARCHIVO="$BACKUP_DIR/diario/${BD_NOMBRE}_${FECHA}_${ETIQUETA}.sql.gz"

# --single-transaction: copia consistente sin bloquear la Caja mientras corre.
mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --routines --triggers \
  --no-tablespaces "$BD_NOMBRE" | gzip -9 > "$ARCHIVO"

if [ -n "${BACKUP_GPG_PASS:-}" ]; then
  gpg --batch --yes --pinentry-mode loopback --passphrase "$BACKUP_GPG_PASS" \
      --symmetric --cipher-algo AES256 -o "$ARCHIVO.gpg" "$ARCHIVO"
  rm -f "$ARCHIVO"
  ARCHIVO="$ARCHIVO.gpg"
fi

# El primer backup de cada mes queda también como mensual.
MES="$(date +%Y-%m)"
if ! ls "$BACKUP_DIR/mensual/"*"_${MES}-"* >/dev/null 2>&1; then
  cp "$ARCHIVO" "$BACKUP_DIR/mensual/"
fi

# Retención.
find "$BACKUP_DIR/diario" -type f -mtime +"$RETENCION_DIARIA" -delete
ls -1t "$BACKUP_DIR/mensual/"* 2>/dev/null | tail -n +"$((RETENCION_MENSUAL + 1))" | xargs -r rm -f

# Copia fuera del VPS (un backup en el mismo disco no sirve si se pierde el VPS).
if [ -n "${RCLONE_DESTINO:-}" ]; then
  rclone copy "$BACKUP_DIR" "$RCLONE_DESTINO" --max-age 48h
fi

echo "Backup OK: $ARCHIVO ($(du -h "$ARCHIVO" | cut -f1))"
