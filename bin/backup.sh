#!/usr/bin/env bash
#
# Copia de seguridad de Suscripta.
#
# Qué guarda:
#   1. La base de datos (pg_dump en formato custom, comprimido con gzip).
#   2. Los binarios de los documentos subidos (var/storage dentro del contenedor).
#   3. Un manifiesto con sumas de verificación, revisión de código y metadatos.
#
# Qué NO guarda (hay que guardarlo aparte, ver docs/operations.md §3):
#   - .env.local, APP_SECRET y las claves de los proveedores de IA: son
#     credenciales y no deben viajar en una copia de datos.
#
# Uso:
#   bin/backup.sh [--out DIRECTORIO] [--keep N] [--label TEXTO] [--sin-documentos]
#
# Requisitos: docker compose con los servicios «php» y «database» levantados.
# La restauración la hace bin/restore.sh, que es el que se prueba en un
# entorno limpio.

set -Eeuo pipefail

RAIZ="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="${COMPOSE:-docker compose}"
DESTINO="${RAIZ}/var/backups"
CONSERVAR=14
ETIQUETA=""
CON_DOCUMENTOS=1

log() { printf '\033[36m[backup]\033[0m %s\n' "$*"; }
error() { printf '\033[31m[backup] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

uso() {
    cat <<'EOF'
Uso: bin/backup.sh [opciones]

  --out DIRECTORIO   Dónde se deja la copia (por defecto var/backups).
  --keep N           Cuántas copias antiguas se conservan (por defecto 14).
  --label TEXTO      Nota que se guarda en el manifiesto.
  --sin-documentos   No incluir los binarios de var/storage.
  -h, --help         Esta ayuda.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --out) DESTINO="${2:?--out necesita un directorio}"; shift 2 ;;
        --keep) CONSERVAR="${2:?--keep necesita un número}"; shift 2 ;;
        --label) ETIQUETA="${2:?--label necesita un texto}"; shift 2 ;;
        --sin-documentos) CON_DOCUMENTOS=0; shift ;;
        -h|--help) uso; exit 0 ;;
        *) error "Opción desconocida: $1" ;;
    esac
done

command -v docker >/dev/null 2>&1 || error "No se encuentra «docker» en el PATH."

cd "$RAIZ"

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -qx 'database'; then
    error "El servicio «database» no está en marcha. Levanta el entorno con «make up»."
fi

mkdir -p "$DESTINO"

SELLAMA="$(date -u +%Y%m%d-%H%M%S)"
PREFIJO="suscripta-${SELLAMA}"
DUMP="${DESTINO}/${PREFIJO}.dump.sql.gz"
DOCUMENTOS="${DESTINO}/${PREFIJO}.storage.tar.gz"
MANIFIESTO="${DESTINO}/${PREFIJO}.manifest.json"

log "Volcando la base de datos…"
# El dump se hace dentro del contenedor de base de datos: es el único sitio
# donde hay pg_dump. El formato custom permite restaurar tablas sueltas con
# pg_restore si algún día hace falta.
if ! $COMPOSE exec -T database sh -c \
    'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner --no-privileges' \
    | gzip -9 > "$DUMP"; then
    rm -f "$DUMP"
    error "Falló el volcado de la base de datos."
fi

[ -s "$DUMP" ] || { rm -f "$DUMP"; error "El volcado ha salido vacío."; }

if [[ "$CON_DOCUMENTOS" -eq 1 ]]; then
    log "Empaquetando los documentos (var/storage)…"
    if ! $COMPOSE exec -T php sh -c \
        'if [ -d /app/var/storage ]; then tar -czf - -C /app var/storage; else tar -czf - -T /dev/null; fi' \
        > "$DOCUMENTOS"; then
        rm -f "$DUMP" "$DOCUMENTOS"
        error "Falló el empaquetado de los documentos."
    fi
fi

# Sumas de verificación: una copia sin verificar no es una copia.
SUMA_DUMP="$(sha256sum "$DUMP" | cut -d' ' -f1)"
SUMA_DOCUMENTOS=""
[[ "$CON_DOCUMENTOS" -eq 1 ]] && SUMA_DOCUMENTOS="$(sha256sum "$DOCUMENTOS" | cut -d' ' -f1)"

REVISION="$(git rev-parse --short HEAD 2>/dev/null || echo 'desconocida')"
RAMO="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'desconocido')"
VERSION_PHP="$($COMPOSE exec -T php php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r\n' || echo 'desconocida')"
PESOS_DUMP="$(du -h "$DUMP" | cut -f1)"

cat > "$MANIFIESTO" <<EOF
{
    "sello": "$SELLAMA",
    "etiqueta": "$ETIQUETA",
    "base_de_datos": "$(basename "$DUMP")",
    "documentos": "$(basename "$DOCUMENTOS")",
    "sha256": {
        "base_de_datos": "$SUMA_DUMP",
        "documentos": "$SUMA_DOCUMENTOS"
    },
    "bytes": {
        "base_de_datos": $(stat -c %s "$DUMP")
    },
    "revision": "$REVISION",
    "rama": "$RAMO",
    "php": "$VERSION_PHP",
    "generado": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

log "Copia lista: $DUMP (${PESOS_DUMP})"

# Poda: se conservan las N copias más recientes.
if [[ "$CONSERVAR" =~ ^[0-9]+$ ]] && [[ "$CONSERVAR" -gt 0 ]]; then
    mapfile -t VIEJAS < <(ls -1t "${DESTINO}"/suscripta-*.manifest.json 2>/dev/null | tail -n "+$((CONSERVAR + 1))")
    for MANIFESTO_VIEJO in "${VIEJAS[@]:-}"; do
        [[ -n "$MANIFESTO_VIEJO" ]] || continue
        BASE="$(basename "$MANIFESTO_VIEJO" .manifest.json)"
        rm -f "$DESTINO/${BASE}.dump.sql.gz" "$DESTINO/${BASE}.storage.tar.gz" "$MANIFESTO_VIEJO"
        log "Poda: ${BASE}"
    done
fi

log "Manifiesto: $MANIFIESTO"
log "Restaura con: bin/restore.sh ${PREFIJO}"
