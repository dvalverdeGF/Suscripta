# Webhooks de salida

> Estado: **Fase 20, entregada**. Los webhooks son **de salida**: Suscripta llama a tu
> servidor cuando pasa algo en la organización. Para que tú llames a Suscripta, la API REST
> de [`README.md`](README.md) sigue siendo el camino.

## Qué problema resuelven

La API se consulta. Un integrador que quiere reaccionar a «ha aparecido un cobro nuevo» tiene
que preguntar cada minuto si ha cambiado algo: gasto de cuota, latencia y una ventana de
indefinición entre el hecho y su descubrimiento. Un webhook invierte eso: Suscripta te avisa
cuando ocurre, con el hecho ya resuelto y firmado.

## Contrato de la entrega

Una petición `POST` a la URL que hayas configurado:

```http
POST /hooks/suscripta HTTP/1.1
Content-Type: application/json
User-Agent: Suscripta-Webhooks/1.0
X-Suscripta-Event: service.created
X-Suscripta-Signature: t=1759000000,v1=5f2c…b9
Idempotency-Key: 0192f0c4-6b1e-7d2a-9f3c-0a1b2c3d4e5f

{"id":"0192f0c4-…","event":"service.created","occurredAt":"2026-10-28T21:50:37+00:00","data":{…}}
```

| Cabecera | Para qué |
|---|---|
| `Content-Type` | Siempre `application/json`. |
| `User-Agent` | `Suscripta-Webhooks/1.0`. Sirve para reconocer el tráfico en tus registros. |
| `X-Suscripta-Event` | El tipo de evento. Permite enrutar sin deserializar el cuerpo. |
| `X-Suscripta-Signature` | Firma HMAC de `"{t}.{cuerpo}"`. Ver más abajo. |
| `Idempotency-Key` | Identificador único de la entrega. **Es el mismo en todos los reintentos.** |

### El sobre

Todos los eventos comparten la misma forma:

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | string (UUIDv7) | Identificador de **la entrega**, no del recurso. Cada suscriptor recibe el suyo. Coincide con `Idempotency-Key`. |
| `event` | string | Uno de los tipos de la tabla de eventos. |
| `occurredAt` | string (RFC 3339) | Cuándo ocurrió el hecho, en UTC. |
| `data` | object | Carga propia del evento. |

`data` **crece con el tiempo**: se pueden añadir campos, nunca se quitan ni se cambia su
significado. Ignora lo que no conozcas.

### Eventos

| `event` | Cuándo se emite | `data` |
|---|---|---|
| `service.created` | Se da de alta un servicio, a mano o confirmando una propuesta | `serviceId`, `name`, `billingPeriod`, `currency`, `source`, `startedAt` |
| `proposal.created` | El procesamiento de un correo crea una propuesta nueva | `discoveryId`, `type`, `providerName`, `planName`, `amountMinor`, `currency`, `matchScore`, `confidence` |
| `proposal.confirmed` | Se confirma una propuesta | `discoveryId`, `type`, `serviceId`, `serviceName`, `matchedExistingService` |
| `charge.detected` | Al confirmar una propuesta se detecta un cobro con importe | `serviceId`, `serviceName`, `amountMinor`, `currency`, `billingPeriod`, `source` |
| `ai.budget_exhausted` | La organización agota su presupuesto de IA | `organizationId`, `budgetMinor`, `spentMinor`, `periodStart`, `periodEnd` |

Los importes van en **unidades menores** (céntimos) y con la moneda aparte, igual que en la API
REST. Nunca en coma flotante.

`proposal.created` **no** se emite cuando la propuesta es un duplicado de una ya existente: en
ese camino no hay hecho nuevo que anunciar.

## Verificar la firma

Se firma `"{timestamp}.{cuerpo}"` con HMAC-SHA-256 y el secreto del suscriptor. El resultado
viaja como `t=<epoch>,v1=<hex>`. El timestamp va **dentro** de lo firmado: si solo se firmara el
cuerpo, una petición capturada valdría para siempre.

Los tres pasos, en este orden:

1. Recalcular el HMAC sobre la cadena `"{t}.{cuerpo}"` **tal cual llegó**, sin reserializar el
   JSON. Reserializar cambia espacios y orden de claves y rompe la firma.
2. Comparar en **tiempo constante** (`hash_equals`, `hmac.compare_digest`,
   `crypto.timingSafeEqual`). Nunca con `===` ni con `==`.
3. Rechazar si `t` se aleja más de **300 segundos** de tu reloj. La tolerancia es la misma que
   usa Suscripta al verificar.

### PHP

```php
$raw = file_get_contents('php://input');
$secret = getenv('SUSCRIPTA_WEBHOOK_SECRET');

[$t, $v1] = [null, null];
foreach (explode(',', $_SERVER['HTTP_X_SUSCRIPTA_SIGNATURE'] ?? '') as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
    if ('t' === $k) { $t = (int) $v; }
    if ('v1' === $k) { $v1 = $v; }
}

if (null === $t || null === $v1 || abs(time() - $t) > 300) {
    http_response_code(400);
    exit;
}

$expected = hash_hmac('sha256', $t.'.'.$raw, $secret);

if (!hash_equals($expected, $v1)) {
    http_response_code(401);
    exit;
}
```

