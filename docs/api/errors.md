# Catálogo de errores

Todos los errores de la API usan el formato **`application/problem+json`** ([RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)).
El cuerpo tiene siempre estos campos:

```json
{
  "type": "https://suscripta.app/errors/forbidden",
  "title": "Acceso prohibido",
  "status": 403,
  "detail": "La clave no tiene permiso para esta operación."
}
```

| Campo                          | Significado                                                                         |
| ------------------------------ | ----------------------------------------------------------------------------------- |
| `type`                         | URI estable del tipo de error. Se puede usar en un `switch` de tu cliente.          |
| `title`                        | Resumen corto y **traducido al español**. No lo uses para lógica: es para personas. |
| `status`                       | El código HTTP, repetido dentro del cuerpo.                                         |
| `detail`                       | Explicación concreta de **esta** petición.                                          |
| `violations`                   | Solo en `422`: lista de campos que fallan.                                          |
| `retry_after`                  | Solo en `429`: segundos que hay que esperar.                                        |
| `capability`, `limit`, `usage` | Solo en `402`: qué límite del plan se ha superado.                                  |

**Regla práctica:** decide con el **código HTTP** o con `type`, nunca con `detail` ni con
`title`. Esos dos campos son para personas y pueden cambiar sin avisar.

---

## 400 — Petición malformada

El cuerpo no se ha podido interpretar.

```json
{ "type": "…/bad-request", "title": "Petición incorrecta", "status": 400, "detail": "…" }
```

**Qué hacer:** corrige el JSON que envías. Suele ser una coma que falta o un tipo de dato
incorrecto.

---

## 401 — Credencial ausente o inválida

```json
{
  "type": "…/unauthorized",
  "title": "No autorizado",
  "status": 401,
  "detail": "Falta la credencial o no es válida."
}
```

**Causas posibles:**

- No mandas ninguna credencial.
- La clave es incorrecta o está mal copiada.
- La clave **caducó** (`expires_at`).
- La clave está **revocada** (`revoked_at`).
- Estás usando `Authorization` con un esquema que no es `Bearer`.

**Qué hacer:** comprueba que la cabecera es exactamente `Authorization: Bearer sk_…` (o
`X-Api-Key: sk_…`) y que la clave sigue vigente en el panel.

> **El mensaje es siempre el mismo** a propósito. Si el `401` distinguiera «no existe» de
> «está revocada», sería un oráculo para enumerar credenciales ajenas. Si tu clave ha caducado o
> la has revocado, míralo en el panel.

**Más probable en un navegador:** el preflight `OPTIONS` no lleva credenciales y el servidor
responde `401` en lugar de `204`. Si ves `401` en un `OPTIONS`, el problema es la
configuración de CORS del servidor, no tu clave.

---

## 403 — Ámbito insuficiente

```json
{
  "type": "…/forbidden",
  "title": "Acceso prohibido",
  "status": 403,
  "detail": "La clave no tiene permiso para esta operación."
}
```

**Qué hacer:** la clave es válida pero no tiene el ámbito necesario. Las de `read` no pueden
escribir; necesitas una clave `write`. Se emite desde el panel de integraciones.

---

## 404 — Recurso no encontrado

```json
{
  "type": "…/not-found",
  "title": "No encontrado",
  "status": 404,
  "detail": "El recurso no existe."
}
```

**Qué hacer:** comprueba el UUID. Si el recurso es de otra organización, **también** recibes
`404`: la API no revela la existencia de datos ajenos. Un `404` no distingue «no existe» de
«no es tuyo», y es intencionado.

---

## 405 — Método no permitido

```json
{
  "type": "…/method-not-allowed",
  "title": "Método no permitido",
  "status": 405,
  "detail": "Ese método no está permitido en esta ruta."
}
```

**Qué hacer:** mira la tabla de endpoints. Cada ruta declara sus métodos.

> En un navegador, un `405` en un `OPTIONS` significa que **el origen no está autorizado por
> CORS**: el servidor no tiene esa ruta `OPTIONS` y el preflight no se contesta. Es el
> equivalente en API de un `401` en preflight. Revisa `API_CORS_ALLOWED_ORIGINS` en el servidor.

