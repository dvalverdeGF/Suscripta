# Política de deprecación

## Principios

1. **No se rompe nada sin avisar.** Un cambio incompatible exige un periodo de aviso mínimo
   de **6 meses** desde que se anuncia hasta que se retira.
2. **La v1 y la v2 coexisten.** Durante el periodo de aviso, las dos versiones funcionan.
   No hay un día en que la v1 deja de funcionar de golpe.
3. **El aviso es visible desde la propia respuesta.** No hay que estar suscrito a un newsletter
   para enterarse: las rutas deprecadas lo dicen en las cabeceras de cada respuesta.
4. **Los bugs se arreglan, no se esconden.** Una corrección de comportamiento en la v1 no
   espera al periodo de aviso, porque no es un cambio incompatible: es un arreglo.

## Qué está deprecado ahora

**Nada.** La API `v1` es la única publicada y no tiene ninguna parte deprecada.

## Cómo se ve una deprecación

Una ruta deprecada sigue funcionando, pero sus respuestas incluyen:

```http
Deprecation: true
Sunset: Tue, 01 Jul 2025 00:00:00 GMT
Link: <https://suscripta.app/docs/api/v2>; rel="successor-version"
```

- `Deprecation: true` — esta ruta o este campo está en retirada.
- `Sunset` — fecha (formato HTTP) en la que dejará de funcionar. **Es un aviso, no un
  incumplimiento**: la fecha puede moverse si se avisa con más antelación.
- `Link` con `rel="successor-version"` — a dónde ir.

En el cuerpo de la respuesta, los **campos** deprecados vienen marcados:

```json
{
  "data": {
    "id": "…",
    "name": "Adobe Creative Cloud",
    "legacy_external_id": "1234",
    "_deprecated": {
      "legacy_external_id": "Se sustituye por `external_id`. Se retirará el 2026-01-01."
    }
  }
}
```

El campo `_deprecated` **es una clave reservada**: no la uses con datos tuyos. Los campos
deprecados se pueden seguir leyendo durante todo el periodo de aviso.

## Cómo se retira algo

El proceso completo, en orden:

1. **Se decide deprecitar.** La decisión se apunta en el `CHANGELOG.md` y en
   `docs/ROADMAP.md`, en la fase correspondiente.
2. **Se marca el código.** La ruta o el campo deja de ser «nuevo» y pasa a «en retirada». Si
   es una ruta, se le añade el `Deprecation: true`; si es un campo, se añade la clave
   `_deprecated`.
3. **Se avisa.** Publicación en el `CHANGELOG.md` con la fecha de retirada prevista. Con
   6 meses de antelación mínima.
4. **Se migra a los integradores.** Los integradores que migran a tiempo no notan nada: el
   campo nuevo ya está disponible desde el principio del periodo de aviso.
5. **Se retira.** Pasada la fecha, el campo desaparece de la respuesta y la ruta deja de
   existir. Quien no haya migrado recibe un `404` con un `detail` que dice a dónde ir.

## Reglas por tipo de cambio

| Tipo de cambio                     | ¿Periodo de aviso? | Cómo se anuncia                                   |
| ---------------------------------- | ------------------ | ------------------------------------------------- |
| Campo nuevo en la respuesta        | No                 | `CHANGELOG.md`                                    |
| Valor nuevo en un enum             | No                 | `CHANGELOG.md`                                    |
| Corrección de bug                  | No                 | `CHANGELOG.md`                                    |
| Campo existente pasa a opcional    | No                 | `CHANGELOG.md`                                    |
| Campo existente pasa a obligatorio | **Sí**             | `_deprecated` + `CHANGELOG.md`                    |
| Campo existente desaparece         | **Sí**             | `_deprecated` + `CHANGELOG.md`                    |
| Ruta nueva                         | No                 | `CHANGELOG.md` + OpenAPI                          |
| Ruta desaparece                    | **Sí**             | Cabeceras `Deprecation`/`Sunset` + `CHANGELOG.md` |
| Cambia el formato de `data`/`meta` | **Sí**             | Versión nueva                                     |
| Cambia el significado de un campo  | **Sí**             | Versión nueva                                     |
| Se retira un código de error       | **Sí**             | `CHANGELOG.md`                                    |

## Campos deprecados que ya no existen

La lista histórica, para saber qué **no** volver a usar, está en el `CHANGELOG.md` del
proyecto. Al día de hoy está vacía: la API `v1` se publicó con el contrato ya cerrado.

## Compatibilidad hacia atrás, dentro de la v1

La regla que manda es una sola:

> **Un cliente bien escrito nunca falla porque el servidor añadió algo.**

Eso significa:

- Ignora los campos que no conoces.
- Trata un valor de enum desconocido como «no lo sé», no como un error.
- No compares `title` ni `detail` con cadenas literales: son texto para personas y pueden
  cambiar. Usa el **código HTTP** o el campo `type`.
- No asumas el orden de las claves de un objeto JSON.

Si un cliente falla al recibir un campo nuevo, el cliente tiene un bug. La API no va a
contradecirlo, porque hacerlo obligaría a cada integrador a estar al tanto de los cambios por un
canal que nadie lee, y a bloquearse a sí mismo para siempre.