### Python (Flask)

```python
import hashlib, hmac, time
from flask import request, abort

@app.post("/hooks/suscripta")
def suscripta():
    raw = request.get_data()  # bytes exactos, sin reserializar
    header = request.headers.get("X-Suscripta-Signature", "")

    parts = dict(part.split("=", 1) for part in header.split(",") if "=" in part)
    t, v1 = parts.get("t"), parts.get("v1")

    if not t or not v1 or abs(time.time() - int(t)) > 300:
        abort(400)

    expected = hmac.new(
        SECRET.encode(), f"{t}.".encode() + raw, hashlib.sha256
    ).hexdigest()

    if not hmac.compare_digest(expected, v1):
        abort(401)

    event = request.get_json()["event"]
    if event == "charge.detected":
        ...  # tu lógica

    return "", 204
```

### Node.js (Express, sin dependencias)

```js
const crypto = require('node:crypto');

app.post('/hooks/suscripta', express.raw({ type: 'application/json' }), (req, res) => {
  const header = req.get('X-Suscripta-Signature') ?? '';
  const parts = Object.fromEntries(header.split(',').map((p) => p.split('=')));
  const t = Number(parts.t);

  if (!Number.isFinite(t) || Math.abs(Date.now() / 1000 - t) > 300) return res.sendStatus(400);

  const expected = crypto
    .createHmac('sha256', process.env.SUSCRIPTA_WEBHOOK_SECRET)
    .update(`${t}.`)
    .update(req.body)
    .digest('hex');

  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(parts.v1 ?? '', 'utf8');

  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return res.sendStatus(401);

  const event = JSON.parse(req.body.toString()).event;
  res.sendStatus(204);
});
```

En Express, `express.json()` **destruye la firma**: parsea y descarta los bytes originales.
Usa `express.raw()` en esa ruta, como arriba.

## Qué tiene que devolver tu servidor

| Respuesta | Qué significa para Suscripta |
|---|---|
| **Cualquier `2xx`** | Entrega correcta. No se reintenta. |
| **Cualquier otro código**, un error de red o un timeout | Fallo. Se reintenta según la política. |
| `410 Gone` | Se trata como un fallo más. Para dejar de recibir, borra o pausa el suscriptor en el panel. |

Se consideran **10 segundos** para responder y **20 segundos** como máximo de la petición
completa. Contesta `2xx` en cuanto hayas validado la firma y guardado el evento: si tu lógica de
negocio tarda, encólala y responde ya. Un `200` que llega a los 30 segundos es un fallo para
Suscripta y un reintento duplicado para ti.

## Reintentos e idempotencia

| Intento | Espera desde el fallo anterior |
|---|---|
| 1 (primer envío) | — |
| 2 | 30 s |
| 3 | 2 min |
| 4 | 10 min |
| 5 | 1 h |
| 6 | 6 h |
| 7 | 24 h |
| 8 (último) | 24 h |

Después del octavo fallo la entrega se marca como **abandonada** y no se vuelve a intentar. La
secuencia suma algo menos de dos días, de modo que un corte de mantenimiento de una noche se
recupera solo.

Además, una entrega pendiente se abandona si lleva **más de 7 días** esperando: si el proceso
estuvo parado, no tiene sentido reanudar algo de hace una semana.

**Un reintento es el mismo evento, byte a byte.** El cuerpo se serializa una sola vez y se
guarda; el reintento reutiliza esa misma cadena, así que la firma cubre exactamente lo que
recibiste la primera vez y `Idempotency-Key` no cambia. Esto significa que **puedes recibir el
mismo evento más de una vez** (un `200` perdido en la red es indistinguible de un fallo): guarda
las claves vistas y descarta los duplicados.

## Buenas prácticas del receptor

1. **Responde rápido y procesa después.** Encola el evento y contesta `2xx`.
2. **Deduplica por `Idempotency-Key`**, con una ventana de al menos 48 h.
3. **Verifica la firma antes de mirar el cuerpo.** Es lo único que distingue una entrega de
   Suscripta de una petición cualquiera.
4. **No confíes en el orden.** Los reintentos pueden adelantar a un evento posterior del mismo
   suscriptor. Si el orden importa, usa `occurredAt`.
5. **Trata `data` como un objeto abierto.** Los campos se añaden; no falles si aparece uno nuevo.
6. **Devuelve `2xx` solo cuando hayas terminado.** Un `200` optimista pierde el evento para
   siempre.

## Configurar un suscriptor

En el backoffice, **Webhooks → Nuevo suscriptor**:

