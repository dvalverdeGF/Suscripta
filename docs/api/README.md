# API de Suscripta

La API pública de Suscripta. Sirve para que **otro programa** consuma los datos y las acciones
del producto: un ERP, un panel de control, un bot, un script de shell o una web.

- **Base:** `/api/v1` (toda la API está versionada en la URL).
- **Formato:** JSON. Los errores son `application/problem+json` (RFC 9457).
- **Autenticación:** clave de API por organización, emitida desde el panel de integraciones.
- **Contrato machine-readable:** [`/api/doc.json`](http://localhost/api/doc.json) (OpenAPI 3) y
  [`/api/doc`](http://localhost/api/doc) (Swagger UI). No requieren credencial.

> **Estado:** la API es **v1 estable**. Las correcciones de bugs entran sin romper el
> contrato; los cambios incompatibles entran en `/api/v2`. Ver [`versioning.md`](versioning.md).

---

## 1. De cero a la primera llamada

### 1.1 Pedir una clave

1. Entra en el panel de Suscripta → **Integraciones** → **Claves de API**.
2. «Nueva clave». Ponle un nombre reconocible («ERP de la oficina») y elige los ámbitos:
   - `read`: solo leer.
   - `write`: leer **y** escribir. `write` cubre `read`, así que no hace falta marcar las dos.
3. Opcionalmente, una fecha de caducidad.
4. La clave completa **se muestra una sola vez**. Cópiala: el servidor guarda solo una huella y
   no puede volver a enseñártela.

Si la pierdes, no hay forma de recuperarla: revoca esa clave y emite otra.

### 1.2 Probarla

```bash
curl -H "Authorization: Bearer sk_TU_CLAVE_AQUI" \
     https://tu-suscripta.ejemplo/api/v1/plan
```

Si tu plataforma borra la cabecera `Authorization` (algunos proxies y gateways), usa la
alternativa:

```bash
curl -H "X-Api-Key: sk_TU_CLAVE_AQUI" \
     https://tu-suscripta.ejemplo/api/v1/plan
```

Los dos funcionan y son equivalentes.

### 1.3 Entender la respuesta

Una colección:

```json
{
  "data": [ { "id": "…", "name": "Adobe Creative Cloud", "…": "…" } ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 3,
    "total_pages": 1,
    "has_next": false,
    "has_previous": false
  }
}
```

Un recurso:

```json
{ "data": { "id": "…", "name": "Adobe Creative Cloud", "…": "…" } }
```

`data` siempre está. `meta` solo en las colecciones. Los nombres de campo van en `snake_case`.

### 1.4 Paginar

Todas las listas aceptan `?page=` y `?per_page=`:

```bash
curl -H "Authorization: Bearer sk_…" \
     "https://tu-suscripta.ejemplo/api/v1/services?page=2&per_page=50"
```

- `per_page` por defecto: **25**. Máximo: **100**. Si pides más, se recorta a 100 (no es un
  error).
- Pide siempre la siguiente página usando `meta.has_next`, no calculando tú el total.

### 1.5 Escribir

```bash
curl -X POST -H "Authorization: Bearer sk_…" \
     -H "Content-Type: application/json" \
     -d '{"name": "Adobe Creative Cloud", "amount": "12.50", "currency": "EUR", "billing_cycle": "monthly"}' \
     https://tu-suscripta.ejemplo/api/v1/services
```

**Los importes van como texto**, `"12.50"`, no como número. Es deliberado: un número de coma
flotante no puede representar `12.50` de forma exacta en binario, y convertirlo a euros desde
ahí acumula errores de redondeo. Si mandas `12.5` (número JSON) recibirás un `422`: la API lo
rechaza a propósito.

---

## 2. Catálogo de endpoints

Todos requieren `read` salvo los marcados como `write`.

### Plan y consumo

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/plan` | `read` | Plan de la organización y consumo frente a los límites. |

### Servicios

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/services` | `read` | Listado de servicios. |
| `GET` | `/api/v1/services/{id}` | `read` | Un servicio. |
| `POST` | `/api/v1/services` | `write` | Alta manual de un servicio. |

### Propuestas

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/proposals` | `read` | Propuestas pendientes. |
| `POST` | `/api/v1/proposals/{id}/confirm` | `write` | Confirma una propuesta y crea el servicio. |

### Documentos

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/documents` | `read` | Documentos procesados. |
| `POST` | `/api/v1/documents` | `write` | Carga de un documento. |

### Cobros y avisos

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/charges/upcoming` | `read` | Próximos cobros. |
| `GET` | `/api/v1/alerts` | `read` | Avisos de renovación y de presupuesto. |

### Correo

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/mail/accounts` | `read` | Cuentas de correo conectadas y su estado. |
| `POST` | `/api/v1/mail/accounts/{id}/sync` | `write` | Fuerza una sincronización. |

### Métricas

| Método | Ruta | Ámbito | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/metrics` | `read` | Métricas del pipeline y coste de IA. |

---

## 3. Errores

Todos los errores son `application/problem+json`:

```json
{
  "type": "https://suscripta.app/errors/forbidden",
  "title": "Acceso prohibido",
  "status": 403,
  "detail": "La clave no tiene permiso para esta operación."
}
```

El catálogo completo, con qué hacer en cada caso, está en [`errors.md`](errors.md).

---

## 4. Límites

- **300 peticiones por minuto y clave.** Al superarlo: `429` con `retry_after` (segundos) y la
  cabecera `Retry-After`. El límite es **por clave**, no por IP: si varias claves legítimas
  salen del mismo servidor (NAT, proxy, CI), un límite por IP castigaría a un cliente por el
  comportamiento de otro.
- **Los límites del plan** (número de servicios, buzones, documentos, presupuesto de IA)
  también aplican a la API. Al superarlos: `402`, y el cuerpo dice qué capacidad, cuál era el
  límite y cuánto se ha usado. La API y el backoffice **comparten contador**.

---

## 5. CORS

Si integras desde un navegador, el origen tiene que estar autorizado por el servidor. El
preflight (`OPTIONS`) debe devolver `204` con `Access-Control-Allow-Origin`.

Por defecto **no se permite ningún origen**. La lista se configura en el servidor:

```dotenv
API_CORS_ALLOWED_ORIGINS=["https://tu-app.ejemplo","https://otra.ejemplo"]
```

Es una lista JSON, no un texto. Si tu origen no está en la lista, el navegador bloqueará la
respuesta aunque el servidor devuelva `200`.

---

## 6. Seguridad

- La clave **no** lleva a ninguna parte salvo en las cabeceras. No la pongas en la URL, ni en
  los logs, ni en un repositorio.
- Cámbiala si se filtra: revoca y emite otra.
- Rota el `API_KEY_PEPPER` con el procedimiento de [`operations.md`](../operations.md) y
  [`deprecation.md`](deprecation.md).
- Cada petición escribe la última utilización de la clave, así que en el panel puedes ver
  qué integraciones están vivas y cuáles no.

---

## 7. Documentos relacionados

| Documento | Contenido |
|---|---|
| [`errors.md`](errors.md) | Catálogo de errores: cada código, cuándo ocurre, qué hacer. |
| [`versioning.md`](versioning.md) | Qué es la versión estable y qué garantiza. |
| [`deprecation.md`](deprecation.md) | Política de deprecación: cómo y cuándo se retira algo. |
| [`../ARCHITECTURE.md` §17](../ARCHITECTURE.md) | Cómo está construida la API por dentro. |
| [`../DECISIONS.md`](../DECISIONS.md) D-71, D-72 | Por qué HMAC y por qué read models. |
| [`../ROADMAP.md`](../ROADMAP.md) | Estado del proyecto y qué está pendiente. |
