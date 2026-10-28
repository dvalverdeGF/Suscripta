# Operación de Suscripta

Runbook de operación: despliegues, copias de seguridad, restauración, vigilancia
y los incidentes que hay que saber resolver. Está pensado para que la persona
encargada de que la aplicación siga en pie no tenga que leer el código.

- [1. Qué hay en producción](#1-qué-hay-en-producción)
- [2. Despliegue](#2-despliegue)
- [3. Copias de seguridad](#3-copias-de-seguridad)
- [4. Restauración](#4-restauración)
- [5. Vigilancia](#5-vigilancia)
- [6. Incidentes frecuentes](#6-incidentes-frecuentes)
- [7. Rotación de secretos](#7-rotación-de-secretos)
- [8. Tareas de mantenimiento](#8-tareas-de-mantenimiento)
- [9. Lista de comprobación](#9-lista-de-comprobación)

---

## 1. Qué hay en producción

| Proceso | Contenedor | Qué hace | Cómo se vigila |
|---|---|---|---|
| Servidor web | `php` | Sirve el backoffice y la API con FrankenPHP + Caddy. | `GET /health` devuelve `200`. |
| Worker | `worker` | Consume las colas `mail_sync`, `mail_processing`, `ocr`, `ai` y `notifications`. | El proceso debe estar vivo; el contenedor reinicia solo si muere. |
| Base de datos | `database` | PostgreSQL 16. Todo el estado del producto. | `pg_isready` y el healthcheck del contenedor. |
| Caddy | dentro de `php` | TLS, HTTP/3 y Mercure. | Renovación de certificados automática. |

Lo que vive **dentro** de la base de datos: organizaciones, servicios, facturas,
avisos, descubrimientos, correos, mensajes extraídos, uso de IA, integraciones
y el registro de auditoría. Lo que vive **fuera**: los binarios de los
documentos subidos, en `var/storage` (dentro del contenedor `php`).

Regla de oro: **la copia de seguridad es la base de datos más `var/storage`**.
Si falta cualquiera de las dos, la restauración está incompleta.

---

## 2. Despliegue

El despliegue no compila nada en el servidor: la imagen se construye en CI y se
descarga. En un servidor propio el procedimiento equivalente es:

```bash
# 1. Traer el código
git fetch --tags && git checkout <etiqueta-o-commit>

# 2. Levantar el entorno de producción
docker compose -f compose.yaml -f compose.prod.yaml up -d

# 3. Aplicar las migraciones ANTES de dejar que el tráfico entre
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console doctrine:migrations:migrate --no-interaction

# 4. Recalentar la caché (evita servir con la caché de la versión anterior)
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console cache:clear --no-interaction

# 5. Reiniciar los workers para que carguen el código nuevo
docker compose -f compose.yaml -f compose.prod.yaml restart worker
```

Reglas que ahorran incidentes:

- **Las migraciones van antes que el código nuevo.** Si el código nuevo
  espera una columna que aún no existe, la primera petición que la toque
  revienta. Si la migración va después, el código viejo sigue funcionando
  con el esquema nuevo.
- **Las migraciones deben ser retrocompatibles** durante el despliegue:
  primero se añade la columna, luego se rellena, y solo en una versión
  posterior se deja de escribir en ella.
- **El worker se reinicia al final.** Si no, se queda con el código viejo en
  memoria y procesa la cola con la lógica anterior.
- **Una copia antes de migrar.** `bin/backup.sh` tarda segundos y es la
  única red de seguridad real de una migración.

Comprobación posterior:

```bash
curl -fsS https://<dominio>/health            # debe devolver 200
docker compose -f compose.yaml -f compose.prod.yaml logs --tail=50 worker
```

---

## 3. Copias de seguridad

### Qué se guarda

| Parte | Dónde | Script |
|---|---|---|
| Base de datos (volcado `pg_dump` en formato custom) | `var/backups/suscripta-AAAAMMDD-HHMMSS.dump.sql.gz` | `bin/backup.sh` |
| Documentos subidos (`var/storage`) | `var/backups/suscripta-AAAAMMDD-HHMMSS.storage.tar.gz` | `bin/backup.sh` |
| Manifiesto con sumas SHA-256, revisión de código y fecha | `var/backups/suscripta-AAAAMMDD-HHMMSS.manifest.json` | `bin/backup.sh` |

**No se guarda** y hay que custodiar aparte: `.env.local`, `APP_SECRET`, la
clave de Mercure, la contraseña de PostgreSQL y las claves de los proveedores
de IA. Son credenciales; en una copia de datos no tienen nada que buscar. Si
se pierde `APP_SECRET` hay que regenerarlo y forzar el cierre de sesión de
todo el mundo (rotación en [§7](#7-rotación-de-secretos)).

### Cómo se hace

```bash
make backup                                  # copia en var/backups
bin/backup.sh --label "antes de migrar"      # con una nota en el manifiesto
bin/backup.sh --out /mnt/copias --keep 30    # otro destino y otra retención
```

El script se encarga de todo: vuelca la base de datos desde el contenedor
correcto, empaqueta los documentos, calcula las sumas de verificación, escribe
el manifiesto y **poda** las copias antiguas según `--keep` (14 por defecto).

### Retención recomendada

| Copia | Cada cuánto | Cuánto se guarda |
|---|---|---|
| Diaria | 1 al día | 14 días |
| Semanal | 1 a la semana | 8 semanas |
| Mensual | 1 al mes | 12 meses |

### Reglas de custodia

1. **Copia fuera del servidor.** `var/backups` está en el mismo disco que la
   aplicación: si se pierde el servidor, se pierde la copia. Hay que subir
   las copias a un almacenamiento externo (S3, Backblaze, un NAS…). Nunca
   mantener la única copia en la misma máquina.
2. **Cifrar y con copia de la clave.** Las copias contienen datos personales
   de los clientes (facturas, correos, documentos). Si se cifran, la clave
   tiene que vivir en otro sitio, o la copia no se puede recuperar.
3. **Restaurar es la única prueba.** Una copia que nunca se ha restaurado no
   es una copia, es una suposición. Hay que hacer un simulacro al menos
   cada trimestre, siguiendo el [§4](#4-restauración).

---

## 4. Restauración

Simulacro completo, con la base de datos de desarrollo:

```bash
# 1. Crear la copia
bin/backup.sh --label "simulacro"

# 2. Restaurarla en un entorno limpio
bin/restore.sh suscripta-AAAAMMDD-HHMMSS --limpio
```

Qué hace `--limpio`: **suelta la base de datos, la vuelve a crear y la
restaura desde cero**. Es exactamente lo que se hace en una recuperación real
y deja claro que la copia no depende del estado previo de la base de datos.

La salida del script es la evidencia de que la restauración funcionó:

```
[restore] Suma verificada.
[restore] Soltando y recreando la base de datos…
[restore] Restaurando la base de datos…
[restore] Restaurando los documentos…
[restore] Comprobando el estado de las migraciones…
 [OK] Already at the latest version ("DoctrineMigrations\Version20261023090000")
[restore] Restauración completada.
```

### Comprobaciones tras restaurar

```bash
# La base de datos tiene las tablas y los datos
docker compose exec -T database psql -U app -d app \
    -c "select count(*) from organization;"

# La versión del esquema es la última
docker compose exec -T php php bin/console doctrine:migrations:migrate --dry-run

# Los documentos están y son legibles por la aplicación
docker compose exec -T php ls -l /app/var/storage

# La aplicación responde
curl -fsS https://<dominio>/health
```

### Restauración en producción

1. Poner la aplicación en mantenimiento (parar `php` y `worker`) para que
   nadie escriba mientras se restaura.
2. `bin/restore.sh <copia> --limpio`.
3. `php bin/console cache:clear` y reiniciar `php` y `worker`.
4. Comprobaciones de arriba.
5. Avisar a los clientes si se ha perdido algún dato posterior a la copia.

### Restauración parcial (una tabla)

El volcado está en formato custom, así que se puede restaurar una parte:

```bash
gunzip -c var/backups/suscripta-AAAAMMDD-HHMMSS.dump.sql.gz > /tmp/copia.dump
docker compose exec -T database pg_restore -U app -d app \
    --clean --if-exists --data-only -t document /tmp/copia.dump
```

Cuidado: restaurar datos sobre una base de datos viva puede dejar filas
duplicadas o incoherentes. Para datos, es preferible restaurar la tabla
entera en una base de datos aparte y mover las filas a mano.

---

## 5. Vigilancia

### El panel de observabilidad

`/observability` (menú «Observabilidad») es el panel de producto: sincronizaciones,
extracción por nivel, uso y coste de la IA, avisos abiertos. Aplica a toda la
organización activa y permite elegir el periodo (24 h, 7 días, 30 días).

Cuando aparece el cartel rojo «Hay algo que revisar», el motivo está escrito
justo debajo:

| Síntoma | Dónde mirar | Qué hacer |
|---|---|---|
| «N sincronizaciones fallidas» | `email_sync_run` con `status = failed` | Revisar el mensaje de error y probar la conexión IMAP desde el panel de integraciones. |
| «N llamadas a la IA fallidas» | `ai_usage` con estado de error | Mirar el error de la llamada; si es el presupuesto, el panel de IA lo indica. |
| Avisos abiertos | Panel de avisos | Son renewing/cobros que hay que revisar; no son fallos del sistema. |

### Salud de la aplicación

```bash
curl -fsS https://<dominio>/health          # sonda de vida
docker compose logs --tail=100 worker       # errores del procesamiento
docker compose logs --tail=100 php | grep -i error
```

### Cola de trabajo

Si la cola crece, el worker está parado o saturado:

```bash
docker compose exec -T php php bin/console messenger:stats
docker compose exec -T php php bin/console messenger:failed:show
```

`messenger:failed:retry` reintenta los mensajes que fallaron de forma
irrecuperable; conviene leer antes el error.

### Webhooks de salida

Los webhooks tienen su propia cola (`webhooks`) y su propia política de reintentos, así que no
aparecen en `messenger:failed` como el resto: **un webhook fallido no es un mensaje fallido**, es
una entrega que sigue viva en la tabla `webhook_delivery`. Se consulta así:

```bash
# Entregas pendientes, con sus intentos y el próximo que toca
docker compose exec -T database psql -U app -d app -c "
  SELECT status, count(*) FROM webhook_delivery GROUP BY status;"

# Las que más están fallando en las últimas 24 h
docker compose exec -T database psql -U app -d app -c "
  SELECT w.url, d.event, count(*) AS fallos, max(d.last_error) AS ultimo
  FROM webhook_delivery d JOIN webhook w ON w.id = d.webhook_id
  WHERE d.status = 'PENDING' AND d.last_error IS NOT NULL
    AND d.created_at > now() - interval '24 hours'
  GROUP BY w.url, d.event ORDER BY fallos DESC;"
```

Dos cosas que hay que vigilar:

1. **El worker tiene que consumir el transporte `webhooks`.** Si no está en la lista de
   `messenger:consume`, las entregas se quedan en `PENDING` y nadie las envía. En desarrollo y en
   producción el worker lo incluye; `make worker-webhooks` levanta uno dedicado.
2. **El cron de mantenimiento tiene que estar puesto** (ver §8). Sin él, una entrega que venció
   mientras el worker estaba parado no se reencola hasta el siguiente arranque.

El detalle del contrato (firma, cabeceras, eventos) está en `docs/api/webhooks.md`; el diseño, en
`ARCHITECTURE.md` §18.

---

## 6. Incidentes frecuentes

### «No llega correo»

1. ¿El buzón está activo y las credenciales son válidas? Panel de
   integraciones → buzón → «Probar conexión».
2. ¿Se está encolando? `app:mail:sync -v` a mano y mirar la salida.
3. ¿La cola está parada? `docker compose ps worker` y
   `messenger:stats`.

### «El consumo de IA se ha disparado»

1. Panel de integraciones → IA: ver el presupuesto por organización y el
   proveedor activo.
2. `ai_usage` ordenado por coste para ver qué llamadas pesan.
3. El presupuesto por plan es un tope duro (Fase 17): al superarlo, los
   correos se **aplazan** en lugar de consumir más. Se liberan con
   `app:ai:release-deferred` cuando se sube el presupuesto.

### «Una organización ha superado su plan»

Los límites del plan se aplican sin tocar el dominio: si la organización
excede servicios, buzones o documentos, el panel lo indica y la operación
que lo incumple se rechaza con un aviso explicativo. Para desbloquear:
`app:plan:set` (ver `app:plan:list` para ver quién está en qué plan).

### «La aplicación va lenta»

1. `docker compose stats` para ver CPU y memoria del contenedor `php`.
2. Si el worker consume mucha memoria, es el límite de 256 MB: subirlo o
   reducir el lote.
3. Comprobar índices con `doctrine:schema:validate`.

### «Se ha filtrado una clave»

Ver [§7](#7-rotación-de-secretos). Lo primero es **revocar** en el
proveedor; lo segundo es rotar aquí.

---

## 7. Rotación de secretos

| Secreto | Dónde vive | Cómo se rota | Efecto |
|---|---|---|---|
| `APP_SECRET` | `.env.local` o el almacén de secretos | Cambiar el valor y `cache:clear` | Cierra las sesiones y firma las cookies; se avisa a los usuarios. |
| Contraseña de PostgreSQL | `.env.local` + variable del contenedor `database` | Cambiar en ambos y `ALTER USER` | Reinicio del contenedor de base de datos. |
| Clave de Mercure | `CADDY_MERCURE_JWT_SECRET` | Cambiar la variable y reiniciar `php` | Las suscripciones se reconectan solas. |
| Claves de proveedores de IA | Panel de integraciones | Editar la integración y «Probar» | Nada; la siguiente llamada usa la clave nueva. |
| Claves de la API pública | Panel de integraciones → API | Revocar la clave y crear otra | La clave revocada deja de funcionar de inmediato. |

Procedimiento para una clave de proveedor:

1. Crear la clave nueva en el proveedor.
2. Meterla en el panel de integraciones y pulsar «Probar».
3. Retirar la clave antigua en el proveedor.
4. Anotarlo en el registro de cambios (`docs/DECISIONS.md` o el parte de
   incidentes) con la fecha y quién lo hizo.

---

## 8. Tareas de mantenimiento

| Tarea | Comando | Cuándo |
|---|---|---|
| Sincronizar buzones | `app:mail:sync -v` | A demanda; el worker lo hace solo. |
| Recalcular avisos | `app:alerts:generate -v` | Después de cambios de precios o vencimientos. |
| Detectar cambios de inventario | `app:changes:detect` | Semanal. |
| Detectar recurrencias | `app:recurrence:detect` | Semanal, sobre facturas nuevas. |
| Caducar descubrimientos antiguos | `app:discoveries:expire` | Diario. |
| Podar conocimiento sin uso | `app:knowledge:prune` | Mensual. |
| Purgar datos por retención | `app:privacy:purge` | Mensual (política de retención en `SECURITY.md` §6). |
| Liberar correos aplazados por presupuesto | `app:ai:release-deferred` | Al subir el presupuesto. |
| Reencolar entregas de webhook vencidas | `app:webhooks:maintain` | **Cada 5 minutos** (ver abajo). |
| Sembrar la demostración | `app:demo:seed` | Solo en desarrollo. |

Las tareas que se ejecutan a diario y semanalmente se programan con el
cron del servidor (o con un `cron` en el contenedor `php`). Todas son
idempotentes: repetirlas no rompe nada.

### Cron mínimo en producción

`app:webhooks:maintain` es la única que **no** puede faltar: es lo que recupera las entregas que
vencieron mientras el worker estaba parado o reiniciándose. Cada cinco minutos es suficiente y
sobra (la granularidad de la política es de 30 segundos a 24 horas):

```cron
# /etc/cron.d/suscripta
*/5 * * * *  app  cd /app && php bin/console app:webhooks:maintain --no-interaction >/dev/null 2>&1
0   3 * * *  app  cd /app && php bin/console app:discoveries:expire --no-interaction
30  3 * * *  app  cd /app && php bin/console app:alerts:generate --no-interaction
0   4 * * 1  app  cd /app && php bin/console app:changes:detect --no-interaction
0   5 * * 1  app  cd /app && php bin/console app:recurrence:detect --no-interaction
0   4 1 * *  app  cd /app && php bin/console app:knowledge:prune --no-interaction
0   5 1 * *  app  cd /app && php bin/console app:privacy:purge --no-interaction
```

Si el despliegue es en Docker, el `cron` va en el contenedor `php` o en el anfitrión con
`docker compose exec -T php php bin/console …`. Lo importante es que se ejecute **una sola vez**
por intervalo: dos crons en dos contenedores reencolarían lo mismo dos veces (el reencolado es
idempotente por estado, pero genera ruido en los registros).

---

## 9. Lista de comprobación

### Antes de dar por bueno un despliegue

- [ ] Copia de seguridad creada (`bin/backup.sh`).
- [ ] Migraciones aplicadas y sin pendientes.
- [ ] `cache:clear` ejecutado.
- [ ] Workers reiniciados.
- [ ] `/health` responde `200`.
- [ ] `/observability` sin fallos de sincronización ni de IA.
- [ ] El worker consume `webhooks` (`docker compose exec -T php php bin/console messenger:stats`
      muestra la cola) y `app:webhooks:maintain` está en el cron.
- [ ] Un correo de prueba entra, se procesa y genera un aviso.

### Al cierre de cada mes

- [ ] Copias fuera del servidor y verificadas.
- [ ] Simulacro de restauración (o al menos, verificación de sumas).
- [ ] Revisión del consumo de IA y de los presupuestos por plan.
- [ ] Tareas de mantenimiento ejecutadas.
- [ ] Registro de incidentes y cambios de claves.

### Al cierre de cada fase del desarrollo

- [ ] `make test` en verde.
- [ ] `make phpstan` sin errores nuevos.
- [ ] `make cs-check` limpio.
- [ ] `docs/ROADMAP.md`, `docs/ARCHITECTURE.md` y `docs/DECISIONS.md`
      actualizados con lo que la fase ha cambiado.