- **URL**: `https` obligatorio, con dominio real, y que **no apunte a una red interna** (ver
  [«Qué URLs se rechazan»](#qué-urls-se-rechazan) más abajo). No se comprueba que el destino sea
  alcanzable hasta que mandas una prueba: un host que aún no existe se acepta, porque dar de alta
  el receptor antes de desplegarlo es lo normal.
- **Eventos**: al menos uno. Un suscriptor que no escucha nada no se distingue de uno roto.
- **Activo**: un suscriptor pausado no recibe nada, pero conserva su historial.

Al crear el suscriptor se genera un secreto `whsec_…` y se enseña **una sola vez**. Cópialo
entonces; después, verlo exige pulsar «Ver secreto» en su ficha (acción `POST`, no queda en el
historial del navegador).

El botón **«Enviar prueba»** manda una entrega real, firmada de verdad, con
`{"data": {"test": true}}` y el primer evento suscrito, y te enseña el código de respuesta en el
acto. Es la forma de comprobar en diez segundos que tu endpoint valida la firma, en lugar de
descubrirlo cuando ya hay algo que contar.

La ficha muestra el **historial de entregas** (estado, intentos, código de respuesta y último
error) y cuántas quedan pendientes en el listado.

## Qué URLs se rechazan

Suscripta hace el `POST` **desde dentro de su propia red**. Si aceptara cualquier URL, un
administrador de una organización —o cualquiera que robara esa cuenta— podría apuntar un
suscriptor a `https://10.0.0.5/`, a `http://169.254.169.254/` (el servicio de metadatos de la
nube, donde están las credenciales del servidor) o a un `localhost`, y usar los webhooks como un
túnel hacia la red interna. Por eso la URL se comprueba **al darla de alta** (el formulario
explica el problema en el campo) y **en cada entrega** (por si el DNS cambió después).

Se rechaza, con un mensaje que dice qué pasó:

| Caso | Ejemplo | Por qué |
|---|---|---|
| No es `https` | `http://erp.example.com/hooks` | El cuerpo va firmado y con datos de facturación del cliente; en claro lo lee cualquiera. |
| Loopback | `https://localhost/…`, `127.0.0.1`, `::1` | Es la propia máquina de Suscripta. |
| Redes privadas | `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7` | Es la red interna del servidor. |
| Link-local / metadatos | `169.254.169.254`, `fe80::/10` | Credenciales de la nube al alcance de un `GET`. |
| Reservados y de prueba | `100.64.0.0/10`, `198.18.0.0/15`, `203.0.113.0/24`, `240.0.0.0/4`, `ff00::/8`… | No son servicios públicos a los que avisar. |
| IPv4 escondida en IPv6 | `::ffff:10.0.0.1`, `64:ff9b::/96` | La forma habitual de esquivar una comprobación que solo mira IPv4. |

Tres detalles que importan si estás al otro lado:

1. **Se comprueban todas las direcciones del nombre**, no solo la primera. Un host puede devolver
   una pública y una privada a la vez, y quedarse con la primera es cómo se cuela el ataque.
2. **La dirección validada es la que se usa para conectar.** No se valida y luego se vuelve a
   preguntar al DNS: entre las dos consultas cabe un cambio de respuesta (*DNS rebinding*) que
   convierte una URL pública en una privada. La entrega se ata a la IP comprobada.
3. **Las redirecciones no se siguen** (`max_redirects: 0`). Un `302` desde un host público hacia
   `http://169.254.169.254/` sería el mismo ataque con un salto intermedio. Si tu endpoint
   redirige, no es un destino válido para un webhook.

Si tu receptor vive en una red privada y quieres probarlo contra un entorno de desarrollo, la
variable `WEBHOOK_ALLOWED_PRIVATE_HOSTS` (lista separada por comas) permite nombrar hosts
concretos que se saltan la comprobación. Está **vacía** por defecto y solo se rellena en el
entorno de test y en instalaciones autoalojadas que lo necesiten: no es una lista para producción.

## Operación

El envío va por la cola `webhooks` de Messenger, no en la petición del usuario: crear un
servicio no espera a que tu servidor conteste. Hace falta el worker:

```bash
bin/console messenger:consume webhooks --time-limit=3600 --memory-limit=128M
```

El transporte tiene `max_retries: 0` a propósito: la política de reintentos vive en
`RetryPolicy` y es la única que decide. Si Messenger también reintentara, habría dos sistemas
decidiendo a la vez.

Y un mantenimiento periódico, para reencolar lo que venció mientras el worker estaba parado y
abandonar lo que ya caducó:

```bash
bin/console app:webhooks:maintain --dry-run   # ver qué haría
bin/console app:webhooks:maintain --limit=500
```

En producción va en el cron, cada cinco minutos. Ver [`../operations.md`](../operations.md).

## Límites conocidos

- **No se valida que la URL sea alcanzable.** Se comprueba que no apunte a una red interna (ver
  [«Qué URLs se rechazan»](#qué-urls-se-rechazan)), pero no que responda: un host que todavía no
  existe se acepta y las entregas fallan hasta que exista.
- **No hay entrega garantizada.** «Al menos una vez» con deduplicación por parte del receptor,
  no «exactamente una vez».
- **No hay firma de respuesta ni challenge de verificación de endpoint.** El botón de prueba
  cumple esa función.
- **No hay webhooks de entrada.** Stripe, en la fase 24, tendrá su propia ruta de recepción.
