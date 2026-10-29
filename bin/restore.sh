#!/usr/bin/env bash
#
# Restauración de una copia de seguridad de Suscripta.
#
# El script está pensado para el caso peor: restaurar en un entorno limpio.
# Con «--limpio» suelta la base de datos, la vuelve a crear y la restaura
# desde cero, que es exactamente lo que se hace en una recuperación real.
#
# Uso:
#   bin/restore.sh PREFIJO [--limpio] [--sin-documentos]
#   bin/restore.sh var/backups/suscripta-20261023-120000 --limpio
#
# Requisitos: docker compose con los servicios «php» y «database» levantados.

set -Eeuo pipefail

RAIZ="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="${COMPOSE:-docker compose}"
ORIGEN=""
LIMPIO=0
CON_DOCUMENTOS=1

log() { printf '\033[36m[restore]\033[0m %s\n' "$*"; }
error() { printf '\033[31m[restore] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

uso() {
    cat <<'EOF'
Uso: bin/restore.sh PREFIJO [opciones]

  PREFIJO            Nombre de la copia (sin extensión) o ruta al manifiesto.
  --limpio           Suelta y recrea la base de datos antes de restaurar.
  --sin-documentos   No restaurar var/storage.
  -h, --help         Esta ayuda.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --limpio) LIMPIO=1; shift ;;
        --sin-documentos) CON_DOCUMENTOS=0; shift ;;
        -h|--help) uso; exit 0 ;;
        -*) error "Opción desconocida: $1" ;;
        *) [[ -n "$ORIGEN" ]] && error "Solo se admite una copia a la vez."; ORIGEN="$1"; shift ;;
    esac
done

[[ -n "$ORIGEN" ]] || { uso; error "Falta indicar la copia."; }

cd "$RAIZ"

# Un nombre corto se busca en el directorio de copias por defecto.
if [[ "$ORIGEN" != */* && -f "${RAIZ}/var/backups/${ORIGEN}.manifest.json" ]]; then
    ORIGEN="${RAIZ}/var/backups/${ORIGEN}"
fi

# Se acepta el prefijo (suscripta-AAAAMMDD-HHMMSS) o el manifiesto.
if [[ -f "$ORIGEN" && "$ORIGEN" == *.manifest.json ]]; then
    MANIFIESTO="$ORIGEN"
    PREFIJO="$(basename "$ORIGEN" .manifest.json)"
elif [[ -f "${ORIGEN}.manifest.json" ]]; then
    MANIFIESTO="${ORIGEN}.manifest.json"
    PREFIJO="$(basename "$ORIGEN")"
else
    error "No se encuentra la copia «$ORIGEN»."
fi

DESTINO_DIR="$(dirname "$MANIFIESTO")"
DUMP="${DESTINO_DIR}/${PREFIJO}.dump.sql.gz"
DOCUMENTOS="${DESTINO_DIR}/${PREFIJO}.storage.tar.gz"

[ -f "$DUMP" ] || error "Falta el volcado ${DUMP}."

# Verificación de integridad antes de tocar nada: si la suma no cuadra, la
# copia está corrupta y es mejor saberlo ahora que en mitad de la restauración.
SUMA_ESPERADA="$(grep -o '"base_de_datos": "[a-f0-9]*"' "$MANIFIESTO" | head -1 | cut -d'"' -f4)"
if [[ -n "$SUMA_ESPERADA" ]]; then
    SUMA_REAL="$(sha256sum "$DUMP" | cut -d' ' -f1)"
    if [[ "$SUMA_ESPERADA" != "$SUMA_REAL" ]]; then
        error "La copia está corrupta: la suma no cuadra (esperada ${SUMA_ESPERADA}, real ${SUMA_REAL})."
    fi
    log "Suma verificada."
fi

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -qx 'database'; then
    error "El servicio «database» no está en marcha."
fi

if [[ "$LIMPIO" -eq 1 ]]; then
    log "Soltando y recreando la base de datos…"
    # Las comillas simples son a propósito: esas variables las expande el shell
    # de dentro del contenedor, no este.
    # shellcheck disable=SC2016
    $COMPOSE exec -T database sh -c \
        'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 \
            -c "DROP DATABASE IF EXISTS \"$POSTGRES_DB\" WITH (FORCE);" \
            -c "CREATE DATABASE \"$POSTGRES_DB\" OWNER \"$POSTGRES_USER\";"' >/dev/null
fi

log "Restaurando la base de datos…"
# shellcheck disable=SC2016
if ! gunzip -c "$DUMP" | $COMPOSE exec -T database sh -c \
    'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner --no-privileges --exit-on-error'; then
    error "Falló la restauración de la base de datos."
fi

if [[ "$CON_DOCUMENTOS" -eq 1 && -f "$DOCUMENTOS" ]]; then
    log "Restaurando los documentos…"
    if ! gunzip -c "$DOCUMENTOS" | $COMPOSE exec -T php sh -c \
        'tar -xf - -C /app --no-same-owner 2>/dev/null || tar -xf - -C /app'; then
        error "Falló la restauración de los documentos."
    fi
    # En producción el proceso corre como www-data y los ficheros deben
    # seguir siendo suyos; en desarrollo el contenedor va como root.
    $COMPOSE exec -T --user root php sh -c \
        'chown -R www-data:www-data /app/var/storage 2>/dev/null || true' >/dev/null 2>&1 || true
fi

log "Limpiando la caché de la aplicación…"
$COMPOSE exec -T php php bin/console cache:clear --no-interaction >/dev/null 2>&1 || true

log "Comprobando el estado de las migraciones…"
# «migrate --dry-run» es el comprobador fiable: si la copia arrastra el
# esquema completo, no hay nada pendiente que ejecutar.
$COMPOSE exec -T php php bin/console doctrine:migrations:migrate --dry-run --no-interaction 2>&1 | tail -n 3 || true

log "Restauración completada. Copia: $PREFIJO"
