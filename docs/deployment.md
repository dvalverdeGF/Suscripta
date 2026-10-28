# Desplegar Suscripta en producción

Guía del **primer** despliegue: de un servidor vacío a una instancia sirviendo tráfico real.
Para el día a día (desplegar una versión nueva, copias, restauración, incidentes) el documento de
referencia es [operations.md](operations.md); esto solo cubre lo que se hace **una vez**.

- [1. Qué se necesita](#1-qué-se-necesita)
- [2. Preparar el servidor](#2-preparar-el-servidor)
- [3. Secretos](#3-secretos)
- [4. Primer arranque](#4-primer-arranque)
- [5. Verificación](#5-verificación)
- [6. Después del primer despliegue](#6-después-del-primer-despliegue)
- [7. Errores que se cometen la primera vez](#7-errores-que-se-cometen-la-primera-vez)

---

## 1. Qué se necesita

| Pieza | Mínimo | Nota |
|---|---|---|
| Servidor | 2 vCPU, 2 GB RAM, 20 GB SSD | El worker de la cola convive con el servidor web. |
| Sistema | Linux con Docker Engine 24+ y Compose v2.10+ | Es lo único que hay que instalar. |
| Base de datos | PostgreSQL 16 | Va en su contenedor; no hace falta un servicio externo. |
| Dominio | Uno, con DNS apuntando al servidor | Caddy pide el certificado solo, hace falta el puerto 80 y el 443 abiertos. |
| Correo saliente | Un relay SMTP | Para verificación de cuenta, recuperación de contraseña y avisos. |

No hace falta PHP, Composer ni Node en el servidor: la imagen se construye con todo dentro.

---

## 2. Preparar el servidor

```bash
# 1. El código
git clone https://github.com/dvalverdeGF/Suscripta.git /srv/suscripta
cd /srv/suscripta
git checkout <etiqueta-o-commit>       # nunca desplegar main a ciegas

# 2. Los ficheros de configuración locales (no están en el repositorio)
cp .env .env.local
$EDITOR .env.local
```

En `.env.local` hay que fijar, como mínimo:

```dotenv
APP_ENV=prod
APP_SECRET=<64 caracteres aleatorios>
SERVER_NAME=suscripta.example.com
POSTGRES_PASSWORD=<contraseña larga>
CADDY_MERCURE_JWT_SECRET=<64 caracteres aleatorios>
MERCURE_PUBLIC_URL=https://suscripta.example.com/.well-known/mercure
MAILER_DSN=smtp://usuario:clave@relay.example.com:587
```

Generar los valores aleatorios:

```bash
openssl rand -hex 32        # APP_SECRET, CADDY_MERCURE_JWT_SECRET, API_KEY_PEPPER
openssl rand -base64 24     # POSTGRES_PASSWORD
```

> **`API_CORS_ALLOWED_ORIGINS` va entre comillas simples.** Symfony Dotenv se come las comillas
> dobles y el JSON queda inválido, con lo que la aplicación **no arranca**:
>
> ```dotenv
> API_CORS_ALLOWED_ORIGINS='["https://app.example.com"]'
> ```
>
> Se puede comprobar con `bin/console debug:dotenv`. Si no se define, la API no admite ningún
> origen de navegador (correcto si solo la consumen servidores).

---

## 3. Secretos

Hay dos niveles y conviene no confundirlos:

| Nivel | Dónde | Qué va ahí |
|---|---|---|
| Entorno | `.env.local` o el entorno del contenedor | Lo que la infraestructura necesita **antes** de arrancar: `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`. |
| Aplicación | `bin/console secrets:set` (cifrado en `config/secrets/`) | Credenciales de proveedores y claves que la aplicación lee en tiempo de ejecución. |

Los secretos de la aplicación se guardan cifrados con una clave de descifrado que **vive en el
entorno** (`APP_SECRET` por defecto). Consecuencia práctica: **el almacén de secretos no sirve de
nada sin el `.env.local`**, así que los dos se custodian juntos y se copian juntos.

```bash
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console secrets:set API_KEY_PEPPER --random
```

> **`API_KEY_PEPPER` merece una decisión consciente.** Si no se define, la aplicación usa
> `APP_SECRET` como pimienta del HMAC de las claves de API. Funciona, pero acopla dos cosas que
> deberían poder rotar por separado: **cambiar `APP_SECRET` invalidaría todas las claves de API
> emitidas**. En producción se fija un `API_KEY_PEPPER` propio desde el primer día, porque
> definirlo después deja inservibles las claves ya repartidas.

Lo que **no** hay que hacer: meter credenciales en `.env` (está en el repositorio) ni en la imagen
de Docker (la imagen se publica en un registro).

---

## 4. Primer arranque

El orden importa: **migrar antes de abrir el tráfico**.

```bash
# 1. Construir y levantar
docker compose -f compose.yaml -f compose.prod.yaml up -d --build

# 2. Esperar a que la base de datos esté sana
docker compose -f compose.yaml -f compose.prod.yaml ps

# 3. Esquema: las 19 migraciones, en orden
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console doctrine:migrations:migrate --no-interaction

# 4. Caché de producción
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console cache:clear --no-interaction

# 5. Comprobar que el esquema y el mapeo coinciden
docker compose -f compose.yaml -f compose.prod.yaml exec -T php \
    php bin/console doctrine:schema:validate
```

`doctrine:schema:validate` debe decir que el mapeo es correcto **y** que no hay diferencias con la
base de datos. Si dice que hay diferencias, falta una migración: no se arregla con
`doctrine:schema:update` (prohibido en este proyecto), se genera la migración y se aplica.

El primer arranque de Caddy tarda unos segundos en obtener el certificado. Hasta entonces
`https://` no responde; es normal.

---

## 5. Verificación

No se considera desplegado hasta que pasan estas cinco comprobaciones:

```bash
# 1. La aplicación responde
curl -fsS https://suscripta.example.com/health          # 200

# 2. El login se sirve y la ayuda de demo NO aparece (es solo de dev)
curl -fsS https://suscripta.example.com/login | grep -c "bo-demo-fill"   # 0

# 3. El worker está vivo y consumiendo
docker compose -f compose.yaml -f compose.prod.yaml ps worker
docker compose -f compose.yaml -f compose.prod.yaml logs --tail=20 worker

# 4. La API responde con el contrato de errores correcto (401, no 500)
curl -s -o /dev/null -w '%{http_code}\n' https://suscripta.example.com/api/v1/services

# 5. El contrato OpenAPI se publica
curl -fsS https://suscripta.example.com/api/doc.json | head -c 200
```

Y una comprobación de extremo a extremo que vale más que las cinco anteriores: **registrar una
cuenta, conectar un buzón y confirmar una propuesta**. Si eso funciona, funciona todo.

---

## 6. Después del primer despliegue

| Tarea | Cómo | Cuándo |
|---|---|---|
| Copias de seguridad | `bin/backup.sh` en `cron` | Diaria, antes de la primera migración |
| Verificar la restauración | [operations.md §4](operations.md#4-restauración) | Una vez, y cada vez que cambie el procedimiento |
| Vigilancia | [operations.md §5](operations.md#5-vigilancia) | Desde el primer día |
| Rotación de secretos | [operations.md §7](operations.md#7-rotación-de-secretos) | Según política |
| Revisar `var/log/prod.log` | `docker compose logs php` | Primera semana, a diario |

Una copia que nunca se ha restaurado no es una copia: es una carpeta con ficheros dentro.

---

## 7. Errores que se cometen la primera vez

| Síntoma | Causa | Arreglo |
|---|---|---|
| El contenedor arranca y muere en bucle | `API_CORS_ALLOWED_ORIGINS` con comillas dobles → JSON inválido | Poner comillas simples y `debug:dotenv` |
| `500` en todas las páginas tras desplegar | `APP_SECRET` cambiado sin `cache:clear` | `cache:clear` y reiniciar `php` y `worker` |
| La cola crece y nada se procesa | El worker no se reinició, o `MESSENGER_TRANSPORT_DSN` apunta a otro transporte | `docker compose ... restart worker` |
| `doctrine:schema:validate` detecta diferencias | Migración pendiente | `make migration` y aplicarla; nunca `schema:update` |
| El certificado no se emite | Puertos 80/443 cerrados, o DNS aún sin propagar | Abrir puertos y esperar al DNS |
| La API devuelve `401` con una clave que «funcionaba» | Se cambió `APP_SECRET` sin haber fijado `API_KEY_PEPPER` | Fijar `API_KEY_PEPPER` y reemitir las claves |
| Las peticiones del navegador a la API fallan por CORS | El origen no está en la lista blanca | Añadirlo a `API_CORS_ALLOWED_ORIGINS` y reiniciar |