---

## 402 — Límite del plan superado

```json
{
  "type": "…/payment-required",
  "title": "Se requiere un plan superior",
  "status": 402,
  "detail": "Has superado el límite de servicios del plan actual.",
  "capability": "services",
  "limit": 3,
  "usage": 3
}
```

**Qué hacer:** el plan de la organización ha llegado a su techo. Se cambia desde el panel
(operador) o llamando a `app:plan:set`. `capability` te dice cuál fue
(`services`, `email_accounts`, `documents`, `ai_budget`).

**La API y el backoffice comparten contador:** un integrador puede agotar el límite del plan de
una organización que también está usando el panel.

---

## 409 — Conflicto de estado

```json
{ "type": "…/conflict", "title": "Conflicto", "status": 409, "detail": "…" }
```

**Qué hacer:** el recurso ya está en el estado destino. Por ejemplo, confirmar una propuesta
que ya fue confirmada. **Relee el recurso** (`GET`) antes de reintentar: el objetivo puede
haberse alcanzado.

---

## 422 — El cuerpo no es válido

```json
{
  "type": "…/unprocessable",
  "title": "Entidad no procesable",
  "status": 422,
  "detail": "…",
  "violations": [
    { "property_path": "name", "message": "Este valor no debe ser vacío." },
    { "property_path": "amount", "message": "Este valor no es un número válido." }
  ]
}
```

**Qué hacer:** `violations` te dice **qué campo** corregir. `property_path` es la ruta del
campo en el cuerpo; `message` explica el motivo, en español.

**Causas frecuentes:**

- **Importes como número.** Los importes van **como texto**: `"12.50"`. Mandar `12.5` (número
  JSON) da `422`. Es deliberado (los importes en coma flotante no son exactos).
- Campo obligatorio vacío o ausente.
- Un enum con un valor que no existe (por ejemplo, `billing_cycle: "quincenal"`).

**Los mensajes están en español**, porque el producto está en español. No los compares con
cadenas literales: usa `property_path`.

---

## 429 — Demasiadas peticiones

```json
{
  "type": "…/too-many-requests",
  "title": "Demasiadas peticiones",
  "status": 429,
  "detail": "Has superado el límite de 300 peticiones por minuto.",
  "retry_after": 42
}
```

También viene la cabecera `Retry-After: 42`.

**Qué hacer:** espera `retry_after` segundos y reintenta. Con un margen: una espera exacta
vuelve a chocar con la siguiente ventana. Si el volumen es alto de verdad, pide varias claves
por integración… pero recuerda que el límite es **por clave**, así que varias claves sí
reparten el límite.

---

## 500 — Error inesperado

```json
{
  "type": "…/internal-error",
  "title": "Error interno",
  "status": 500,
  "detail": "Se ha producido un error inesperado."
}
```

**Qué hacer:** reintenta con espera. Si persiste, es un fallo nuestro: repórtalo indicando la
ruta, la hora y el `type`, pero **no** la clave.

El `detail` **nunca** filtra información interna (peticiones SQL, rutas del servidor, trazas).

---

## Resumen rápido

| Código | Significado                                   | Reintentar                     |
| ------ | --------------------------------------------- | ------------------------------ |
| `400`  | Cuerpo malformado                             | No, corrige la petición        |
| `401`  | Credencial ausente/inválida/caducada/revocada | No, revisa la clave            |
| `403`  | Ámbito insuficiente                           | No, pide una clave `write`     |
| `404`  | No existe (o no es tuyo)                      | No                             |
| `405`  | Método no permitido                           | No, y en `OPTIONS` revisa CORS |
| `402`  | Límite del plan superado                      | No, cambia el plan             |
| `409`  | Conflicto de estado                           | No, relee el recurso           |
| `422`  | Validación fallida                            | No, lee `violations`           |
| `429`  | Límite de frecuencia                          | **Sí**, tras `retry_after`     |
| `500`  | Error del servidor                            | **Sí**, con espera             |
