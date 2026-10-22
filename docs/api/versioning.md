# Versionado y versión estable

## Qué es «v1 estable»

La API se publica bajo `/api/v1` y su número de versión mayor es **1**. «Estable» significa
exactamente esto:

> Mientras la ruta siga siendo `/api/v1`, **ningún cambio incompatible se publicará**. Los
> cambios que sí pueden aparecer son correcciones de bugs, campos nuevos, campos opcionales y
> mejoras de rendimiento.

**Lo que sí puede cambiar sin avisar:**

- **Campos nuevos** en las respuestas. Tu cliente debe **ignorar los campos que no conoce**.
  Es la razón por la que los importes van como texto: un número de coma flotante en el
  contrato sería un decimal que algún día saldría `12.499999999`.
- **Valores nuevos dentro de un enum** (un `billing_cycle` nuevo, un `status` nuevo). Un
  cliente debe tratar lo desconocido como «no lo sé» y no como un error.
- **Correcciones de bugs** en la semántica de un campo, siempre en el sentido más
  fiel al significado documentado.

**Lo que NO cambia dentro de `/api/v1`:**

- El nombre o el tipo de un campo existente.
- La forma de la respuesta (`data` / `meta`).
- Los códigos de error ni su significado.
- La semántica de un parámetro de query.
- Los campos obligatorios de un cuerpo de entrada.

## Cómo sé si algo es incompatible

Una regla práctica para tu cliente:

| Cambio | ¿Incompatible? |
|---|---|
| Un campo nuevo aparece en una respuesta | No |
| Un campo opcional nuevo en un cuerpo de entrada | No |
| Un valor nuevo en un enum | No |
| Un campo existente cambia de nombre o de tipo | **Sí** |
| Un campo existente deja de ser opcional | **Sí** |
| Un campo existente desaparece | **Sí** |
| Un código de error cambia de significado | **Sí** |
| Aparece un campo obligatorio en el cuerpo de entrada | **Sí** |

Si tu cliente **falla** cuando ve un campo que no conoce, no es un problema del servidor: es
un cliente que no cumple la regla de "ignorar lo desconocido". Arregla el cliente.

## Cómo se anuncia un cambio incompatible

1. **Se publica en `/api/v2`.** La versión anterior (`/api/v1`) sigue funcionando **entera**
   durante el periodo de aviso.
2. **Se avisa en el `CHANGELOG.md`** del repositorio y en la respuesta de la API: las rutas
   deprecadas devuelven la cabecera `Deprecation: true` y `Sunset: <fecha HTTP>`.
3. **El aviso dura 6 meses mínimo.** Ese es el periodo que se compromete la política de
   [deprecación](deprecation.md).
4. **Pasado el aviso, `/api/v1` deja de existir** y las llamadas reciben un `404` con un
   `detail` que dice a dónde ir.

## Cómo se anuncia un cambio compatible

- Se publica en el `CHANGELOG.md`, bajo «Cambios compatibles».
- Los campos nuevos **no** se anuncian por otro canal: es la razón de la regla de "ignora lo
  desconocido".

## El número de versión

El OpenAPI declara la versión del **contrato** en `info.version` (actualmente `1.0.0`).

Esa versión es **independiente** de la versión del producto. Un parche de seguridad de Suscripta
no cambia `info.version`: el contrato no ha cambiado. Y una versión mayor del producto tampoco
cambia la URL: mientras `/api/v1` signifique lo mismo, `/api/v1` sigue siendo la ruta.

Esto significa que un cliente debe **fijar la ruta**, no la versión del producto.

## Garantías de una versión mayor

Cuando aparece `/api/v2`:

- `/api/v1` sigue existiendo durante el periodo de aviso.
- `/api/v2` no es «la v1 con cosas nuevas»: es un contrato **revisado**, y puede tener un
  formato de respuesta distinto.
- Los clientes existentes que no se migren **siguen funcionando** contra `/api/v1` hasta que
  el periodo de aviso termine.

## Roman el contrato

Si tu integración depende de un comportamiento concreto (por ejemplo, que
`/api/v1/services/{id}` devuelva `404` y no `403` cuando el recurso es de otra organización),
**escríbelo en un test de tu lado**. El comportamiento está documentado y es estable dentro de
la versión, pero un test es la forma de saber que ha cambiado: es un fallo de tu suite, no una
sorpresa en producción.
