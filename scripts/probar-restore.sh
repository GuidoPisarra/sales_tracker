#!/usr/bin/env bash
# Prueba mensual de restore: carga el último backup en una base temporal,
# cuenta filas de las tablas principales y la borra. Un backup que nunca se
# restauró no cuenta como backup.
#
#   ./scripts/probar-restore.sh
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$DIR/backup.conf" ] && . "$DIR/backup.conf"
MYSQL_CNF="${MYSQL_CNF:-$HOME/.my.cnf}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/kontia}"
BD_PRUEBA="kontia_restore_prueba"

ULTIMO="$(ls -1t "$BACKUP_DIR/diario/"* | head -1)"
echo "Restaurando $ULTIMO en $BD_PRUEBA"

mysql --defaults-extra-file="$MYSQL_CNF" -e "DROP DATABASE IF EXISTS $BD_PRUEBA; CREATE DATABASE $BD_PRUEBA"
if [[ "$ULTIMO" == *.gpg ]]; then
  gpg --batch --pinentry-mode loopback --passphrase "$BACKUP_GPG_PASS" -d "$ULTIMO" | gunzip | mysql --defaults-extra-file="$MYSQL_CNF" "$BD_PRUEBA"
else
  gunzip -c "$ULTIMO" | mysql --defaults-extra-file="$MYSQL_CNF" "$BD_PRUEBA"
fi

for t in negocio user product sales_product suscripcion; do
  n="$(mysql --defaults-extra-file="$MYSQL_CNF" -N -e "SELECT COUNT(*) FROM $BD_PRUEBA.$t" 2>/dev/null || echo 'falta')"
  echo "  $t: $n filas"
done

mysql --defaults-extra-file="$MYSQL_CNF" -e "DROP DATABASE $BD_PRUEBA"
echo "Restore OK"
