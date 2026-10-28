# Roadmap

> Estado: **fases 0 a 20 cerradas** (1 483 tests, PHPStan nivel 8 limpio). Las fases 21 a 25
> están planificadas y decididas con el usuario, sin empezar; la deuda técnica conocida está
> listada al final. Fases pequeñas y verificables: cada fase termina con algo demostrable y con
> criterios de aceptación explícitos.

## Criterios de ordenación

El orden propuesto en el encargo original se ha ajustado por cuatro motivos:

1. **La vertical end-to-end va antes que la profundidad horizontal.** El producto se valida
   demostrando la cadena completa *conectar correo → encontrar factura → proponer servicio →
   confirmar → ver próximos costes*, no acumulando funcionalidades auxiliares. Esa vertical es
   la **fase 3**, no la fase 10. Ver `PRODUCT.md` §3.2.
2. **El historial de precios es un requisito de modelo de datos, no una funcionalidad tardía.**
   Si el precio se implementa como campo mutable, migrarlo después es costoso. Se implementa
   desde la fase 2, antes de que nada escriba precios automáticamente.
3. **Los documentos deben existir antes que la ingesta de correo completa**, porque los
   adjuntos se convierten en documentos. La vertical de la fase 3 procesa el adjunto **en
   memoria** y solo persiste la propuesta; la entidad `Document` llega en la fase 7.
4. **La detección de recurrencia y el matching se separan de la extracción**, porque son
   problemas distintos (uno determinista, otro con IA) y se pueden verificar por separado.
5. **Las fases 9 a 14 siguen el orden del pipeline de análisis** (`ARCHITECTURE.md` §13), de
   lo más barato y determinista a lo más caro: filtros → extracción determinista → parsers →
   IA → validación → matching → aprendizaje → control de coste. **Nunca se implementa un
   nivel caro antes que el barato que lo hace innecesario la mayor parte del tiempo.**

**IMAP no es una funcionalidad experimental de última fase.** Aparece en la fase 3 en su
versión mínima (conectar, leer, encontrar una factura) y se endurece en la fase 8. La
arquitectura lo contempla desde el principio (`ARCHITECTURE.md` §4.10, `DECISIONS.md` D-23).

**La IA no es una funcionalidad de última fase, pero tampoco temprana.** Llega en la fase 11,
**después** de que existan filtros, extracción determinista y conocimiento de proveedores,
porque sin ellos la IA sería el único mecanismo y el producto no sería viable ni económica ni
legalmente. El control de coste (fase 14) es requisito para operar con IA en producción.

## Estado global

Una tabla para saber, de un vistazo, qué está hecho y qué falta. El detalle de cada fase está
en su apartado correspondiente.

### Mapa de la documentación

| Documento | Para qué |
|---|---|
| [`PRODUCT.md`](PRODUCT.md) | Qué es el producto, para quién y qué problema resuelve. |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Cómo está construido, módulo por módulo. §17 = la API, §18 = los webhooks. |
| [`DECISIONS.md`](DECISIONS.md) | Por qué se hizo así. D-71 y D-72 = la API; D-73 a D-76 = los webhooks; D-77 = la guarda anti-SSRF. |
| [`ROADMAP.md`](ROADMAP.md) | Este documento: qué está hecho y qué falta. |
| [`SECURITY.md`](SECURITY.md) | Modelo de amenazas y medidas. |
| [`operations.md`](operations.md) | Runbook del día a día: desplegar, copiar, restaurar, vigilar. |
| [`deployment.md`](deployment.md) | El **primer** despliegue en producción, paso a paso. |
| [`../CHANGELOG.md`](../CHANGELOG.md) | Qué cambió en cada fase (Keep a Changelog). |
| [`operations.md`](operations.md) | Runbook: despliegue, backups, restauración, incidentes. |
| [`api/README.md`](api/README.md) | **Guía de integración de la API**, de cero a la primera llamada. |
| [`api/errors.md`](api/errors.md) | Catálogo de errores: cada código, cuándo y qué hacer. |
| [`api/versioning.md`](api/versioning.md) | Qué es la versión estable y qué garantiza. |
| [`api/webhooks.md`](api/webhooks.md) | **Webhooks de salida**: contrato, firma, reintentos e idempotencia. |
| [`api/deprecation.md`](api/deprecation.md) | Política de deprecación. |
| [`LEARNING.md`](LEARNING.md) | Lo aprendido en cada fase, para no repetir errores. |
| [`agents.md`](agents.md) | Cómo trabajan los agentes de IA en este proyecto. |
| [`COMPETITORS.md`](COMPETITORS.md) | Alternativas del mercado y cómo se diferencia esto. |
| [`PROMPT.md`](PROMPT.md) | El encargo original, para contexto. |

### Tabla de fases

| Fase | Nombre | Estado | Commit | Verificación al cerrar |
|---|---|---|---|---|
| 0 | Base del proyecto | ✅ Completada | `c0918df` | Suite + CI + `schema:validate` en verde |
| 1 | Identidad y multi-tenancy | ✅ Completada | `cd1bed8` | Registro, verificación, aislamiento por organización |
| 2 | Servicios manuales (mínimo del dominio) | ✅ Completada | `56cef50` | Alta, edición y baja de servicios |
| 3 | Vertical end-to-end (walking skeleton) ⭐ | ✅ Completada | `1237f46` | *conectar correo → factura → propuesta → confirmar → costes* |
| 4 | Dashboard completo | ✅ Completada | `9f07abd` | Costes por categoría, ranking, ventanas de cobro |
| 5 | Historial de precios y cambios | ✅ Completada | `180dc7f` | Variaciones y evolución del gasto |
| 6 | Renovaciones, alertas y notificaciones | ✅ Completada | `2d7cb11` | Avisos, canales y preferencias |
| 7 | Documentos | ✅ Completada | `4957339` | Carga, deduplicación, almacenamiento |
| 8 | Conexión de correo completa (IMAP y reenvío) | ✅ Completada | `373032d` | Sincronización incremental, multi-proveedor |
| 9 | Pipeline: ingesta, metadatos, filtros y billing score | ✅ Completada | `926896d` | Niveles 0–2 con métricas |
| 10 | Extracción determinista y conocimiento de proveedores | ✅ Completada | `3fd535c` | Niveles 3–4 sin IA |
| 11 | IA económica y avanzada con validación | ✅ Completada | `3c08714` | Niveles 5–7 con panel de integraciones |
| 12 | Service matching y descubrimientos | ✅ Completada | `920fc0e` | Emparejamiento, recurrencia, deduplicación |
| 13 | Aprendizaje del buzón | ✅ Completada | `9f2dfee` | Perfil del buzón y aprendizaje |
| 14 | Control de coste de IA y observabilidad | ✅ Completada | `d7a13bc` | Microcéntimos y límites por organización |
| 15 | Detección de cambios | ✅ Completada | `1db3473` | Hechos e inferencias con confianza |
| 16 | Hardening de seguridad y RGPD | ✅ Completada | `a035a36` | Retención, privacidad, exportación y borrado |
| 17 | Preparación SaaS | ✅ Completada | `9504ff6` | 1385 tests, planes, cuotas, onboarding, backups |
| 18 | API pública | ✅ Completada | `d840174` + `ffa7127` | 15 casos E2E y **35 tests** de API en verde; 6 defectos corregidos |
| 19 | Cierre de documentación y publicación | ✅ Completada | `5821de0` | README de producto, CHANGELOG por fase, guía de despliegue, 1 420 tests |
| 20 | Webhooks de salida | ✅ Completada | `bd646a8` | 29 tests nuevos; firma HMAC, 8 envíos, panel con prueba |
| 21 | `Subscription` como entidad propia | ⬜ Planificada | — | Servicios y suscripciones como listas distintas |
| 22 | Catálogo comercial en el backoffice | ⬜ Planificada | — | Un plan con dos precios publicado sin desplegar |
| 23 | Web corporativa (11 páginas) | ⬜ Planificada | — | `/` pública sin sesión, precios desde el catálogo |
| 24 | Checkout con Stripe | ⬜ Planificada | — | Alta, cobro en modo test, webhook y estado reflejado |
| 25 | CSRF unificado con el gestor de sesión | ⬜ Planificada | — | Ningún `POST` con token sin estado |

**Lectura rápida de la tabla:** las fases 0 a 20 están **cerradas, commiteadas y verificadas**.
La fase 18 se cerró corrigiendo los seis defectos que aparecieron al verificarla —dos de
comportamiento en la API, uno de arranque en el entorno, uno de contrato y dos que impedían
usar el panel de claves—, todos con test. La fase 19 cerró la documentación de entrega: el
README de producto, el CHANGELOG por fase y la guía del primer despliegue.

La fase 20 cerró los webhooks de salida, que eran lo único que la fase 19 había dejado como
«idea documentada». **Quedan cinco fases planificadas** (21 a 25) y están todas decididas con el
usuario: no hay ninguna pregunta abierta que bloquee el trabajo, solo trabajo. Lo único diferido
conscientemente de la fase 19 son las **capturas de pantalla** del README: no se ha añadido
ningún binario al repositorio y se prefiere que envejezcan mal fuera de él antes que versionar
imágenes que se quedan obsoletas a la primera fase.

## Fase 0 — Base del proyecto

> **Estado:** ✅ **Completada.**


**Objetivo:** proyecto Symfony arrancando sobre la infraestructura existente.

- Instalar Symfony 8 skeleton sobre la plantilla actual (`composer create-project`).
- Doctrine ORM + Migrations, conexión a PostgreSQL.
- Symfony Messenger con transporte Doctrine y transporte `failed`.
- Symfony Security, Validator, UID, Twig.
- Frontend: **AssetMapper**, **Turbo**, **Stimulus** y **Mercure** (ya incluido en la plantilla
  FrankenPHP). Sin Node ni `npm`. Ver `ARCHITECTURE.md` §12.
- Herramientas de calidad: PHPUnit, PHPStan (nivel alto), PHP-CS-Fixer, Rector (opcional).
- CI: activar los pasos comentados de `.github/workflows/ci.yaml` (base de datos de test,
  migraciones, PHPUnit, `doctrine:schema:validate`).
- Estructura de módulos vacía (`src/Shared`, `src/Identity`, …).

**Verificación:** `docker compose up --wait` levanta la app; `bin/phpunit` pasa; CI en verde;
`schema:validate` sin errores.

**Fuera de alcance:** cualquier entidad de negocio.

## Fase 1 — Identidad y multi-tenancy

> **Estado:** ✅ **Completada.**


**Objetivo:** usuarios, organizaciones y aislamiento funcionando.

- Entidades `User`, `Organization`, `Membership`.
- Registro, login, logout, verificación de email, recuperación de contraseña.
- Creación automática de organización personal al registrar.
- `TenantContext` + `TenantFilter` de Doctrine.
- `AuditLog` y escritura de las primeras acciones sensibles.
- Tests de aislamiento entre organizaciones.

**Verificación:** dos usuarios de dos organizaciones no pueden verse datos entre sí; los tests
de aislamiento fallan si se desactiva el filtro.

## Fase 2 — Servicios manuales (mínimo del dominio)

> **Estado:** ✅ **Completada.**


**Objetivo:** el usuario puede inventariar sus servicios a mano, y el modelo de datos queda
preparado para el descubrimiento automático.

- `Provider` y `Category` con catálogo global + entradas propias del tenant.
- `Service` con todos sus campos (estado, periodicidad, fechas, notas, `source`).
- `ServicePrice` con historial (precio vigente = `validTo IS NULL`).
- `ServiceEvent` append-only.
- CRUD completo con validación en español.
- Cálculos de dominio: equivalente mensual, coste anual, próximo cobro.

**Verificación:** crear, editar, pausar, cancelar y borrar servicios; cambiar un precio genera
una nueva fila de historial y un `ServiceEvent`; los cálculos tienen tests unitarios.

## Fase 3 — Vertical end-to-end (walking skeleton) ⭐

> **Estado:** ✅ **Completada.** Vertical IMAP → factura → propuesta → confirmación →
> dashboard operativa. 326 pruebas, PHPStan nivel 8 y php-cs-fixer en verde. La verificación
> con un buzón real queda pendiente de credenciales de un proveedor real (ver *Preguntas
> abiertas* al final de este documento).


**Objetivo:** demostrar la propuesta de valor completa de punta a punta, con la mínima
cantidad de código posible. **Esta fase es el instrumento de validación del producto**
(`PRODUCT.md` §3.2), no una demo.

```text
Cuenta
  ↓
Conectar IMAP (solo lectura)
  ↓
Encontrar factura
  ↓
Extraer proveedor + importe + periodicidad
  ↓
Proponer servicio
  ↓
Usuario confirma
  ↓
Servicio aparece en el dashboard
  ↓
Sistema calcula próximos costes
```

**Alcance deliberadamente mínimo:**

- `EmailAccount` con conexión IMAP de solo lectura, credenciales cifradas con libsodium y
  prueba de conexión. **Una sola cuenta** en esta fase. Sin cursores, sin lotes, sin reenvío
  todavía.
- Sincronización **manual y acotada**: una carpeta, una ventana corta (p. ej. últimos 6 meses),
  un número limitado de mensajes.
- `EmailMessage` con metadatos mínimos (remitente, asunto, fecha, `messageId`, `bodyHash`).
- **Esqueleto del pipeline** (`ARCHITECTURE.md` §13) en su versión mínima: identidad y
  deduplicación (nivel 0), metadatos (nivel 1), filtro determinista con `billingScore` (nivel
  2) y **una** extracción por reglas para los proveedores más comunes (nivel 3). Sin OCR, sin
  IA, sin conocimiento de proveedores persistido.
- **La máquina de estados y `MessageProcessingEvent` se implementan ya en esta fase**, aunque
  solo se usen cuatro estados. Añadirlos después obligaría a reprocesar todo el histórico.
- `Discovery` con `proposedData` y evidencia, y una bandeja de revisión mínima.
- Confirmar → crea `Service` + `ServicePrice` + `ServiceEvent` con `source = email_discovery`.
- Dashboard mínimo: servicios detectados y próximos cobros calculados.

**Fuera de alcance de esta fase:** reenvío, cursores incrementales, OCR, IA, documentos
persistidos, alertas, notificaciones, detección de cambios, duplicados.

**Verificación:** con un buzón real de prueba, el sistema encuentra al menos una factura,
propone un servicio con proveedor, importe y periodicidad correctos, el usuario lo confirma y
aparece en el dashboard con su próximo cobro calculado. **Nunca se ejecuta una operación de
escritura sobre el buzón.** La bandeja de revisión y el dashboard funcionan **a 320 px de ancho
sin scroll horizontal** (`ARCHITECTURE.md` §12).

**Criterio de continuidad:** si esta vertical no funciona con buzones reales, no se avanza a
las fases siguientes sin resolverlo.

## Fase 4 — Dashboard completo

> **Estado:** ✅ **Completada.** El panel responde a las ocho preguntas del producto con un
> número fijo de consultas, independiente del número de servicios y de buzones. Las alertas
> abiertas en portada llegan con la Fase 6, que es donde existen.


**Objetivo:** responder a las ocho preguntas del producto (`PRODUCT.md` §3.1).

- Próximos cobros (30/60 días) con totales.
- Coste recurrente: mensual equivalente, anual, por categoría, servicios más caros.
- Recuento de servicios por estado y categoría.
- Listado de servicios con filtros.
- Descubrimientos pendientes y alertas abiertas en portada.

**Verificación:** con datos de prueba, los totales del dashboard coinciden con los cálculos de
dominio; consultas sin N+1.

## Fase 5 — Historial de precios y cambios

> **Estado:** ✅ **Completada.** El historial es inmutable y la evolución del gasto es
> histórica: cada mes usa el precio que estaba vigente entonces, no el de hoy.


**Objetivo:** hacer visible la evolución del coste.

- Vista de historial de precios por servicio con variación porcentual y absoluta.
- Detección determinista de cambio de precio (comparación de filas consecutivas).
- `ServiceEvent` de tipo `price_changed` y `plan_changed`.
- Evolución del gasto agregada por periodo.

**Verificación:** un cambio de precio produce la variación correcta; el historial es inmutable.

## Fase 6 — Renovaciones, alertas y notificaciones

> **Estado:** ✅ Completada.

**Objetivo:** avisar antes de que algo importante ocurra.

- Propiedades de renovación en `Service` (`renewalAt`, `noticePeriodDays`, `autoRenews`,
  `commitmentEndAt`).
- Entidades `Alert`, `Notification`, `NotificationPreference`.
- Generación programada de alertas deterministas: próximo cobro, próxima renovación,
  renovación anual próxima, aumento de precio.
- Canales `in_app` y `email` tras `NotificationChannelInterface`.
- Bandeja de alertas y preferencias por tipo.

**Verificación:** una renovación a 45 días genera alerta y notificación; desactivar un tipo en
preferencias impide la notificación; no se envían duplicados.

**Implementado:**

- 7 tipos de aviso (`AlertType`) con umbrales centralizados en `AlertRules` (constantes, no
  reglas dispersas): próximo cobro, próxima renovación, renovación anual, plazo de preaviso,
  fin de compromiso, subida de precio y descubrimiento pendiente.
- `Alert` con clave de deduplicación `tipo|servicio|fecha` e índice único por organización:
  ejecutar el generador cada hora no duplica nada. Un aviso descartado **nunca** se reabre.
- `GenerateAlerts` crea los avisos que corresponden y **resuelve** los que ya no: un aviso de
  cobro que se quedó abierto porque el cobro ya pasó enseña al usuario a ignorar la bandeja.
- `DispatchNotifications` entrega por los canales activos, con reintento de los fallos y sin
  repetir lo ya entregado.
- Suelo no configurable: un aviso `CRITICAL` **siempre** se envía por correo, aunque el usuario
  haya desactivado el canal. Un producto que existe para evitar cobros olvidados no puede
  callarse tres días antes de un cargo.
- `NotificationPreference` guarda **solo las desviaciones** respecto a los valores por defecto:
  volver al valor por defecto borra la fila, porque un valor por defecto no es una opinión.
- Comando `app:alerts:generate` (idempotente, recorre todas las organizaciones dentro de su
  propio contexto de tenant) y `app:alerts:generate --no-notify` para generar sin entregar.
- Bandeja de avisos con filtro por estado, detalle con el motivo, y matriz de preferencias.

**Verificación realizada:** 487 pruebas y 2263 aserciones en verde; PHPStan nivel 8 sin errores;
php-cs-fixer sin cambios pendientes. Cubierto con pruebas unitarias (`GenerateAlerts`,
`DispatchNotifications`, `UpdateNotificationPreferences`, `AlertRules`) y funcionales
(`AlertInboxTest`, `AlertAccessTest`, `AlertPreferencesTest`, `GenerateAlertsCommandTest`).

**Fallo real encontrado y corregido:** `TenantContext::runAs()` cambiaba la organización activa
pero **no la publicaba en el filtro de Doctrine**, así que los comandos de consola leían los datos
de todas las organizaciones. `app:alerts:generate` habría generado avisos cruzados entre clientes.
Corregido con `TenantFilterSynchronizerInterface` (ver `ARCHITECTURE.md` §8.1).

## Fase 7 — Documentos

> **Estado:** ✅ Completada.

**Objetivo:** adjuntar facturas y documentos a un servicio.

- Entidades `Document` e `Invoice`.
- `DocumentStorageInterface` con implementación local.
- Subida manual, validación de MIME y tamaño, checksum, deduplicación.
- Descarga autorizada (nunca por URL directa).
- Asociación a servicio y a factura; borrado con soft delete.
- Los adjuntos de correo pasan a persistirse como `Document` (hasta ahora se procesaban en
  memoria).

**Verificación:** subir, descargar y borrar documentos; un usuario no puede descargar el
documento de otra organización; el fichero no es accesible por URL directa.

**Implementado**

- `Document` (tabla `document`): `originalFilename`, `storageDriver`, `storageKey`, `mimeType`,
  `sizeBytes`, `checksumSha256`, `type`, `source`, `emailMessageId`, `serviceId`, `invoiceId`,
  `createdAt`, `deletedAt`. Índice único `(organization_id, checksum_sha256)`: el mismo fichero
  no se guarda dos veces en una organización. Borrado lógico con `delete()` / `restore()`.
- `Invoice` (tabla `invoice`): `number`, `issuedAt`, `totalAmountMinor`, `currency`,
  `periodStart`, `periodEnd`, `paidAt`, `status`, `source`, `serviceId`, `providerId`,
  `documentId`. `buildDedupKey()` = `providerId|number|amountMinor|currency|fecha`, que es lo
  que impide registrar dos veces el mismo cobro.
- `InvoiceSource` (`manual` | `email_discovery`). Se añadió porque una factura puede existir
  **sin documento** (D-19: hay cobros que solo conocemos por el cuerpo del correo) y entonces
  no hay ningún `Document.source` que diga de dónde salió. Es además la métrica que mide la
  propuesta de valor (PRODUCT.md §12): qué parte del historial la descubrió el producto.
- `DocumentStorageInterface` + `LocalDocumentStorage`: clave con particionado
  `{orgId}/{h[0:2]}/{h[2:4]}/{hash}{ext}` y guarda contra *path traversal* (rechaza `''`, `..`
  y `\0`).
- `UploadDocument`: lista blanca de MIME (pdf, jpeg, png, webp, tiff, plain, csv, xml, zip),
  tope de 20 MB, deduplicación por SHA-256 (devuelve el documento existente con
  `created: false`), evento `DOCUMENT_ADDED` y auditoría `DOCUMENT_UPLOADED`.
- `CreateInvoice`: rechaza importes negativos, deduplica por `buildDedupKey()`, rellena el
  `serviceId` de una factura ya conocida sin sobrescribir el asignado, y registra
  `ServiceEventType::RENEWED` fechado el día de la factura.
- `DownloadDocument` / `DocumentContents` / `DeleteDocument` / `AttachDocumentToService`.
- 9 rutas: `app_documents_{index,new,show,download,attach,delete}` y
  `app_invoices_{index,new,show}`. La descarga pasa siempre por el controlador (nunca por URL
  directa) y añade `X-Content-Type-Options: nosniff`.
- Migración `Version20261005093357` (document + invoice) y `Version20261005095401`
  (`invoice.source`), aplicadas en `app` y `app_test`.

**Verificación realizada**

- 610 pruebas, 2610 aserciones, todas en verde; PHPStan nivel 8 sin errores; php-cs-fixer sin
  cambios.
- `tests/Functional/Documents/DocumentTest.php` (12): subida, deduplicación, filtro por tipo,
  descarga con cabeceras, borrado lógico, asociación a servicio.
- `tests/Functional/Documents/DocumentAccessTest.php` (3): anónimo redirigido en las 4 rutas
  sensibles; aislamiento entre organizaciones (404 en ver, descargar, borrar y asociar).
- `tests/Functional/Documents/InvoiceTest.php` (11): alta, deduplicación, asociación a servicio
  con evento `RENEWED`, importe negativo → 422, importe ausente → 422, periodo invertido → 422,
  filtro por estado, factura desconocida → 404, y el origen (`manual` / `email_discovery`).
- `tests/Unit/Documents/…`: `DocumentTest` (14), `InvoiceTest` (32), `LocalDocumentStorageTest`
  (16, con 5 rechazos de *path traversal*), `UploadDocumentTest` (19), `CreateInvoiceTest` (21),
  `DocumentLifecycleTest` (14).

**Fallos reales encontrados y corregidos**

1. **`CreateInvoice` dejaba una referencia colgando.** Llamaba a
   `$invoice->attachToDocument($input->documentId)` **antes** de comprobar que el documento
   existía, así que una factura podía quedar apuntando a un documento inexistente. Se corrigió
   resolviendo el documento primero.
2. **`submitForm()` recibe una *ruta*, no un `UploadedFile`.** `DomCrawler\Form::getPhpFiles()`
   pasa el valor por `http_build_query`, y `FileFormField::setValue(?string)` espera una ruta. En
   modo coercitivo PHP convertía el objeto a *string* vía `File::__toString()`, es decir, a la
   ruta temporal, y `getClientOriginalName()` devolvía el nombre del fichero temporal. La prueba
   funcional escribía el fichero en un directorio temporal **con el nombre final** y pasaba esa
   ruta. Además `UploadedFile::getMimeType()` **inspecciona el contenido**, así que el fichero de
   prueba tiene que ser un fichero real del tipo esperado.

## Fase 8 — Conexión de correo completa (IMAP y reenvío)

> **Estado:** ✅ Completada.


**Objetivo:** ingesta robusta, incremental y multi-proveedor.

- `EmailSyncCursor`, `EmailSyncRun` y sincronización incremental por `uidValidity` + `lastSeenUid`.
- **Varias cuentas de correo por organización**, cada una con su cursor y su estado, y
  **deduplicación entre cuentas** (documentos por checksum, descubrimientos por proveedor +
  importe + fecha + periodicidad). Ver `DECISIONS.md` D-27.
- Ventana acotada, lotes, backfill progresivo y backpressure.
- **Vía alternativa de ingesta por reenvío** (dirección dedicada por organización, con
  verificación de remitente), que no requiere acceso al buzón ni OAuth. Ver `DECISIONS.md`
  D-21.
- UI de estado de sincronización, errores y reconexión.
- Pruebas con varios proveedores: Gmail, Microsoft 365, servidor IMAP propio y correo de
  hosting. **Ninguno es el camino principal** (D-23).

**Verificación:** la sincronización no repite mensajes ya procesados; desconectar elimina
credenciales y mensajes; nunca se ejecuta una operación de escritura sobre el buzón; un correo
reenviado desde una dirección no autorizada se rechaza; funciona con al menos tres proveedores
distintos; **con dos cuentas conectadas a la vez, una factura presente en ambas genera un solo
descubrimiento**.

**Implementado**

- **`EmailSyncCursor`** (`email_sync_cursor`): `uidValidity` + `lastSeenUid` + `backfillCursor`
  por cuenta y carpeta, con `UNIQUE (email_account_id, folder)`. `observeUidValidity()` detecta
  la renumeración del servidor y reinicia el cursor; `advanceTo()` nunca retrocede.
- **Lectura incremental real.** `ImapClientInterface` pasa de 4 a 7 métodos:
  `getUidValidity()`, `fetchHeadersAfter()` y `fetchHeadersBefore()`. `WebklexImapClient`
  comparte una única implementación privada (`fetch()`) para las tres lecturas de cabeceras y
  aísla el truco del rango de UID (`CUSTOM UID 100:*`) en un solo método, porque la API de alto
  nivel entrecomilla cualquier valor no numérico y el servidor lo rechaza.
- **Backfill progresivo.** La primera pasada lee la ventana acotada (6 meses por defecto) y,
  a continuación, un lote hacia atrás. El backfill termina cuando el lote vuelve vacío, llega
  incompleto o alcanza el UID 1. `EmailSyncRun` gana `messagesBackfilled`.
- **Vía de reenvío completa.** `ForwardingAddressFactory` genera
  `inbox-<32 hex>@<dominio>` con `random_bytes(16)`; `EnableEmailForwarding`,
  `RotateForwardingAddress`, `DisableEmailForwarding` y `UpdateForwardingSenders` la gestionan;
  `IngestForwardedEmail` valida destinatario, estado, remitente autorizado, tamaño y caudal, y
  encola el mensaje. `InboundEmailController` expone `POST /mail/inbound`.
- **`EmailMessage.source`** (`imap` / `forwarding`) y **`uid` nullable**: un reenvío nunca
  estuvo en una carpeta IMAP. La deduplicación de reenvíos se apoya en `messageId` y
  `contentHash`, que usa la **misma normalización** que el pipeline para compartir la caché de
  extracción (D-37).
- **UI completa:** botón «Sincronizar ahora», panel de reenvío (dirección, remitentes
  autorizados, rotar, desactivar) y tabla «Últimas lecturas» con estado, revisados, nuevos y
  descubrimientos.
- **Desconectar** borra credenciales, mensajes indexados y cursores, y desactiva el reenvío;
  los servicios confirmados permanecen (`SECURITY.md` §6).

**Verificación realizada**

- `tests/Unit/Mailbox/Application/SyncEmailAccountTest.php` (23 pruebas): lectura incremental,
  reinicio por `uidValidity`, backfill, orden de lectura, idempotencia.
- `tests/Unit/Mailbox/Application/MultiProviderSyncTest.php` (8 pruebas): **Gmail, Microsoft
  365, servidor IMAP propio y correo de hosting** recorren el mismo camino genérico; se
  comprueba que los parámetros que llegan al cliente son exactamente los configurados y que la
  contraseña nunca aparece en la descripción de la conexión.
- `tests/Unit/Mailbox/Application/EmailAccountRemovalTest.php` (8 pruebas): desconectar
  destruye credenciales, borra mensajes y cursores, desactiva el reenvío y conserva la cuenta;
  borrar además la marca como eliminada y no toca los mensajes de otra cuenta.
- `tests/Unit/Mailbox/Application/Forwarding/*` (38 pruebas) y
  `tests/Unit/Mailbox/Domain/Entity/EmailAccountForwardingTest.php` (13 pruebas): generación,
  rotación, autorización por dirección y por dominio, y **rechazo de dominios parecidos**.
- `tests/Functional/Mailbox/InboundEmailTest.php` (15 pruebas): los siete estados de ingesta,
  deduplicación, auditoría, endpoint público, `405` y el contrato «encolado, no procesado en la
  petición».
- `tests/Functional/Mailbox/EmailAccountTest.php` (29 pruebas): UI de sincronización y de
  reenvío, CSRF, y el contrato de **solo lectura** sobre los 7 métodos del cliente IMAP.
- `tests/Functional/Processing/CrossAccountDedupTest.php` (2 pruebas): **la misma factura en
  dos cuentas de la misma organización produce un único descubrimiento con dos evidencias**;
  dos importes distintos producen dos descubrimientos.

**Fallos reales encontrados y corregidos**

1. **El correo reenviado se procesaba dentro de la petición HTTP del proveedor.**
   `IngestForwardedEmail` despachaba `ProcessEmailMessageMessage` **sin**
   `TransportNamesStamp`. Como `messenger.yaml` no declara `routing:` para ese mensaje —el
   pipeline se enruta explícitamente por mensaje—, Messenger lo trató como síncrono y ejecutó
   todo el pipeline (billing score, extracción, matching) antes de responder. Riesgo de
   *timeout* y de trabajo duplicado en cada reintento del proveedor. Se corrigió despachando
   con `[new TransportNamesStamp(['mail_processing'])]` y se añadió una prueba de regresión que
   comprueba que el mensaje queda en estado `RECEIVED`.
2. **Desconectar no borraba los mensajes ni los cursores.** `SECURITY.md` §6 y esta misma fase
   dicen que desconectar elimina credenciales, cursores y mensajes asociados, pero
   `DisconnectEmailAccount` solo borraba las credenciales. Se corrigió añadiendo el borrado de
   mensajes y cursores, y **desactivando el reenvío**: una cuenta «desconectada» seguía
   aceptando correo reenviado porque `findByForwardingAddress()` no miraba el estado.
3. **`InMemoryTransport` se vacía entre peticiones.** Implementa `ResetInterface` y
   `Kernel::boot()` reinicia los servicios cuando no hay petición en curso, así que la cola no
   sobrevive de una petición a la siguiente ni con `disableReboot()`. Las pruebas tienen que
   vaciar la cola inmediatamente después de cada petición. Además `get()` lee
   `func_num_args()` y devuelve **un solo** sobre por defecto, y su firma declara cero
   parámetros, así que PHPStan rechaza `get(100)`.
4. **`assertSame` sobre dos `Uuid` compara identidad, no valor.** Varias aserciones
   comparaban objetos; se normalizaron a `->toRfc4122()`.

## Fase 9 — Pipeline: ingesta, metadatos, filtros y billing score (niveles 0–2)

> **Estado:** ✅ Completada.

**Objetivo:** que el sistema sepa, de forma barata y explicable, **qué correos merecen
procesarse**. Es la fase que hace viable económicamente todo lo demás.

- **Nivel 0 — identidad y deduplicación.** Clave primaria `emailAccountId + folder + uid`
  (con `uidValidity`), clave secundaria `emailAccountId + messageId`, y `contentHash` como
  clave de caché de extracción. Procesamiento idempotente (D-37).
- **Nivel 1 — metadatos.** Extracción de cabeceras con `BODY.PEEK[HEADER]`: `From`, `Reply-To`,
  `To`, `Subject`, `Date`, `Message-ID`, UID, `Content-Type`, nombres y tipos de adjuntos,
  tamaño y dominio del remitente. **Sin descargar cuerpo ni adjuntos.**
- **Nivel 2 — filtros deterministas.** `BillingClassifierInterface` con pesos configurables y
  `billingScore` 0–100, más `billingReasons` persistidos (D-31).
- **Máquina de estados** (`RECEIVED`, `IGNORED`, `CANDIDATE`, …) y `MessageProcessingEvent`
  como log append-only de transiciones (D-32).
- Listas configurables de remitentes ignorados y de proveedores conocidos.
- Mensajes de Messenger del pipeline y transporte `mail_processing`.

**Verificación:** con un buzón real, el sistema descarta la mayoría de mensajes **sin
descargar su contenido**; cada decisión es explicable desde `billingReasons`; reprocesar el
mismo mensaje no cambia nada y no cuesta nada; el porcentaje de descartes en el nivel 2 es
medible.

**Implementado**

- `BillingClassifierInterface` (`src/Processing/Domain/Billing/`) como puerto del nivel 2
  (D-31), implementado por `BillingScoreCalculator` y aliado en `config/services.yaml`.
  `ProcessEmailMessage` depende del puerto, no de la clase.
- **Caché de extracción** (D-37): entidad `ExtractionCache` (tabla `extraction_cache`, única
  por `(organization_id, content_hash)`), `ExtractionCacheRepositoryInterface` +
  implementación Doctrine, y `ExtractedDocument::fromArray()` / `withSenderContext()` para
  poder reutilizar el resultado. El pipeline consulta la caché antes de extraer, registra el
  acierto (`hitCount`, `lastUsedAt`) y marca la transición con el extractor `cache`.
- **Lista de remitentes ignorados** con las tres formas de entrada (dirección, `@dominio`,
  dominio) y comparación por etiquetas completas (D-42).
- **`app:mail:stats`** (`src/Processing/UI/Command/MailboxStatsCommand.php`): embudo del
  pipeline por organización —leídos, descartados, porcentaje de descarte, candidatos,
  descubrimientos, en revisión, fallidos y entradas en caché—, de solo lectura y con
  `--organization`.
- Migración `Version20261005105737.php` (`extraction_cache` + 2 índices), aplicada en `app` y
  `app_test`.

**Verificación realizada**

- `tests/Unit/Processing/Application/Billing/BillingScoreCalculatorTest.php` — 22 pruebas,
  incluidas 8 sobre las tres formas de `ignored_senders`, coincidencia de subdominio y
  **rechazo de los dos casos de suplantación** (`falsolinkedin.com`, `linkedin.com.evil.io`).
- `tests/Unit/Processing/Domain/Dto/ExtractedDocumentTest.php` — 6 pruebas: ida y vuelta por
  array de los 17 campos, degradación de un array vacío y de valores basura, aceptación de
  cadenas numéricas y refresco de contexto con `withSenderContext()`.
- `tests/Unit/Processing/Domain/Entity/ExtractionCacheTest.php` — 7 pruebas: serialización,
  contador de aciertos, confianza en centésimas, hash vacío rechazado y truncado a 64.
- `tests/Unit/Processing/Application/ProcessEmailMessageTest.php` — 21 pruebas, incluidas 6
  nuevas: la extracción se cachea, un segundo mensaje con el mismo cuerpo reutiliza la caché
  (extractor `cache`, `hitCount` 1), el acierto refresca remitente y asunto, un cuerpo vacío
  nunca se cachea, no se escribe dos veces la misma entrada y **reprocesar un mensaje terminal
  no cuesta nada** (sin descubrimientos nuevos, sin eventos nuevos).
- `tests/Functional/Processing/MailboxStatsTest.php` — 6 pruebas: embudo completo con
  porcentaje (`42,9 %`), tasa de descarte (`50,0 %`), buzón vacío que muestra `—` y no
  `0,0 %`, aislamiento por organización, organización desconocida (código 2) y comando de solo
  lectura.
- `doctrine:schema:validate` → mapeo correcto y esquema sincronizado en `app` y `app_test`.
- `app:mail:stats` ejecutado contra la base de datos real: imprime el embudo de las dos
  organizaciones.
- Suite completa: **750 pruebas, 3241 aserciones**. PHPStan nivel 8: 0 errores. php-cs-fixer:
  0 archivos.

**Fallos reales encontrados y corregidos**

1. **La lista de remitentes ignorados era código muerto.** `BillingScoreCalculator` comparaba
   la dirección completa del remitente con `in_array()`, así que las entradas de dominio
   (`linkedin.com`) nunca coincidían con `messages-noreply@linkedin.com` y la penalización de
   −100 no se aplicaba nunca. Corregido con `ignoredSenderMatch()` y comparación por etiquetas
   completas (D-42). Las entradas `newsletter` y `marketing` se retiraron de la configuración:
   no eran ni direcciones ni dominios.
2. **La guarda de la caché era código muerto.** `remember()` comprobaba `'' === $contentHash`,
   pero `contentHash('')` es un hash SHA-256 perfectamente válido de 64 caracteres, así que la
   condición nunca se cumplía y **todos los mensajes sin cuerpo habrían compartido una única
   entrada de caché**, reutilizando extracciones entre mensajes sin relación. Corregido
   pasando el cuerpo y comprobando `'' === trim($body)`.
3. **`BillingClassifierInterface` no existía.** El roadmap y `ARCHITECTURE.md` §13 lo nombraban
   como el puerto de D-31, pero `ProcessEmailMessage` dependía de la clase concreta. Creado y
   aliado.


## Fase 10 — Extracción determinista y conocimiento de proveedores (niveles 3–4)

> **Estado:** ✅ Completada (2026-10-21).


**Objetivo:** extraer por código todo lo que sea fiablemente extraíble, y no volver a pagar por
lo que ya se sabe.

- **Nivel 3 — extracción determinista.** Importes, monedas, fechas, números de factura,
  periodos, correos, dominios, URLs, identificadores y referencias de pedido mediante regex,
  parsers, análisis de HTML y extracción de texto de PDF (`smalot/pdfparser`).
- **OCR** (`OcrEngineInterface`, Tesseract) **solo** para documentos sin capa de texto,
  ejecutado en nuestra infraestructura, en transporte propio con concurrencia muy baja.
- `ExtractedDocument` como DTO persistido en JSON, con `rawSignals` para poder explicar y
  depurar la extracción.
- **Nivel 4 — conocimiento de proveedores.** `Provider`, `ProviderIdentity` (dominio,
  remitente, patrón) y `ProviderParser` (parser de código + `config` declarativa).
- Escalera de resolución: identidad → parser conocido → patrones → (si nada basta) IA.
- Contadores `successCount` / `failureCount` por parser, con degradación a IA en lugar de fallo
  duro.

**Verificación:** un conjunto de facturas reales se extrae correctamente **sin IA**; un
proveedor conocido se resuelve por parser en la segunda factura; un PDF escaneado pasa por OCR
y no por IA; las capas 1–3 no envían datos fuera del sistema; un parser que falla degrada a IA
y no rompe el lote.

### Qué se entregó

- **`src/Processing/Application/Extraction/`** — `DeterministicExtractor` (orquestador de nivel 3),
  `AmountParser`, `DateParser`, `InvoiceNumberReader`, `BillingPeriodDetector`.
- **`src/Processing/Application/Provider/`** — `ProviderResolver` (escalera de identidad),
  `ProviderParserRegistry` (índice por clave), `ExtractWithProviderParser` (nivel 4),
  `DeclarativeParser` (parser configurable por datos) y `Parsers/OvhParser` + `Parsers/AcmeParser`
  (parsers de código, uno por familia de plantilla).
- **`src/Processing/Infrastructure/Text/`** — `PdfTextExtractor` (prioridad 100),
  `PlainTextExtractor`, `DocumentTextExtractorRegistry`.
- **`src/Processing/Infrastructure/Ocr/TesseractOcrEngine`** — Tesseract (`eng`+`spa`) con
  `symfony/lock`, 5 páginas máximo, 300 dpi. **Solo** entra si el texto extraído tiene menos de
  40 caracteres, para no pagar un OCR sobre un PDF que ya traía su texto.
- **`src/Processing/Application/Extraction/InvoiceNumberReader`** — lector compartido del número
  de factura. Nació de un bug real: el patrón anterior capturaba literalmente la palabra
  «Factura» (7 caracteres alfanuméricos) en lugar del número, y el filtro posterior la
  descartaba sin probar el siguiente candidato. Ahora la etiqueta se reconoce, pero **el número
  exige un dígito**, no puede ser un periodo (`2026-10`) y tiene entre 4 y 30 caracteres.

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 884 tests, 3582 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:yaml config/ --parse-tags` | ✅ 32 ficheros |
| `bin/console lint:twig templates/` | ✅ 39 plantillas |

**Correcciones relevantes de la fase:**

1. **El número de factura nunca era el número.** `DeterministicExtractor::INVOICE_NUMBER_PATTERN`
   era una alternancia sin grupo de captura de la etiqueta, de modo que `preg_match_all` devolvía
   la *etiqueta* («Factura», «Invoice», «nº») como si fuera el candidato. El `foreach` que
   filtraba los candidatos descartaba ese valor y, como era el único, `invoiceNumber` quedaba
   `null`. Se añadió una *lookahead* que exige un dígito y se extrajo la lógica a
   `InvoiceNumberReader`, que ahora comparten el extractor determinista y los parsers de
   proveedor.
2. **El PDF escaneado nunca llegaba al OCR.** La fixture de prueba generaba un PDF con capa de
   texto, así que `PdfTextExtractor` lo leía y el OCR no se ejecutaba nunca: el test pasaba sin
   probar nada. `PdfFixture::scanned()` rasteriza de verdad con `pdftoppm` y embebe el JPEG
   como `XObject`/`DCTDecode` en un PDF **sin** capa de texto.
3. **El parser de código no estaba registrado.** `ProviderParser.key` referencia la clave de un
   `ProviderParserInterface` (`ProviderParserInterface::key()`), no un nombre libre. El test
   usaba `AcmeParser::KEY` pero el pipeline buscaba `'acme'`, que no existía en el contenedor.
   Ahora `AcmeParser` está autoconfigurado por `_instanceof` y se registra sin tocar YAML.
4. **Un test de integración dependía del estado heredado de la base de datos.** `SeedCatalogTest`
   asumía que el catálogo ya estaba sembrado, pero la base de datos de **test** no pasa por el
   `entrypoint` del contenedor, que es quien siembra. El test siembra ahora el catálogo en su
   `setUp()`.
5. **Reloj congelado en los tests dependientes de la fecha.** `GenerateAlertsCommandTest` fallaba
   conforme pasaban los días, porque «un cobro pasado respecto a *ahora*» dejaba de ser cierto.
   `tests/Support/FreezesClock.php` sustituye `test.clock` por un `MockClock` y fija el instante
   en el test, de modo que el fallo que aparezca sea siempre un fallo real.

## Fase 11 — IA económica y avanzada con validación (niveles 5–7)

> **Estado:** ✅ Completada.


**Objetivo:** resolver la incertidumbre que las reglas no cubren, **sin que la IA sea el camino
por defecto** y sin que pueda corromper el dominio.

- **Nivel 5 — IA económica.** `DocumentExtractorInterface` + `AiProviderInterface` con
  adaptador de modelo económico. **Desactivada por defecto** (D-08).
- **Redacción previa al envío:** eliminar HTML innecesario, firmas, avisos legales, tracking y
  contenido irrelevante; limitar longitud; extraer texto del PDF; enviar como contexto los
  datos ya obtenidos determinísticamente.
- **Contrato de salida estructurado** (proveedor, servicio, plan, tipo de documento, importe,
  moneda, periodicidad, fechas, confianza).
- **Nivel 6 — IA avanzada** con umbral configurable, solo para confianza insuficiente, campos
  contradictorios, proveedor desconocido con documento complejo, PDF difícil o información
  insuficiente (D-30).
- **Nivel 7 — validación obligatoria.** DTO tipado → Symfony Validator → reglas de negocio.
  Se rechazan importes negativos o absurdos, fechas incoherentes (`renewalDate < invoiceDate`),
  periodicidades imposibles y campos obligatorios ausentes (D-34).
- Registro de `extractionTier` por extracción, para medir el valor real de cada capa.

**Verificación:** sin proveedor de IA configurado el sistema sigue funcionando; con IA
configurada, un documento ambiguo se resuelve y uno con datos imposibles se rechaza y queda en
`REQUIRES_REVIEW`; se puede cambiar de proveedor de IA sin tocar el dominio; el texto enviado
no contiene firmas, tracking ni el correo completo.

### Qué se entregó

- **`src/Ai/Domain/`** — `AiPurpose` (las cuatro necesidades), `AiTier` (económico/avanzado),
  `AiProviderInterface` y `AiProviderRegistryInterface`, `AiUsage` (registro de uso y coste),
  `AiRequest`/`AiResponse`/`AiPrice`/`AiExtractionResult` (contrato de salida estructurado).
- **`src/Ai/Application/`** — `AiPurposeSelector` (elección por necesidad y por organización),
  `AiExtractionService` (la escalera en el pipeline), `AiBudget` (tope mensual del mes natural),
  `AiUsageRecorder`, `AiPriceCatalog` (precios por defecto, corregibles por integración),
  `RedactContent` (redacción previa: sin HTML, firmas, tracking ni correo completo),
  `ExtractionValidator` (reglas de negocio que el Validator no expresa) y
  `Extraction/AbstractAiDocumentExtractor`, `AiDocumentExtractor` (nivel 5),
  `AdvancedAiDocumentExtractor` (nivel 6) y `DocumentExtractorChain`.
- **`src/Ai/Infrastructure/Provider/`** — `GroqProvider`, `OpenAiProvider`, `AnthropicProvider`,
  `OllamaProvider` (autoalojado) y `AbstractOpenAiCompatibleProvider`.
- **`src/Integrations/`** — `IntegrationRegistry` (catálogo), `IntegrationDefinition` e
  `IntegrationField` (declaración de campos), `IntegrationConfigWriter` (cifra) y
  `IntegrationConfigReader` (descifra bajo demanda), entidad `Integration` y sus controladores.
- **`src/Shared/Application/OrganizationSettingsReader.php`** — los ajustes de la organización
  (`Organization.settings` es un `json` genérico: añadir un ajuste no obliga a migrar).
- **Panel de integraciones** (`/integrations`) y **panel de IA** (`/ai`), con el estilo del
  backoffice de la plataforma de referencia.
- **Migración `Version20261020232102`** — tabla `integration` y tabla `ai_usage`, ejecutada.

**Correcciones relevantes de la fase:**

1. **Ninguna extracción con IA funcionaba (bug de producción).** `AiExtractionResult` usaba
   `#[Assert\Range(min: 0, max: 1, minMessage: ..., maxMessage: ...)]`, combinación **prohibida**
   en Symfony 8: `Range` lanza `ConstraintDefinitionException` al construirse. Como la
   restricción se construye al arrancar el contenedor, el sistema no arrancaba con la IA
   activada. Corregido a `notInRangeMessage`. Ver D-46.
2. **El nivel 6 pagaba con un modelo económico.** `AdvancedAiDocumentExtractor::supports()`
   comprobaba solo que hubiera un proveedor elegido para `advanced_extraction`, no que fuera de
   nivel `ADVANCED`. Ahora resuelve el proveedor real con `forPurpose()` y exige su nivel. Ver
   D-44.
3. **El escalado al nivel 6 se activaba antes de tiempo.** La escalera de IA entra solo si no
   hay resultado en caché **y** el documento no es accionable; y el documento que devuelve la IA
   solo se adopta si es accionable. Un documento que la IA no ha podido completar no sustituye
   al determinista: el caso acaba en `REQUIRES_REVIEW`.

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 1026 tests, 3927 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:yaml config/ --parse-tags` | ✅ 32 ficheros |
| `bin/console lint:twig templates/` | ✅ 42 plantillas |

## Fase 12 — Service matching y descubrimientos (nivel 8)

> **Estado:** ✅ Completada.


**Objetivo:** decidir a qué servicio pertenece un documento, **sin crear servicios
automáticamente**.

- `ServiceMatcherInterface` con puntuación ponderada (proveedor +40, dominio +20, nombre +20,
  moneda +5, periodicidad +5, similitud de importe +5, remitente +5) y umbrales configurables
  (≥70 HIGH, 40–69 MEDIUM, <40 LOW). Ver D-35.
- `Discovery.matchScore` y `matchReasons` persistidos, para poder explicar la asociación.
- `RecurrenceDetectorInterface` determinista (intervalos entre facturas, importes) y cálculo de
  `nextChargeAt`.
- `DiscoveryEvidence` con enlace a correo y documentos de origen.
- Bandeja de descubrimientos con evidencia, filtros y acciones en lote.
- Confirmar → `Service` + `ServicePrice` + `ServiceEvent`; editar; ignorar (y no volver a
  proponer lo mismo); expiración de descubrimientos antiguos.
- Alertas de tipo `new_service_detected`.

**Verificación:** con historiales sintéticos la periodicidad detectada es correcta; los
emparejamientos ambiguos quedan en confianza media y pasan por revisión; **ningún servicio se
crea sin confirmación**; confirmar crea el servicio con su precio inicial; ignorar no vuelve a
proponerlo; el flujo completo funciona sin tocar la base de datos a mano.

### Qué se entregó

- **Emparejamiento explicable** — `ServiceMatcherInterface` y `ServiceMatcher`
  (`src/Processing/`): puntuación ponderada (proveedor +40, dominio +20, nombre +20, moneda +5,
  periodicidad +5, similitud de importe +5, remitente +5) con umbrales configurables (≥70 `HIGH`,
  40–69 `MEDIUM`, <40 `LOW`). Devuelve la mejor candidata **y el desglose** que la justifica. Ver
  D-35.
- **Propuesta** — entidad `Discovery` con `matchScore` y `matchReasons` persistidos, estado
  (`pending`, `confirmed`, `edited`, `ignored`, `expired`) y `DiscoveryConfidence`.
- **Evidencia** — `DiscoveryEvidence` enlaza cada propuesta con los correos y documentos que la
  sostienen; exige al menos un origen y pondera cada evidencia entre 0 y 100.
- **Periodicidad** — `RecurrenceDetectorInterface` e `IntervalRecurrenceDetector`: intervalos entre
  facturas, importe, regularidad y fecha del próximo cobro. `DetectRecurrence` la aplica al
  servicio **sin degradar** la periodicidad declarada por el usuario (D-49).
- **Bandeja** — `templates/discoveries/index.html.twig` con evidencia, filtros por estado y
  acciones en lote; el detalle cuenta cuántos correos distintos sostienen la propuesta.
- **Decisiones** — `ConfirmDiscovery` (servicio + precio + evento), `DismissDiscovery` (ignorar y
  no volver a proponer) y `ExpireStaleDiscoveries` (caducar **conservando** la evidencia).
- **Lote** — `BatchReviewDiscoveries`: confirma o descarta varias propuestas de una vez; el fallo
  de una propuesta no aborta el resto (D-48).
- **Alertas** — `AlertType::NEW_SERVICE_DETECTED`, con clave de deduplicación que incluye la
  propuesta de origen, para que cada descubrimiento genere **una** alerta y no un duplicado por
  barrido.
- **Modelo de lectura** — `ServiceView`, `ServicePriceView` y `ServiceEventView`
  (`src/Services/Domain/Dto/`): las rutas de lectura ya no cargan la entidad de escritura (D-50).
- **Comandos** — `app:recurrence:detect` y `app:discoveries:expire`.
- **Parámetros** — `config/packages/discovery.yaml`: `expiry_days` e `ignore_suppression_days`.

### Correcciones relevantes de la fase

1. **Las rutas de lectura leían la entidad de escritura.** `ServiceCostCalculator`, `PriceHistory`,
   `SpendEvolution`, `BuildDashboardSummary` y los controladores de alertas, documentos, facturas
   y descubrimientos cargaban la entidad `Service`. Ahora leen del modelo de lectura `ServiceView`:
   el pipeline y las pantallas dejan de depender de la forma interna del dominio (D-50).
2. **Una propuesta que fallaba tiraba abajo el lote.** `BatchReviewDiscoveries` capturaba el error
   de la primera propuesta y abortaba. Ahora aísla el fallo de cada propuesta: perder veinte
   propuestas porque la primera ya estaba confirmada sería peor que el error (D-48).
3. **La detección de periodicidad podía pisar lo declarado por el usuario.** Si el usuario dijo
   «trimestral» y el historial dice «mensual», ahora se actualiza la fecha pero la periodicidad
   sigue siendo la del usuario; la detección solo adopta el periodo cuando el declarado es
   `UNKNOWN` (D-49).
4. **La alerta de servicio nuevo se duplicaba.** `Alert::buildDedupKey()` ahora incluye el
   identificador de la propuesta: cada descubrimiento genera su propia alerta y los barridos
   sucesivos no acumulan duplicados de la misma.
5. **Ignorar una propuesta la silenciaba para siempre.** La supresión es ahora una **ventana**
   (`ignore_suppression_days`, 90 días por defecto): descartar la suscripción de ejemplo hace seis
   meses no impide que una factura nueva vuelva a merecer una pregunta (D-47).

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 1095 tests, 4171 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:yaml config/ --parse-tags` | ✅ 33 ficheros |
| `bin/console lint:twig templates/` | ✅ 42 plantillas |

## Fase 13 — Aprendizaje del buzón

> **Estado:** ✅ Completada.


**Objetivo:** que el coste marginal de procesar un buzón **decrezca con el tiempo**.

- `MailboxKnowledgeEntry` por cuenta de correo: `sender_mapping`, `subject_pattern`,
  `ignored_sender`, `document_pattern`, `provider_hint` (D-33).
- Alimentación desde confirmaciones, correcciones, proveedores conocidos, patrones observados y
  parsers que han funcionado.
- Promoción **explícita** (no automática) de conocimiento del buzón a `ProviderIdentity` /
  `ProviderParser` globales.
- Pantalla de conocimiento aprendido: ver, editar y borrar lo que el sistema ha aprendido.
- Poda por `hitCount` + `lastUsedAt`.
- **Sin machine learning** (D-39).

**Verificación:** la segunda sincronización de un buzón ya procesado consume **menos IA** que
la primera, de forma medible; corregir un proveedor hace que el siguiente correo del mismo
remitente se resuelva sin IA; el usuario puede ver y borrar lo aprendido; un patrón aprendido en
un buzón no afecta a otro.

### Qué se entregó

- **Dominio** — `MailboxKnowledgeEntry` (`src/Processing/Domain/Entity/`): una fila por regla y
  por buzón, con tipo, valor, proveedor, tipo de documento, periodicidad, origen, confianza,
  contador de usos y marca de promoción. Los patrones se guardan como expresiones regulares y
  **no** se normalizan a minúsculas; el resto de valores sí.
- **Tipos** — `MailboxKnowledgeType` (cinco tipos con sus reglas) y `MailboxKnowledgeSource`
  (`observed`, `confirmation`, `user`, `promoted`), cada uno con su etiqueta y su confianza por
  defecto.
- **Lectura** — `ResolveMailboxKnowledge` devuelve un `MailboxKnowledge` con el proveedor, el tipo
  de documento, la periodicidad y las reglas de exclusión de un buzón. Se consulta **antes** que
  el catálogo global (D-51).
- **Aprendizaje** — `LearnFromMessage` observa el correo ya procesado; `LearnFromConfirmation` y
  `LearnFromDismissal` aprenden de la acción del usuario. Un dato del usuario **reemplaza** al
  deducido; un dato deducido solo rellena huecos y nunca baja la confianza.
- **Plantillas** — `PatternTemplate` convierte un texto en una plantilla con los huecos `{fecha}`,
  `{n}` y `{txt}`, y la ancla al principio y al final. Los tokens pegados a un guion bajo
  (`factura_ABC123.pdf`) también se sustituyen: los límites son miradas alrededor, no `\b`.
- **Exclusión** — `RecordSenderRejection` suma 30 puntos de confianza por descarte; el umbral es
  60, así que **el segundo descarte excluye** el remitente y el primero no (D-53). El usuario
  puede excluir de inmediato desde la pantalla.
- **Promoción** — `PromoteMailboxKnowledge` es la **única** vía al catálogo global, y solo hacia
  un proveedor global y desde tipos que identifican a un proveedor (D-54).
- **Poda** — `PruneMailboxKnowledge` borra lo que nunca se ha usado y es viejo (90 días), y el
  comando `app:knowledge:prune` lo ejecuta.
- **Pantalla** — `MailboxKnowledgeController` y `templates/mail/accounts/knowledge.html.twig`:
  ver lo aprendido, escribir una regla a mano, corregirla, ignorarla, promoverla y borrarla.
  Desde la pantalla del buzón hay un enlace directo.
- **Pipeline** — `ProcessEmailMessage` descarta el mensaje **antes de descargar el cuerpo y antes
  de llamar a la IA** si el remitente está excluido, resuelve el proveedor aprendido por encima
  del catálogo y, al terminar, anota lo aprendido.
- **Extractor** — `DeterministicExtractor` usa la periodicidad aprendida cuando el texto no la da:
  es la palanca que más IA ahorra (D-52).
- **Parámetros** — `config/packages/knowledge.yaml`: `stale_days` (90) y
  `max_entries_per_account` (500).

### Correcciones relevantes de la fase

1. **La plantilla no sustituía los tokens pegados a un guion bajo.** `{txt}` usaba límites de
   palabra (`\b`), que en `factura_ABC123.pdf` nunca se disparan, así que la plantilla aprendida
   no casaba con el siguiente correo del mismo proveedor — justo el caso que la fase existe para
   resolver. Ahora los límites son miradas alrededor.
2. **La señal de periodicidad detectada se perdió.** Al meter la periodicidad aprendida se
   sustituyó (en lugar de complementarse) el bloque que anotaba la periodicidad detectada en el
   texto, y `rawSignals` dejó de informar de ella. Se han recuperado las dos.
3. **La plantilla nombraba métodos que no existen.** La pantalla llamaba a `capturesProvider()` y
   `capturesDocumentType()`; el enum expone `carriesProvider()` y `carriesDocumentType()`. La
   pantalla se caía con un error 500 al abrirla.

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 1168 tests, 4360 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:yaml config/ --parse-tags` | ✅ |
| `bin/console lint:twig templates/` | ✅ |


## Fase 14 — Control de coste de IA y observabilidad

> **Estado:** ✅ Completada.


**Objetivo:** que el coste sea **acotado, medible y predecible**. Es requisito para operar con
IA en producción, no una optimización posterior.

- `AiUsage` obligatorio en cada llamada, incluso si falla (organización, cuenta, mensaje,
  documento, operación, proveedor, modelo, nivel, tokens, coste estimado, latencia, resultado).
- `AiBudget` por organización y periodo, con `hardStop` y guard comprobado **antes** de cada
  llamada (D-36).
- Al alcanzar el techo: no más IA, el pipeline continúa por reglas y los mensajes quedan en
  `DEFERRED` con el motivo.
- Transporte `ai` con concurrencia limitada y rate limit propio. **Nunca llamadas ilimitadas
  durante una sincronización.**
- Caché de extracción por `contentHash`: un reintento o un duplicado no vuelven a consumir IA.
- Panel de observabilidad: coste por usuario y mes, % de mensajes resueltos sin IA, tasa de
  escalado a IA avanzada, tiempo medio por mensaje y por nivel.
- Alertas internas al superar umbrales de coste.

**Verificación:** ninguna ruta del pipeline puede hacer llamadas de IA ilimitadas; al agotar el
presupuesto el sistema sigue procesando por reglas y deja el resto en `DEFERRED`; reprocesar un
mensaje ya extraído cuesta cero; se puede responder con datos a *"¿cuánto cuesta procesar el
buzón de este usuario?"* y *"¿cuánto cuesta de IA un usuario medio al mes?"*.

### Qué se entregó

- **Presupuesto** — `AiBudget` (`src/Ai/Domain/Entity/`): una fila por organización y periodo
  (`YYYY-MM`) con el **tope** en céntimos y el **modo** de agotamiento. Persiste **solo la
  política**: el gasto sale siempre del libro mayor `ai_usage` (D-55). `AiBudgetGuard` es el
  guardián: responde a «¿puedo llamar?», «¿puedo llamar a *este* nivel?» y «¿cuánto queda?».
- **Modos** — `AiBudgetMode`: `SOFT` degrada (sigue la IA económica, se apaga el nivel avanzado) y
  `HARD` para (ninguna llamada; los correos que necesitaban IA quedan `DEFERRED` y se reintentan
  otro mes). El modo lo elige la organización, por mes (D-56). Tope por defecto: 500 céntimos
  (5,00 €), modo `SOFT`; `0` significa «sin tope».
- **Motivo explícito** — la escalera de IA devuelve un `AiExtractionOutcome` con el documento y un
  `AiSkipReason` (`NOT_CONFIGURED`, `NO_TEXT`, `NOT_NEEDED`, `FAILED`, `RATE_LIMITED`,
  `BUDGET_EXHAUSTED`), en vez de un `null` pelado. Solo `BUDGET_EXHAUSTED` aplaza el correo; el
  resto va a revisión manual, que es lo que corresponde (D-57).
- **Aplazamiento** — `MessageProcessingState::DEFERRED`: un estado que **no** es pendiente ni
  terminal. El correo no se marca como procesado (el tiempo medio mediría los días de espera) y
  lleva el motivo en el evento de transición (`skip_reason`).
- **Liberación** — `ReleaseDeferredMessages` devuelve los correos aplazados a `CANDIDATE` y los
  reencola. Se dispara al subir el tope y también a mano desde el panel. El módulo `Ai` no sabe
  qué es un correo aplazado: declara el puerto `AiBudgetChangeHandlerInterface` y `Processing` lo
  implementa (D-58).
- **Límite de peticiones** — `AiCallRateLimiter` con `RateLimiterFactory`: una ventana deslizante
  por organización, con límite propio y configurable. Evita que un bucle de reintentos dispare una
  factura.
- **Libro mayor** — `AiUsageRepository` completa: agregaciones por proveedor, por necesidad y por
  nivel; resumen de llamadas, tokens y fallos; y la media de latencia, calculada en SQL con la
  función DQL `SECONDS_BETWEEN` (`src/Shared/Infrastructure/Doctrine/Dql/`), que devuelve siempre
  segundos y no un intervalo.
- **Informe de coste** — `AiCostCalculator` cruza el libro mayor con los mensajes procesados y
  produce el `AiCostReport`: coste total, desglose por proveedor y por necesidad, llamadas y
  latencia por nivel, porcentaje de mensajes resueltos sin IA, tasa de escalado a IA avanzada,
  tiempo medio por mensaje y **correos aplazados**.
- **Panel de observabilidad** — `AiCostController` (`/ai/cost`, `ROLE_USER`): la foto del mes, con
  la barra de presupuesto, el desglose por necesidad y por nivel, la lista de correos aplazados y
  el botón de volver a ponerlos en cola.
- **Ajustes de presupuesto** — `AiSettingsController` (`/ai`): la pantalla de ajustes de IA
  incluye el tope (en euros, vacío = sin tope) y el modo, y avisa por *flash* cuando se sube el
  tope y se sueltan los correos aplazados.
- **Avisos de coste** — `GenerateCostAlerts` genera los avisos `ai_budget_warning` y
  `ai_budget_exceeded`, uno por mes, y **reabre** el aviso que el sistema había resuelto si la
  situación se repite dentro del mismo mes. Vive fuera del barrido general de alertas: el
  presupuesto lo lleva otro módulo (D-58).
- **Estadísticas del pipeline** — `EmailMessageRepository::extractionStats()`: mensajes del
  periodo, resueltos sin IA y resueltos con IA, que es lo que alimenta el porcentaje del panel.
- **Migración** — `Version20261022084337` crea la tabla `ai_budget` (aplicada en `app` y `app_test`).

### Correcciones relevantes de la fase

1. **La pantalla de coste y la de ajustes devolvían HTTP 500.** Las dos plantillas llamaban a
   `{{ money(x) }}` como si `money` fuera una función de Twig, cuando `MoneyExtension` la
   registra como **filtro**. La corrección fue reescribirlas como `{{ x|money }}` y borrar el
   «atajo» `money` que se pasaba a la plantilla, que ya no lo usaba nadie.
2. **El panel de coste también devolvía HTTP 500.** `AiUsageRepository` indexaba el resultado de
   sus agregaciones con `u.purpose` y `u.tier`, pero esas columnas están mapeadas con `enumType`:
   Doctrine las hidrata como objetos enum y `Cannot access offset of type AiPurpose on array`.
   Ahora se convierten a su valor antes de indexar.
3. **La tasa de escalado a IA avanzada era siempre 0.** Se calculaba sobre el total de
   extracciones del pipeline en vez de sobre las **llamadas de IA**, así que un panel con un
   100 % de escalado mostraba 0 %. Ahora el denominador son las llamadas y el numerador las de
   nivel avanzado.
4. **Los avisos de coste se perdían.** Cuando la situación se repetía dentro del mismo mes, el
   aviso que el sistema había resuelto no se reabría y el usuario se quedaba sin saber que
   seguía cerca del tope. `GenerateCostAlerts` reabre el aviso resuelto, porque la clave de
   deduplicación es única y no se puede crear otro.
5. **Los correos aplazados no se podían volver a poner en cola.** `DEFERRED` **no** está entre
   los estados pendientes, a propósito: un correo aplazado no debe reencolarse en cada
   sincronización, porque hasta que no haya presupuesto no va a poder avanzar. Hacen falta
   `findDeferred()` y `countByState()` en el repositorio, y un camino de liberación aparte.
6. **La puerta de IA seguía devolviendo `?ExtractedDocument`.** El cambio a `AiExtractionOutcome`
  (D-57) dejó la interfaz y sus dobles desalineados: la suite entera moría con un error fatal
  al cargar `RecordingAiExtraction`.
7. **`ChainResult::hasDocument()` miraba una variable que no existía.** Devolvía siempre `true`,
  porque la expresión era `null !== $document` en vez de `null !== $this->document`.
8. **La suite completa moría de agotamiento de memoria.** Con el límite de 128 MB del contenedor
  y un pico de ~125 MB, el resultado dependía del orden de los tests. `phpunit.dist.xml` fija
   ahora 512 MB.

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 1216 tests, 4591 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:yaml config/ --parse-tags` | ✅ |
| `bin/console lint:twig templates/` | ✅ |
| `bin/console doctrine:schema:validate` | ✅ |

## Fase 15 — Detección de cambios

> **Estado:** ✅ Completada.


**Objetivo:** detectar lo que ha cambiado y avisar.

- Cambio de precio detectado desde facturas nuevas.
- Cambio de plan.
- Posible duplicado (inferencia).
- Factura recurrente que deja de recibirse (inferencia).
- Servicio sin actividad reciente (inferencia).
- Todas las inferencias marcadas con `isInference` y presentadas como sugerencias.

**Verificación:** cada tipo de alerta tiene test con datos sintéticos; las inferencias se
muestran como sugerencias, no como hechos.

### Qué se entregó

- **Dos clases de propuesta** — `Discovery` distingue ahora lo que el sistema ha **leído** de un
  documento (un hecho: servicio nuevo, cambio de precio, cambio de plan) de lo que ha **deducido**
  del inventario (una inferencia: posible duplicado, cobro que no ha llegado, servicio sin
  actividad). La marca vive en una columna (`discovery.is_inference`, migración
  `Version20261022092002`) y no en el JSON de `proposed_data`, porque la interfaz la necesita para
  **filtrar** la bandeja y para **presentarla** como sugerencia (D-59).
- **La banda de confianza** — una inferencia nunca puede subir de la banda `LOW`
  (`DiscoveryConfidence::LOW->maxScore() = 54`). `markAsInference()` recorta la confianza si hace
  falta: una sospecha que el detector está seguro de tener no es un hecho, y su confianza tiene que
  decirlo.
- **Cambio de plan** — el pipeline lo detecta comparando el plan del documento con el del servicio
  (`ServiceMatchResult::$servicePlanName`, `ServiceMatcher::scoreAgainst()`). El plan lo extrae el
  **parser del proveedor** (`OvhParser::PLAN_PATTERN`), no el extractor determinista. Cuando en un
  mismo correo hay cambio de precio y de plan, **manda el plan**: cambiar de plan explica el
  precio, y avisar de los dos sería ruido.
- **Barrido de inferencias** — `DetectChanges` (`src/Discovery/Application/Change/`) recorre el
  inventario y llama a tres detectores:
  - `DetectDuplicates` — compara cada pareja de servicios: proveedor (45), importe (30),
    periodicidad (15), nombre (40, solo si la similitud es ≥ 85) y categoría (5), con **umbral 70**.
    El más antiguo es el original y el más reciente el sospechoso.
  - `DetectMissingCharges` — servicio `ACTIVE` con periodicidad recurrente, con el cobro esperado
    más de `graceDays` (7) de retraso y **ninguna** factura emitida desde `expectedAt - graceDays`.
    La fuerza de la señal crece con el retraso: `60 + intdiv(overdueDays, 2)`, tope 100.
  - `DetectDormantServices` — servicio `ACTIVE` sin facturas ni señales de vida (eventos `RENEWED`,
    `RECURRENCE_DETECTED`, `DOCUMENT_ADDED`) desde hace más de `dormantServiceDays` (180). Fuerza:
    `60 + intdiv(inactiveDays, 4)`.
- **Idempotencia** — el barrido se puede lanzar cada noche: `findByDedupKey()` busca la propuesta
  en **cualquier** estado. Las claves son `serviceId|expectedAt` (cobro que falta),
  `serviceId|inactiveSince` (servicio inactivo) y la pareja ordenada de ids (duplicado).
- **Confirmar una inferencia** — `ConfirmInference` aplica la acción que corresponde y **nunca
  borra historia**: el duplicado cancela el servicio más reciente y deja una nota en los dos; el
  cobro que falta adelanta `nextChargeAt` y deja nota; el servicio inactivo se cancela con nota.
  El historial (`ServiceEvent`) se conserva siempre, y la propuesta queda `CONFIRMED` con su
  entrada de auditoría.
- **Cinco avisos nuevos** — `PRICE_CHANGE_DETECTED`, `PLAN_CHANGE_DETECTED`, `POSSIBLE_DUPLICATE`,
  `MISSING_CHARGE` y `DORMANT_SERVICE` cuelgan de propuestas **pendientes**. `resolveStaleAlerts()`
  mantiene abierto cualquier aviso cuya propuesta siga pendiente: mientras el usuario no decida, el
  aviso sigue vivo.
- **Interfaz** — la bandeja (`/discoveries`) tiene filtros de **familia** (todas / hechos /
  sugerencias) y de **tipo**; las inferencias llevan la insignia «Sugerencia» y un texto que
  explica que **no** vienen de un correo. La ficha de una inferencia enseña la «fuerza de la
  señal» y ofrece dos salidas: «Sí, es eso» (aplica la acción) o «Descartar sospecha» (la explica
  y la aparta). Un hecho, en cambio, sigue mostrando el formulario de alta del servicio.
- **Comando** — `app:changes:detect` lanza el barrido y resume qué se ha propuesto.
- **Configuración** — `config/packages/discovery.yaml` con los umbrales (`missing_charge_grace_days`,
  `dormant_service_days`, `duplicate_amount_tolerance`, `duplicate_name_similarity`) y la clase
  `ChangeSettings` que los lee.

### Correcciones relevantes de la fase

1. **`DetectMissingChargesTest` moría con nueve `TypeError`.** El helper construía el servicio
   con `?DateTimeImmutable` y el test le pasaba cadenas; en PHP pasar un `string` a un parámetro
   tipado no es coerción, es un error de tipos.
2. **La señal del retraso no crecía.** El test comparaba `getConfidenceScore()`, pero
   `markAsInference()` recorta la confianza a la banda `LOW`, así que los dos retardos daban 54.
   Ahora se compara `getMatchScore()`, que es la fuerza bruta de la señal.
3. **El detector de servicios inactivos devolvía un `?DateTimeImmutable` que nunca era `null`.**
   La referencia de inactividad siempre existe (si no hay señal, es la fecha de alta), así que el
   tipo de retorno era engaños y la comprobación de `null` del llamante era código muerto.
4. **El par de servicios de prueba no llegaba al umbral.** Dos servicios con el mismo nombre pero
   sin proveedor compartido se quedan en 45 puntos (importe + periodicidad), por debajo del 70. La
   prueba ahora los registra con el **mismo proveedor**: es lo que hace que dos suscripciones
   apunten al mismo servicio.

**Verificación ejecutada al cerrar la fase:**

| Comprobación | Resultado |
|---|---|
| `php bin/phpunit` | ✅ 1291 tests, 4807 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:twig templates/` | ✅ |
| `bin/console doctrine:migrations:status` | ✅ al día en `app` y en `app_test` |

## Fase 16 — Hardening de seguridad y RGPD

> **Estado:** ✅ Completada.


**Objetivo:** cumplir `SECURITY.md` de punta a punta.

- Exportación de datos (`ExportOrganizationData`).
- Borrado de organización y de cuenta de correo con cascada completa, incluido el conocimiento
  aprendido del buzón.
- Purga programada según política de retención (correo, documentos, datos estructurados y
  metadatos de procesamiento por separado).
- Revisión de cabeceras, CSRF, rate limiting y validación de subidas.
- Auditoría completa de acciones sensibles, incluidos los envíos a proveedores de IA.
- Revisión de dependencias y de secretos.
- Tests de aislamiento ampliados a todos los módulos.
- Revisión de las afirmaciones de privacidad publicadas: **solo se afirma lo que la
  implementación y los proveedores sostienen** (D-25).

**Verificación:** checklist de `SECURITY.md` §11 completo; exportar y borrar datos funciona;
ningún secreto en logs.

### Qué se entregó

#### 1. Los tres puertos de privacidad

La política de datos no puede vivir en el módulo que la usa: el borrado de una organización
afecta a siete módulos y cada uno sabe cómo tirar sus propias filas. El patrón es el inverso
del que se suele usar: **el módulo publica un puerto, el agregador lo llama**.

| Puerto | Responsibility |
|---|---|
| `Shared/Application/Privacy/OrganizationDataProviderInterface` | Declarar qué datos exporta el módulo y cuántos hay. |
| `Shared/Application/Privacy/OrganizationDataRemoverInterface` | Declarar cómo borra el módulo sus datos. |
| `Shared/Application/Privacy/RetentionPurgerInterface` | Declarar cómo purga el módulo según antigüedad. |

Cada módulo implementa los que le tocan (`src/*/Application/Privacy/`) y los agregadores de
`src/Privacy/Application/` (`ExportOrganizationData`, `DeleteOrganizationData`,
`PurgeExpiredData`) los coordinan. El agregador no sabe nada de los módulos: solo conoce
los puertos. Añadir un módulo nuevo es añadir sus implementaciones, sin tocar la orquestación.

#### 2. Exportación de datos

`ExportOrganizationData` devuelve un `DataExport` con la lista de secciones y el número de
elementos de cada una. La interfaz (`/privacy`) ofrece dos formatos:

- **JSON** (`/privacy/export.json`) — un único documento con todas las secciones.
- **CSV** (`/privacy/export/{seccion}.csv`) — una sección por fichero, con cabeceras y filas
  aplanadas (los campos JSON se serializan en una celda).

Ambos formats se generan en memoria; no hay exportación asíncrona porque el volumen de una
organización (servicios, correos, documentos, alertas) cabe de sobra en el límite de memoria
y la respuesta es inmediata.

#### 3. Borrado de organización

`DeleteOrganizationData` pide a cada módulo que borre sus filas y devuelve un `RemovalReport`
con el detalle por módulo. El borrado es **completo y en cascada**: servicios, documentos y
sus binarios, correos y eventos de procesamiento, conocimiento aprendido del buzón, propuestas,
avisos, integraciones (credenciales), uso de IA y datos de identidad.

> **El borrado se lleva la organización.** Es la única forma de que el borrado sea de verdad
> completo: si la organización sobreviviera, sus credenciales, sus correos y sus documentos
> seguirían siendo recuperables por un `organization_id` que ya no apunta a nada. Ver D-61.

El hueco que dejó esa decisión se rellenó: un usuario al que se le borra la organización se
queda **sin organización**, y el panel lo lleva a crear una nueva
(`/organization/new`, `CreateOrganization` + `OrganizationFormType`). `ActiveOrganizationListener`
cae al primer `memberships[0]` cuando la organización de la sesión ya no le pertenece, de modo
que la aplicación nunca se queda en un estado imposible («tengo sesión pero no tengo
organización»).

#### 4. Purga por política de retención

`config/packages/privacy.yaml` declara los plazos **por categoría de dato**, no por módulo, y
`PrivacySettings` los interpreta. Los plazos:

| Categoría | Plazo | Por qué |
|---|---|---|
| Correo (mensajes y eventos) | 365 días | Los metadatos solo sirven para reconstruir el origen de una propuesta. |
| Documentos (binario) | 30 días | El borrado lógico deja el binario un mes por si el usuario se arrepiente. |
| Propuestas ignoradas | 90 días | Ya están resueltas; se conservan por si se quiere revisar qué se descartó. |
| Avisos resueltos | 365 días | Es el histórico de lo que avisó al usuario. |
| Libro de auditoría | 730 días | Trazabilidad sin guardarlo para siempre. |
| Uso de IA | 730 días | Los importes agregados sobreviven para las métricas. |
| Conocimiento del buzón | 365 días | Si no se usa en un año, ya no identifica a nadie. |
| Caché de extracción | 30 días | Solo sirve para no repetir trabajo. |

`app:privacy:purge` ejecuta la purga (es idempotente) y `PurgeExpiredData` devuelve un
`PurgeReport` con el detalle por categoría. La purga va **en tandas** (`purge_batch_size`),
porque PostgreSQL no admite `LIMIT` en un `DELETE` y un borrado masivo bloquearía la tabla.

#### 5. Cabeceras de seguridad

`SecurityHeadersListener` añade en cada respuesta:

| Cabecera | Valor |
|---|---|
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'nonce-…'; frame-ancestors 'none'; object-src 'none'; form-action 'self'; base-uri 'self'` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (solo sobre HTTPS) |

La CSP **no** usa `unsafe-inline`: cada respuesta lleva un **nonce** aleatorio
(`SecurityHeadersListener::NONCE_ATTRIBUTE`) que la plantilla lee con la función Twig
`csp_nonce()`. Sin nonce no hay script en línea; con nonce, solo el script de esa respuesta.

> **Excepción conocida:** en modo `debug`, Symfony 8 **elimina la CSP de las páginas de error**
> (`ErrorListener::removeCspHeader()` marca la petición con `_remove_csp_headers`). En
> producción (`debug = false`) la CSP se mantiene también en los 404. El resto de cabeceras
> sí se aplican en las páginas de error.

#### 6. Rate limiting

`RateLimitGuard` es la puerta de frecuencia compartida, con la clave **por organización**
(para que una organización no pueda agotar el límite de otra). Los limitadores viven en
`config/packages/rate_limiter.yaml`; los de prueba, en `config/packages/test/rate_limiter.yaml`
(que replica los límites reales de producción en lugar de subirlos: un test que prueba un
límite de 1000 no prueba nada).

| Limitador | Dónde | Objetivo |
|---|---|---|
| `login` | Formulario de acceso | Frenar fuerza bruta. |
| `password_reset` | Formulario de recuperación | Frenar spam y enumeración. |
| `document_upload` | Subida de documentos | Frenar abuso de almacenamiento. |
| `privacy_export` | Exportación de datos | Frenar scraping de los datos de la organización. |
| `email_account` | Alta de cuentas de correo | Frenar abuso de credenciales IMAP. |

#### 7. Validación de contenido de ficheros

`UploadDocument` ya no confía en la extensión: valida el **tipo real** del fichero (magic
bytes), el tamaño máximo y el tipo MIME permitido antes de guardarlo. Un `.php` renombrado a
`.pdf` se rechaza.

#### 8. Auditoría

Las acciones sensibles (acceso, exportación, borrado, cambios de credenciales, envíos a
proveedores de IA) escriben en el libro de auditoría (`AuditLog`), que es append-only y se
pura por antigüedad, no por edición.

### Correcciones relevantes de la fase

1. **`IdentityDataProvider` reventaba con un 500.** Leía una clave `joinedAt` que la entidad
   no tiene; la clave real es `createdAt`. El panel de privacidad fallaba al exportar.
2. **`DataExport::rowCount()` lanzaba un `TypeError`.** Contaba filas sobre un array que ya
   era un recuento.
3. **El CSV no aplanaba los arrays.** Los campos JSON se escribían como `Array` en la celda.
4. **`fputcsv()` sin `$escape`** — deprecación en PHP 8.5.
5. **La purga de retención no purgaba nada.** Este era el fallo más grave y no lo delataba
   ningún error: las purgas seleccionan los identificadores y los borran con
   `DELETE ... WHERE id IN (:ids)`, y en **Doctrine ORM 3 `Query::getScalarResult()` devuelve
   filas asociativas** (`['id' => '…']`), no una lista plana de escalares. Los identificadores
   llegaban al `IN` como arrays, PHP convertía cada uno a la cadena `"Array"` y el `DELETE`
   no borraba ninguna fila. Ocho repositorios afectados (correo, eventos, auditoría, uso de IA,
   avisos, caché de extracción, conocimiento del buzón y descubrimientos): **la política de
   retención entera era inocua**. La corrección es `getSingleColumnResult()`
   (`HYDRATE_SCALAR_COLUMN`), y el helper `DoctrineRepository::scalarIds()` normaliza el
   resultado a `list<string>`.
6. **La CSP no llegaba a las páginas de login.** El layout de autenticación
   (`templates/layout/auth.html.twig`) no carga el importmap, así que el nonce nunca se
   generaba ahí. Ahora el nonce lo genera el listener y lo lee la plantilla cuando lo pide.
7. **El CSRF se validaba por origen, no por token.** Con el gestor sin estado de Symfony 8,
   el token **es** el nombre de la cookie, así que la comparación de tokens no dice nada: lo
   que protege de verdad es `isValidOrigin()`. Los tests que fallaban de forma confusa se
   arreglaron enviando `Origin`/`Referer` coherentes.

### Verificación ejecutada al cerrar la fase

| Comprobación | Resultado |
|---|---|
| `vendor/bin/phpunit` | ✅ 1349 tests, 4997 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:container` | ✅ |
| `bin/console lint:twig templates/` | ✅ |
| `bin/console doctrine:schema:validate` | ✅ |
| Exportación JSON y CSV | ✅ verificado en `PrivacyPanelTest` |
| Borrado de organización | ✅ verificado en `PrivacyPanelTest` y `PrivacyIsolationTest` |
| Aislamiento entre organizaciones | ✅ `PrivacyIsolationTest` (4 tests) |
| Cabeceras de seguridad | ✅ `SecurityHeadersTest` (8 tests) |
| Rate limiting real | ✅ `RateLimitTest` (6 tests) |
| Purga de retención effective | ✅ `RetentionPurgeTest` (6 tests, integración con la base de datos) |

## Fase 17 — Preparación SaaS

> **Estado:** ✅ Completada.


**Objetivo:** dejar el producto listo para planes y crecimiento.

- `EntitlementCheckerInterface` con implementación de límites (servicios, cuentas de correo,
  documentos procesados y **presupuesto de IA por plan**).
- Onboarding guiado (conectar correo o continuar sin él).
- Observabilidad de producto: métricas de sincronización, extracción, coste de IA y alertas.
- Backups y restauración documentados.
- Rendimiento: índices revisados, consultas del dashboard optimizadas.
- Documentación de operación y despliegue.

**Verificación:** los límites se aplican sin tocar el dominio; un backup se restaura en un
entorno limpio.

### Qué se entregó

#### 1. Planes y límites (D-64, D-65)

El plan es un **techo**, no una etiqueta. `OrganizationPlan` declara qué planes existen
(`free`, `pro`) y los límites viven en `config/packages/entitlements.yaml`, de modo que
cambiar un precio no exige un despliegue.

| Capacidad | Tipo | Gratis | Profesional |
|---|---|---|---|
| Servicios | límite | 3 | sin techo |
| Cuentas de correo | límite | 1 | 5 |
| Documentos | límite | 20 | 500 |
| Inteligencia artificial | interruptor | no | sí |
| Presupuesto de IA | límite (céntimos) | 0 | 10 000 |

`EntitlementSettings` **valida en el arranque** que todos los planes declaren todas las
capacidades: si alguien añade una capacidad al código y olvida la configuración, el
contenedor no arranca. Es un error de despliegue visible el primer día, no un límite que
desaparece en silencio.

La comprobación vive en el **caso de uso** (`QuotaGuard::assertWithinQuota()`), no en el
controlador, porque en la Fase 18 la API escribirá sin pasar por un controlador. Al agotarse
un límite se lanza `QuotaExceededException`, que en la web se convierte en un aviso con
redirección al módulo correspondiente y en la API será un `402 Payment Required`.

El cambio de plan es un caso de uso auditado (`ChangeOrganizationPlan`) y **no borra nada**:
bajar de plan deja intactos los servicios y los documentos; la organización simplemente no
puede añadir más. En esta versión el cambio lo hace el operador con `app:plan:set`; la
pantalla `/plan` informa del consumo.

#### 2. Guía de bienvenida (D-66)

`BuildOnboardingChecklist` calcula los pasos **en vivo** (¿hay buzón? ¿hay servicios? ¿el plan
ofrece IA?). Lo único guardado es «la guía está terminada». La guía no bloquea la
aplicación: solo redirige desde el panel, para que un cliente con datos ya cargados pueda
consultar una factura sin pasar por la explicación.

#### 3. Observabilidad de producto

`/observability` con cuatro bloques en el orden en que se depura un problema real:
sincronización, extracción, IA y avisos. El periodo (`24h`, `7d`, `30d`) va en la URL, para
que el enlace se pueda mandar a un compañero tal cual. Es un panel **operativo**, distinto
del panel de coste de IA (Fase 14), que es una pregunta **financiera** con otro periodo.

#### 4. Copias de seguridad y operación

`bin/backup.sh` (volcado `pg_dump` + documentos + manifiesto con sumas SHA-256 y poda) y
`bin/restore.sh` (verificación de suma, `--limpio` para rehacer la base de datos entera,
restauración de documentos, `cache:clear` y comprobación de migraciones). El runbook
completo está en `docs/operations.md`, con `make backup` y `make restore` como atajos.

#### 5. Rendimiento y esquema

Los 35 índices declarados en las entidades existen en la base de datos. Los que faltaban
para las consultas nuevas del panel se añadieron (`email_sync_run (organization_id,
started_at)` y `email_message (organization_id, received_at)`, migración
`Version20261023140000`). Se corrigió además una **deriva de esquema**: `organization.plan`
tenía `DEFAULT 'free'` en la base de datos y el mapeo no lo declaraba, así que cada
`migrations:diff` proponía un `ALTER TABLE` inútil. `doctrine:schema:validate` queda en OK.

### Defectos encontrados y corregidos al cerrar la fase

1. **`SyncActivity::failureRate()` tenía `this->runs` sin `$`.** PHPStan lo detectó como
   «Constant this not found»: el método habría petado en cuanto se le llamara. Es un bug
   real que ningún test cubría porque la plantilla no usaba esa tasa.
2. **El coste de IA se guardaba en céntimos y se redondeaba a 0** (D-68). Con 42 llamadas de
   IA el panel enseñaba «0,00 €». Ahora se guarda en **microcéntimos** (`AiCost`,
   `cost_micro` BIGINT) y el panel enseña 0,03 €.
3. **La demostración sembraba duraciones irreales** (275 111 ms por pasada, 85 500 ms por
   correo). Ahora son segundos verosímiles (12–39 s por sincronización, 2–8 s por correo).
4. **La demo no tenía ningún correo en estado fallido**, aunque su propia documentación
   prometía el camino «fallido». Con la tasa de fallos siempre a cero, el panel de
   observabilidad no enseñaba cómo se ve un error. Ahora hay un mensaje en `failed`.
5. **`demoMailbox()` devolvía `?EmailAccount` sin poder devolver `null`**, con una rama
   muerta en el llamante.
6. **Los porcentajes se pintaban como «8.3 %»** (`default_locale: en` con la interfaz en
   español). Ahora se formatean con `number_format(1, ',', '.')`.
7. **`doctrine:migrations:diff` usaba la fecha del contenedor** (2026-10-22), anterior a la
   de migraciones ya aplicadas. La migración de índices se renombró a mano a una fecha
   posterior.
8. **Doble descompresión gzip** en `restore.sh` (`gunzip -c | tar -xzf -`): «not in gzip
   format». Corregido a `tar -xf -`.
9. **`restore.sh` no encontraba la copia por prefijo corto**: ahora busca en `var/backups/`.
10. **`doctrine:migrations:status` mostraba «Available 17»** (artefacto cosmético por
    versiones con namespace). El script usa `migrations:migrate --dry-run`.
11. **Los límites del plan no se aplicaban** (D-70). El localizador de contadores de uso
    estaba indexado por el **nombre de la clase** del proveedor, no por la capacidad, así que
    el guard no encontraba ningún contador, el uso se leía como `0` y
    `assertWithinQuota()` **no lanzaba nada**: el plan gratuito habría permitido servicios,
    buzones y documentos ilimitados. El defecto era silencioso (la pantalla de consumo
    enseñaba «0 de 3» sin estar contando nada) y ningún test lo cubría. Corregido declarando
    la clave de cada proveedor con `#[AsTaggedItem(Capability::…)]`, y fijado con dos pruebas
    nuevas: `PlanTest` (funcional, de punta a punta) y `QuotaGuardTest` (unitario, con la
    tabla de decisiones).

### Verificación ejecutada al cerrar la fase

| Comprobación | Resultado |
|---|---|
| `vendor/bin/phpunit` | ✅ 1385 tests, 5175 assertions |
| `vendor/bin/phpstan analyse --no-progress` | ✅ 0 errores (nivel 8) |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | ✅ 0 ficheros pendientes |
| `bin/console lint:twig templates/` | ✅ 49 plantillas |
| `bin/console doctrine:schema:validate` | ✅ esquema sincronizado con el mapeo |
| Límites del plan aplicados en el caso de uso | ✅ `PlanTest` (9 tests), `QuotaGuardTest` (7 tests) |
| El plan bloquea un buzón que no cabe | ✅ `EmailAccountTest::testThePlanDoesNotAllowASecondMailbox` |
| Bajar de plan no borra datos | ✅ `PlanTest::testChangingThePlanDoesNotDeleteWhatAlreadyExists` |
| Guía de bienvenida calculada en vivo | ✅ `OnboardingTest` (5 tests) |
| Panel de observabilidad | ✅ `ObservabilityPanelTest` (6 tests) |
| Copia de seguridad y restauración en entorno limpio | ✅ probado de verdad: 4 organizaciones, 3 usuarios, 12 mensajes, 9 sincronizaciones, 18 servicios y el documento recuperado con su propietario |
| Semilla de demostración | ✅ `DemoSeedTest` (6 tests) |
| Aislamiento entre organizaciones | ✅ `PrivacyIsolationTest` (4 tests) |

## Fase 18 — API pública

> **Estado:** ✅ **Cerrada.** Código (`d840174`), correcciones de comportamiento
> (`ffa7127` y el commit de cierre), documentación en `docs/api/` (`8860e55`) y **35 tests
> funcionales** en `tests/Functional/Api/`. Ver «Defectos encontrados y corregidos» al final de
> la fase: se encontraron **seis**, y los seis están arreglados y cubiertos por un test.

**Objetivo:** que Suscripta sea **integrable en otros proyectos** que necesiten este servicio.

- Autenticación por **API key** por organización (credencial revocable, con ámbito y fecha de
  expiración), emitida desde el panel de integraciones.
- Endpoints JSON versionados (`/api/v1/…`), documentados con OpenAPI.
- Lectura de datos del producto: servicios, precios, próximos cobros, alertas, propuestas,
  documentos, métricas y coste de IA.
- Operaciones de escritura que aporten valor desde fuera: alta manual de servicio, carga de
  documento, confirmación de propuesta, forzar sincronización.
- Paginación, filtros, ordenación y códigos de error documentados.
- Límites de frecuencia **por clave de API**, independientes de los del backoffice.
- Webhooks de salida (opcional): avisar a otros sistemas de un cambio (cobro detectado, propuesta
  creada, presupuesto de IA agotado).
- Versión estable y política de deprecación.

**Verificación:** la documentación de la API permite integrarse sin leer el código; la clave de
API es revocable y auditable; los datos devueltos respetan el aislamiento por organización.

### Qué se entregó

#### 1. La credencial: `api_key`, no «un token más»

La API se autentica con una **clave propia**, emitida desde el backoffice. La tabla
`api_key` (migración `Version20261024090000`) guarda:

| Columna | Tipo | Para qué |
|---|---|---|
| `id` | UUID | Identificador de la credencial. |
| `organization_id` | UUID | Dueña. **Todo lo que devuelve la API es de esta organización y solo de esta.** |
| `name` | VARCHAR(100) | Nombre legible («Integración con el ERP de la oficina»). |
| `prefix` | VARCHAR(20) | Los 12 primeros caracteres de la clave, **en claro**, para reconocerla en una lista sin poder usarla. |
| `secret_hash` | VARCHAR(64) | HMAC-SHA-256 de la clave completa. **La clave nunca se guarda en claro.** |
| `scopes` | JSON | `["read"]`, `["read","write"]`. Se guardan como texto y no como enumeración porque el filtro de Doctrine no mapea enumeraciones dentro de un `json` (D-69). |
| `expires_at` | TIMESTAMP NULL | Caducidad opcional. `NULL` = sin caducidad. |
| `revoked_at` | TIMESTAMP NULL | Cuándo se revocó. `NULL` = vigente. |
| `last_used_at` | TIMESTAMP NULL | Última utilización. Solo se escribe si el registro de uso está habilitado. |
| `created_by_user_id` | UUID NULL | Qué usuario del backoffice la emitió. |
| `created_at` | TIMESTAMP | Cuándo se emitió. |

Índices: `idx_api_key_organization` (buscar por organización) y
`uniq_api_key_secret_hash` (garantiza que dos claves nunca compartan huella, y da el índice
único que necesita la búsqueda por secreto).

**La clave se genera en `ApiKeyFactory`:** `sk_` + 32 bytes aleatorios en base64url sin
relleno (`sk_` + 43 caracteres). 32 bytes son 256 bits de entropía: adivinar una clave es
buscar entre 2²⁵⁶ posibilidades, y no hay tabla de.lookup que valga. El prefijo `sk_` solo
sirve para que un humano reconozca de un vistazo que es una credencial y no un identificador.

**La huella se calcula con `hash_hmac('sha256', $secreto, $pepper)`, no con `password_hash`.**
Es una decisión deliberada y explica el diseño entero:

- Una API key se **presenta** en cada petición, muchísimas veces al día. `password_hash` con
  bcrypt está pensado para contraseñas: es deliberadamente lento, y a 300 peticiones por minuto
  por clave se notaría.
- Una API key se **busca** en la base de datos para autenticarla. Con bcrypt habría que leer
  **todas** las claves de la organización y compararlas una a una: O(n) por petición.
- Con HMAC, la huella es determinista: se busca con una única consulta indexada
  (`WHERE secret_hash = :hash`) y se compara con `hash_equals`.
- El **pepper** (`API_KEY_PEPPER`, con respaldo `app.api.pepper_fallback`) vive **fuera** de la
  base de datos. Sin él, un volcado de la tabla `api_key` no basta para autenticarse; hay que
  tener además el secreto del despliegue. Es el mismo modelo que el pepper de contraseñas
  (`D-32`), aplicado a credenciales de uso masivo.

Consecuencia asumida: el pepper **no se puede rotar sin invalidar las claves**. Está
documentado en `docs/api/` y en la guía de operación, que explica cómo emitir claves nuevas
antes de rotar.

**Ámbitos (`ApiScope`).** Dos: `read` y `write`. `write` **cubre** `read` (`ApiScope::covers()`),
así que una clave de escritura no necesita llevar `read` explícitamente. La comprobación la
hace `ApiScopeVoter`, que responde a los atributos `SCOPE_READ` y `SCOPE_WRITE`; los
controladores lo declaran con `#[IsGranted('SCOPE_READ')]` o `#[IsGranted('SCOPE_WRITE')]`.
Un `403` por ámbito insuficiente **no** revela la existencia del recurso: la comprobación de
ámbito ocurre antes que la de propiedad del recurso.

**El panel del backoffice.** `/api-keys` (listado con nombre, prefijo visible, ámbitos,
caducidad, fecha de creación y última utilización), `/api-keys/new` (alta, con elección de
ámbitos y fecha de caducidad opcional) y `/api-keys/{id}/revoke` (revocación). La clave
completa se muestra **una sola vez**, en `/api-keys/created`, y no vuelve a estar disponible:
el servidor solo conserva la huella. Copiarla y guardarla es responsabilidad de quien la emite;
si se pierde, se revoca y se emite otra.

#### 2. El firewall: `api` es stateless y no toca la sesión

`config/packages/security.yaml` declara un firewall `api` para `^/api/v1`, **stateless** (sin
sesión, sin cookies, sin CSRF), con el `ApiKeyAuthenticator`. El backoffice sigue con su
firewall de sesión, con formulario de login y token CSRF: son dos mundos separados, y una
petición de API nunca arrastra una cookie de sesión.

`ApiKeyAuthenticator` acepta la credencial en **dos sitios**:

- `Authorization: Bearer sk_…` — lo que espera cualquier cliente HTTP moderno.
- `X-Api-Key: sk_…` — lo que sobrevive a algunos intermediarios (proxies, gateways) que
  borran la cabecera `Authorization`.

El prefijo `bearer ` se compara en minúsculas, así que `bearer`, `Bearer` y `BEARER` funcionan
igual. El autenticador usa `SelfValidatingPassport` (no hay contraseña que comprobar) y, al
validar, **fija la organización en el `TenantContext`**: a partir de ahí, todos los
repositorios filtran por esa organización. Es el mismo `TenantContext` que usa el backoffice, de
modo que **el aislamiento entre organizaciones es el mismo mecanismo en web y en API**, no dos
implementaciones que puedan divergir.

El `access_control` deja públicos `OPTIONS` y las rutas de documentación (`/api/doc`,
`/api/doc.json`): un integrador tiene que poder **leer el contrato antes de tener una clave**.

#### 3. Superficie de la API: 13 rutas en 8 controladores

Todas bajo `/api/v1`, todas con version explícita en la URL.

| Método | Ruta | Ámbito | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/plan` | `read` | Plan de la organización y consumo frente a los límites. |
| `GET` | `/api/v1/alerts` | `read` | Avisos de renovación y de presupuesto. |
| `GET` | `/api/v1/mail/accounts` | `read` | Cuentas de correo conectadas y su estado. |
| `POST` | `/api/v1/mail/accounts/{id}/sync` | `write` | Fuerza una sincronización. |
| `GET` | `/api/v1/charges/upcoming` | `read` | Próximos cobros. |
| `GET` | `/api/v1/services` | `read` | Listado de servicios (paginado). |
| `GET` | `/api/v1/services/{id}` | `read` | Un servicio concreto. |
| `POST` | `/api/v1/services` | `write` | Alta manual de un servicio. |
| `GET` | `/api/v1/proposals` | `read` | Propuestas pendientes. |
| `POST` | `/api/v1/proposals/{id}/confirm` | `write` | Confirma una propuesta y crea el servicio. |
| `GET` | `/api/v1/documents` | `read` | Documentos procesados (paginado). |
| `POST` | `/api/v1/documents` | `write` | Carga de un documento. |
| `GET` | `/api/v1/metrics` | `read` | Métricas del pipeline y coste de IA. |

Los controladores usan `#[MapRequestPayload]` y `#[MapQueryString]` (con
`validationFailedStatusCode: 422` explícito), de modo que la validación la hace el Validator de
Symfony y no código a mano. El DTO de alta de servicio (`CreateServiceRequest`) **recibe los
importes como texto** (`"12.50"`), no como número de coma flotante: un `float` de coma
flotante no puede representar `12.50` de forma exacta en binario, y convertirlo a euros a
partir de ahí acumula errores de redondeo. Mandar `12.5` (número JSON) es un `422` legítimo.

#### 4. Forma de las respuestas

Dos formas, y solo dos:

- **Colección**: `{"data": [ … ], "meta": { … }}`.
- **Recurso**: `{"data": { … }}`.

`meta` lo produce `Pagination::toArray()` y siempre lleva las mismas seis claves:
`page`, `per_page`, `total`, `total_pages`, `has_next`, `has_previous`. Los nombres van en
`snake_case`, como el resto de la API.

`Pagination` es una **clase compartida** (`src/Shared/Application/Pagination.php`), no una
clase de la API: el backoffice y la API paginan igual, y una clase con una regla de negocio
dentro de un módulo de infraestructura sería un problema de capas. `DEFAULT_PER_PAGE` es 25 y
`MAX_PER_PAGE` es 100: un `per_page` mayor se recorta, no se rechaza, para que un integrador
que pide `1000` reciba datos y no un error.

#### 5. Errores: `application/problem+json` (RFC 9457)

`ApiExceptionListener` convierte cualquier excepción en un documento `problem+json` con
`type`, `title`, `status`, `detail` y, cuando procede, campos extra. La clase `Problem`
tiene constructores semánticos (`unauthorized()`, `forbidden()`, `notFound()`,
`methodNotAllowed()`, `conflict()`, `paymentRequired()`, `unprocessable()`,
`tooManyRequests()`), con los textos **en español**, porque el producto está en español.

Códigos que la API devuelve:

| Código | Cuándo |
|---|---|
| `400` | Petición malformada. |
| `401` | Falta la credencial, no existe, ha caducado o está revocada. |
| `403` | La clave no tiene el ámbito necesario. |
| `404` | El recurso no existe **o no es de esta organización** (se indistinguen a propósito). |
| `405` | Método no permitido en esa ruta. |
| `402` | Se ha superado un límite del plan (`QuotaExceededException`); el cuerpo dice qué capacidad, cuál era el límite y cuánto se ha usado. |
| `409` | Conflicto de estado (por ejemplo, confirmar una propuesta ya confirmada). |
| `422` | El cuerpo no pasa la validación. Incluye `violations[]` con campo y motivo. |
| `429` | Se ha superado el límite de frecuencia. Incluye `retry_after`. |
| `500` | Error inesperado. El detalle **no** filtra información interna. |

El `401` de una clave caducada, revocada o inexistente dice **lo mismo**: «Falta la credencial o
no es válida». Un mensaje distinto para «no existe» y «está revocada» sería un oráculo para
 enumerar credenciales ajenas.

El aislamiento se respeta también en los errores: un `404` de un recurso de otra organización
es indistinguible de un `404` de un recurso inexistente.

#### 6. Límite de frecuencia: 300/min **por clave**

En `config/packages/rate_limiter.yaml`, política `sliding_window`, 300 peticiones por minuto.
La unidad de conteo es **la clave de API**, no la organización ni la IP, y el motivo está
escrito en el propio YAML: varias claves legítimas pueden salir del mismo servidor (NAT,
proxy, CI), y un límite por IP castigaría a un cliente por el comportamiento de otro. Y la
clave es la unidad que se puede **explicar**: «su clave `sk_a1B2…` ha superado el límite de
300 peticiones por minuto» es un mensaje accionable; «hemos limitado su IP» no lo es.

Los límites del backoffice (`inbound_email`, `ai_calls`, `mail_account`, `document_upload`,
`privacy_export`) siguen vigentes y son independientes: son por sesión, por organización o por
IP, según el caso.

#### 7. CORS

`ApiCorsListener` responde al preflight `OPTIONS` con los Allow-Headers y Allow-Methods
correspondientes. Los orígenes permitidos vienen de `API_CORS_ALLOWED_ORIGINS`, una **lista
JSON** en el entorno (no un texto), porque el listener tiene que compararlos uno a uno:

```
API_CORS_ALLOWED_ORIGINS=["https://integrador.example"]
```

Con la lista vacía (valor por defecto en `.env`) no se permite ningún origen: es la postura
correcta por defecto — un navegador no puede leer la respuesta de una API de credenciales desde
un sitio ajeno, salvo que alguien lo autorice explícitamente.

#### 8. OpenAPI: `/api/doc.json` y `/api/doc`

`nelmio/api-doc` con `path_patterns: ^/api/v1`: **solo se documenta la API versionada**. El
backoffice no es una API — es una aplicación con sesión —, y documentarlo como si lo fuera
daría una imagen equivocada de lo que un integrador puede llamar.

Los dos esquemas de autenticación (`bearerAuth` y `apiKeyAuth`) están declarados porque los
dos funcionan. `/api/doc.json` es el JSON OpenAPI 3 que consumen Postman, Dredd y los
generadores de clientes; `/api/doc` lo pinta con Swagger UI.

#### 9. Read models: la API no habla con Doctrine

La API **no** consulta entidades Doctrine directamente. Habla con interfaces de lectura
(`ServiceViewRepositoryInterface`, `AlertViewRepositoryInterface`,
`DocumentViewRepositoryInterface`, `DiscoveryViewRepositoryInterface`,
`EmailAccountViewRepositoryInterface`), y cada módulo declara su implementación en
`config/services.yaml`. Dos consecuencias:

1. La API puede devolver **exactamente los campos que el contrato promete**, sin arrastrar
   campos internos ni tener que serializar entidades con sus relaciones.
2. Un test puede sustituir la implementación por un doble, así que los tests de la API no
   necesitan base de datos.

### Defectos encontrados y corregidos

La fase se verificó de dos formas: con **peticiones HTTP reales** contra el contenedor (15
casos, `curl` de verdad) y con **35 tests funcionales** en `tests/Functional/Api/`. Entre las
dos aparecieron **seis defectos**. Ninguno era teórico: los seis se manifestaban como un fallo
visible para el integrador o para el operador del backoffice.

| # | Defecto | Dónde | Cómo se arregló | Commit |
|---|---|---|---|---|
| 1 | El preflight CORS devolvía `405` | `ApiCorsListener` | Prioridad en `REQUEST` de **9 a 40** | `ffa7127` |
| 2 | El `422` salía genérico, en inglés y sin `violations` | `ApiExceptionListener` | Desempaquetar la `ValidationFailedException` de `getPrevious()` | `ffa7127` |
| 3 | El arranque reventaba con el JSON del CORS | `.env.dev` | Comillas **simples** envolviendo el JSON | `ffa7127` |
| 4 | `violations[].field` usaba el nombre PHP | `ApiExceptionListener` | Traducir la ruta al nombre serializado | `ffa7127` |
| 5 | `match_reasons` llegaba como `["Array","Array"]` | `DiscoveryView` | Dejar de castear las razones a texto | cierre |
| 6 | El formulario de alta de clave daba `500` | `ApiKeyFormType` | `choices` con los casos del enum y `choice_value` | cierre |

#### Defecto 1 — El preflight CORS devolvía `405` en vez de `204`

**Síntoma.** `OPTIONS /api/v1/services` devolvía `405 Method Not Allowed` en vez de `204 No
Content` con las cabeceras de CORS. En un navegador, eso significa que **ninguna llamada
cruzada desde JavaScript funciona**.

**Causa raíz.** El `ApiCorsListener` estaba registrado con
`#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]`. En `KernelEvents::REQUEST`,
las prioridades son:

| Listener | Prioridad | Qué hace |
|---|---|---|
| `LocaleListener::setDefaultLocale` | 100 | Fija el locale por defecto. |
| `RouterListener::onKernelRequest` | **32** | Resuelve la ruta y **responde 405 si el método no existe**. |
| `LocaleAwareListener` | 15 | Aplica el locale de la ruta. |
| `ApiCorsListener` | **40** (antes 9) | Contesta al preflight. |
| `Firewall::onKernelRequest` | 8 | Autenticación. |
| `ApiRateLimitListener` | -8 | Límite de frecuencia. |

El router va con prioridad **32** y el listener de CORS iba con **9**: el router se ejecutaba
primero, no encontraba ninguna ruta `OPTIONS /api/v1/services` (las rutas solo aceptan `GET` y
`POST`), lanzaba `MethodNotAllowedHttpException` y **cortaba el ciclo de eventos**. El listener
de CORS no llegaba a ejecutarse nunca.

La hipótesis inicial era que el culpable era el firewall (que rechaza `OPTIONS` por exigir
autenticación), pero el firewall va con prioridad **8**, todavía por debajo. **El culpable era
el router**, y la lección es que la prioridad hay que compararla con la del router, no con la
del firewall: cualquier listener que tenga que contestar antes de que exista una ruta necesita
una prioridad **> 32**.

**Arreglo.** Prioridad **40**. Como `RequestEvent::setResponse()` llama a `stopPropagation()`,
en cuanto el listener de CORS fija la respuesta de preflight ni el router ni el firewall se
ejecutan: que es exactamente lo deseado, porque el preflight **no lleva credenciales** (por
diseño de CORS) y no puede autenticarse.

**Verificación.** `ApiCorsTest` (5 tests): preflight con origen permitido → `204` +
`Access-Control-Allow-Origin`; preflight con origen no permitido → sin `ACAO`; `OPTIONS` sin
`Origin` → `405` (no es un preflight, es un método que esa ruta no admite); y las cabeceras
también viajan en una respuesta real, incluido el `401`.

#### Defecto 2 — El `422` salía genérico y en inglés, sin `violations`

**Síntoma.** Un `POST /api/v1/services` con un campo inválido devolvía `422` con el mensaje
genérico de Symfony en inglés y **sin el array `violations`**, que es justo el campo que
permite a un integrador saber qué corregir.

**Causa raíz.** En Symfony 8, `RequestPayloadValueResolver` no lanza la
`ValidationFailedException` directamente: la **envuelve** en un `HttpException`:

```php
throw HttpException::fromStatusCode(
    $validationFailedCode,
    implode("\n", $mensajes),
    new ValidationFailedException($payload, $violations)
);
```

`HttpException::fromStatusCode(422, …)` devuelve una `UnprocessableEntityHttpException`, de
modo que la `ValidationFailedException` queda como **`previous`** de la excepción que llega al
listener. Y el `ApiExceptionListener` comprobaba:

```php
if ($exception instanceof ValidationFailedException) { … }
```

…condición que **nunca se cumplía**, porque la excepción que llega no es una
`ValidationFailedException`: es una `UnprocessableEntityHttpException`. El listener caía en la
rama genérica de `HttpExceptionInterface`, que construye el problema con
`Problem::make($status, Response::$statusTexts[$status], …)`: el `Response::$statusTexts` de
Symfony está **en inglés** («Unprocessable Entity») y no lleva `violations`.

**Arreglo.** `validationFailure()` recorre `getPrevious()` (con tope de 5 niveles, para no
colgarse si alguien construye una cadena circular) y devuelve la primera
`ValidationFailedException` que encuentre. El `problem+json` se construye con
`Problem::unprocessable($detalle, $violations)`, que ya sabe generar `violations[]` con el
título en español («Entidad no procesable»).

Nota: `MapRequestPayload::$validationFailedStatusCode` **ya vale 422 por defecto** en Symfony
8.1, así que el código de estado era correcto; lo que faltaba era el cuerpo. Los controladores
que usan `MapQueryString` sí declaran `validationFailedStatusCode: 422` explícitamente, para
que quede a la vista y no dependa del valor por defecto.

**Nota adicional ya aplicada.** El `default_locale` del framework era `en`, lo que hacía que
los mensajes del Validator salieran **en inglés** en una interfaz que está en español. Cambiado
a `es` en `config/packages/translation.yaml` y commiteado (`d840174`). Se comprobó que es
seguro: Twig no depende del locale para `number_format` (usa el `number_format()` nativo de
PHP) ni para `date` (usa `DateTimeInterface::format()`), y los catálogos `validators`, `form` y
`security` en español existen en `vendor/` y se registran solos.

**Verificación.** `ApiContractTest` comprueba el `422` con `violations[]`, el campo y el
mensaje **en español**, tanto para una regla de tipo como para una de rango.

#### Defecto 3 — El arranque reventaba con el JSON del CORS

**Síntoma.** Con el origen de prueba añadido a `.env.dev`, **todas** las peticiones devolvían
`200` con un cuerpo que no era JSON: la página de error de Symfony. El log decía
`Invalid JSON in env var "API_CORS_ALLOWED_ORIGINS": Syntax error` en
`EnvVarProcessor.php:297`.

**Causa raíz.** Symfony Dotenv **elimina las comillas dobles** del valor al parsear el
`.env`. `API_CORS_ALLOWED_ORIGINS=["https://integrador.example"]` llegaba al parámetro como
`[https://integrador.example]`, que **no es JSON válido** (las claves y las cadenas de un JSON
necesitan comillas dobles). Y el fallo no ocurría al usar el parámetro, sino **al arrancar**,
porque `%env(json:API_CORS_ALLOWED_ORIGINS)%` se resuelve al compilar el contenedor.

Es un fallo especialmente traicionero porque **`.env` ya tenía `API_CORS_ALLOWED_ORIGINS=[]`**
(sin comillas, y por tanto válido): el valor por defecto funcionaba, y el que rompía era la
sobrescritura de `.env.dev` — es decir, el fichero que solo existe en desarrollo.

**Arreglo.** Envolver el JSON en comillas **simples**, que Dotenv sí respeta:

```
API_CORS_ALLOWED_ORIGINS='["https://integrador.example"]'
```

Se comprueba con `bin/console debug:dotenv`. Queda documentado en un comentario dentro de
`.env.dev`, porque es el tipo de detalle que se vuelve a romper en el siguiente entorno que
alguien configure.

#### Defecto 4 — `violations[].field` usaba el nombre PHP, no el serializado

**Síntoma.** `POST /api/v1/services` con `billingIntervalCount` inválido devolvía
`violations[0].field = "billingIntervalCount"`, cuando en el JSON el campo se llama
`billing_interval_count`. Un integrador que use el `field` para resaltar el campo del formulario
no encontraría nada: **el contrato decía una cosa y el error decía otra**.

**Causa raíz.** El Validador de Symfony recorre **propiedades PHP**, así que el `propertyPath`
que produce (`billingIntervalCount`) es el nombre de la propiedad, no el nombre con el que el
`Serializer` la publica. La traducción entre los dos la hace `#[SerializedName]`, y el
`ApiExceptionListener` no la estaba aplicando: leía la ruta del validador y la publicaba tal
cual.

**Arreglo.** `serializedPath()` toma el primer tramo del `propertyPath`, reflexiona sobre la
clase del payload (`ValidationFailedException::getValue()`) y, si la propiedad tiene
`#[SerializedName]`, devuelve ese nombre. Solo se traduce la **propiedad raíz**: las rutas
anidadas se dejan igual, porque resolverlas exigiría recorrer el grafo de tipos y el contrato
actual no tiene ningún DTO con anidamiento serializado distinto.

Detalle de la implementación: `SerializedName` **no tiene getter**. Expone
`public readonly string $serializedName`. Se descubrió porque el primer intento llamaba a
`getSerializedName()` y reventaba.

**Verificación.** `ApiContractTest::testAValidationErrorCarriesViolationsInSpanish` comprueba
que el `field` es exactamente `billing_interval_count`.

#### Defecto 5 — `match_reasons` llegaba como `["Array","Array"]`

**Síntoma.** `GET /api/v1/proposals` publicaba `match_reasons: ["Array", "Array"]` en vez de
las razones del emparejamiento. La explicación de *por qué* se propone un servicio —lo que el
integrador enseña a su usuario y lo que justifica la propuesta— se perdía en el camino.

**Causa raíz.** `DiscoveryView::fromDiscovery()` mapeaba las razones con
`(string) $reason`. Pero `Discovery::$matchReasons` es
`list<array{signal: string, weight: int, detail?: string|null}>`: castear un array a `string` en
PHP da la cadena `"Array"` y emite un aviso de conversión. El tipo declarado en el DTO
(`list<string>`) era el que estaba mal, y PHPStan nivel 8 lo llevaba señalando desde que se
escribió el DTO: `Cannot cast array{signal: string, weight: int, detail?: string|null} to
string`.

**Arreglo.** Tipar la propiedad como la lista de estructuras que realmente es y **dejar de
castear**: `matchReasons: array_values($discovery->getMatchReasons())`.

**Verificación.** `ApiContractTest::testProposalMatchReasonsTravelAsStructuredObjects` crea una
propuesta con dos razones (una con `detail` y otra sin él) y comprueba que llegan como objetos
con `signal`, `weight` y `detail`, y que la segunda **no** tiene la clave `detail`.

#### Defecto 6 — El formulario de alta de clave daba `500`

**Síntoma.** Abrir `/api-keys/new` en el navegador devolvía `500`. Es decir: **el panel de
claves de API, la única forma de emitir una credencial, no se podía usar**.

**Causa raíz.** Dos errores en `ApiKeyFormType`, los dos por haber escrito el formulario sin
abrirlo nunca:

1. El campo `scopes` declaraba `choices` como un mapa etiqueta → **string**
   (`ApiScope::Read->label() => ApiScope::Read->value`) y a la vez un `choice_label` tipado
   `static fn (ApiScope $scope): string`. Symfony llama a `choice_label` con el **valor** de la
   opción, que era un `string`: `TypeError` → `500`.
2. El campo `expiresInDays` declaraba `placeholder_value`, **una opción que no existe** en
   `ChoiceType` (ni en Symfony 8 ni antes). `OptionsResolver` lanzaba
   `The option "placeholder_value" does not exist`.

**Arreglo.** Los `choices` son ahora `ApiScope::cases()` —los casos del enum, no un mapa— con
`choice_value: static fn (?ApiScope $scope): ?string => $scope?->value`, de modo que la
etiqueta y el valor salen del mismo sitio y no hay dos listas que se puedan desincronizar. Y se
quitó `placeholder_value`: con `required => false`, la opción vacía ya llega como `null`.

Consecuencia en el DTO: `ApiKeyFormData::$scopes` pasa a ser `list<ApiScope>` (antes
`list<string>` con un `toScopes()` que convertía a mano). El `ChoiceType` ya devuelve los casos
del enum, así que la conversión manual sobraba — y era justo la que ocultaba el problema de
tipos.

**Verificación.** `ApiKeyPanelTest` (5 tests) recorre el formulario de verdad: alta, la clave
se enseña **una sola vez** y no reaparece al recargar, el listado nunca contiene el secreto,
`write` arrastra `read`, y revocar marca la clave como revocada.

**Nota de método.** Los defectos 5 y 6 no los encontró la verificación E2E: los encontró
**PHPStan** (el 5) y **los tests del panel** (el 6). La verificación E2E solo recorrió la API
con `curl`; nunca abrió el panel en un navegador. La lección es que «lo probé y funcionaba»
significa «probé el camino que recorrí».

### Guarda anti-SSRF (D-77)

La deuda técnica número uno del proyecto, cerrada al terminar la fase en lugar de esperar a la 24.
Commit: `4ed28dd`.
La URL de un suscriptor ya no puede apuntar a la red interna del servidor: ni al loopback, ni a
`10.0.0.0/8`, ni a `169.254.169.254` (los metadatos de la nube, que es donde están las credenciales
de la máquina), ni a una IPv4 escondida dentro de una IPv6.

| Pieza | Fichero | Qué hace |
|---|---|---|
| La guarda | `src/Webhooks/Application/WebhookUrlGuard.php` | Esquema, resolución de **todas** las direcciones del host, 14 rangos IPv4 y 8 IPv6, IPv4-mapeadas, allowlist por host, literales IP |
| El puerto | `src/Webhooks/Application/Port/HostResolverInterface.php` | Aísla el DNS para que los tests no dependan de la red de la máquina |
| El adaptador | `src/Webhooks/Infrastructure/Http/DnsHostResolver.php` | A con `gethostbynamel`, AAAA con `dns_get_record` |
| El resultado | `src/Webhooks/Application/WebhookTarget.php` | `host` + `ip`: la dirección que se comprobó es la que se usa para conectar |
| El error | `src/Webhooks/Domain/Exception/UnsafeWebhookUrlException.php` | Cuatro mensajes: esquema, host ausente, destino interno, no resoluble |

Se aplica **dos veces**: al dar de alta o editar el suscriptor (`WebhookController`, con el error
colgado del campo `url`) y en cada entrega (`DeliverWebhook`), donde un destino interno **abandona
la entrega en el acto, sin reintentos**, porque esperar no arregla una dirección privada. La
conexión va con `resolve` apuntando a la IP validada y `max_redirects: 0`, para que ni un cambio de
DNS ni un `302` reabran el agujero.

Lo que **no** hace: rechazar un host que no resuelve. Dar de alta el receptor antes de desplegarlo
es lo normal, y en la entrega se comporta como cualquier fallo de red.

### Verificación de cierre

**Verificación E2E real**, con `curl` contra el contenedor en ejecución, usando claves de API
reales de la organización «Estudio Demo» (plan `pro`). **15 de 15 en verde:**

| Caso | Esperado | Resultado |
|---|---|---|
| `GET` sin credencial | `401` `problem+json` | ✅ |
| `GET` con clave inexistente | `401` `problem+json` | ✅ |
| `GET` con clave de lectura | `200` `application/json` | ✅ |
| `GET` con `X-Api-Key` en vez de `Authorization` | `200` | ✅ |
| `POST` con clave de solo lectura | `403` `problem+json` | ✅ |
| `POST` con clave de escritura, importe válido | `201` | ✅ |
| `POST` con importe como número JSON (`12.5`) | `422` + `violations[]` | ✅ |
| `GET` de un recurso inexistente | `404` `problem+json` | ✅ |
| `GET` de un recurso de otra organización | `404` (indistinguible) | ✅ |
| `OPTIONS` (preflight CORS) | `204` + cabeceras CORS | ✅ |
| `OPTIONS` con origen no permitido | sin `Access-Control-Allow-Origin` | ✅ |
| `GET` con clave caducada | `401` | ✅ |
| `GET` con clave revocada | `401` | ✅ |
| `GET` `/api/doc.json` | `200` OpenAPI 3 | ✅ |
| Cabeceras `X-RateLimit-*` en respuesta real | presentes | ✅ |

**Tests funcionales:** **35 tests, 526 aserciones**, todos en verde.

| Fichero | Tests | Qué fija |
|---|---|---|
| `ApiAuthenticationTest` | 7 | Sin credencial, inexistente, caducada, revocada, las dos cabeceras, el mismo cuerpo en los cuatro fallos. |
| `ApiScopeTest` | 4 | `read` no escribe, `write` sí, `write` cubre `read`. |
| `ApiIsolationTest` | 1 | Dos organizaciones: la una no ve el recurso de la otra (`404`). |
| `ApiCorsTest` | 5 | Preflight `204`, origen no permitido, `OPTIONS` sin `Origin`, cabeceras en respuestas reales. |
| `ApiContractTest` | 7 | `data`/`meta`, las seis claves de `meta`, paginación, `422` con `violations[]` en español, `field` serializado, `match_reasons` estructurado, `404` problem+json. |
| `ApiRateLimitTest` | 3 | El límite se agota exactamente en 300, cada clave tiene su ventana, cabeceras de cuota. |
| `ApiKeyPanelTest` | 5 | Alta desde el panel, el secreto se enseña una sola vez, no reaparece, `write` arrastra `read`, revocar. |
| `ApiOpenApiTest` | 3 | `/api/doc.json` responde, declara las rutas de `/api/v1` y los esquemas de seguridad. |

**Lints:** PHPStan nivel 8 limpio, `lint:twig` 52 ficheros OK, `lint:yaml --parse-tags` 38
ficheros OK, `lint:container` OK, `doctrine:schema:validate` OK, suite completa
(1 419 tests) en verde.

### Lo que queda fuera de esta fase

Queda **un solo** punto del alcance original fuera de esta fase: los **webhooks de salida**,
que pasan a la fase 20 con diseño propio. El motivo está en la sección siguiente.

El **panel de integraciones** —credenciales por proveedor y elección de IA **por necesidad** y
por organización— no es trabajo de esta fase porque **ya existe**: lo entregaron las fases 11
(`AiPurposeSelector`, elección de proveedor por necesidad) y 14 (control de coste y
observabilidad), y vive en `/integrations` (`IntegrationController`) y en
`src/Integrations/`. Esta fase solo añadió, encima de eso, la credencial de **API key** con la
que un sistema externo entra en Suscripta: son dos direcciones distintas de la misma idea —
Suscripta llamando hacia fuera con credenciales de terceros, y terceros llamándonos a nosotros
con una clave que emitimos aquí.

### Webhooks de salida: fuera de esta fase

El roadmap original mencionaba los webhooks de salida (avisar a otros sistemas de un cobro
detectado, una propuesta creada o un presupuesto de IA agotado) como **opcionales** dentro de
esta fase. **No se han implementado**, y la decisión es explícita:

- Un webhook de salida es una **fuente de peticiones salientes no autenticadas por el
  cliente**, con reintentos, firma, idempotencia y su propio almacén de entregas. No es un
  añadido al final de una fase: es un subsistema con su propio ciclo de vida, sus fallos y su
  observabilidad.
- El valor real de un webhook (no tener que consultar la API cada hora) **no depende de que
  exista**: con la API paginada, un integrador puede consultar cada X minutos y obtener
  exactamente la misma información.
- Meterlo aquí retrasaría el cierre de la API, que es lo que desbloquea la integración.

**Por tanto: los webhooks de salida pasan a la fase 20**, con su propio diseño (reintentos
exponenciales, firma HMAC del cuerpo, `Idempotency-Key`, tabla de entregas y su panel). La
API actual **no deja ningún hueco** para ellos: los eventos que dispararán ya son identificables
por su marca de tiempo y su estado, así que un webhook futuro no exigirá cambiar el contrato.

## Fase 19 — Cierre, documentación y publicación

> **Estado:** ✅ **Completada.** Commit `5821de0`.

**Objetivo:** que el proyecto se pueda entregar a alguien que no lo ha visto: instalar, entender,
usar e integrar.

**Lo que había antes de esta fase.** El código estaba terminado y documentado por dentro —1 900
líneas de roadmap, arquitectura, decisiones y seguridad—, pero **la puerta de entrada mentía**.
El `README.md` de la raíz empezaba describiendo Suscripta y a la línea 29 se convertía en el
README de `symfony-docker`, con sus insignias, su «Getting Started» y su licencia MIT. Y en medio
de esa mezcla afirmaba: *«Estado actual: documentación y arquitectura. No hay todavía código de
producto»*. Era falso desde hacía dieciocho fases: había 1 420 tests, 13 rutas de API y una
aplicación que funciona. Quien llegara al repositorio por primera vez leía, en el único
documento que iba a leer, que el proyecto no existía.

### Entregado

| Fichero | Qué es |
|---|---|
| `README.md` | Reescrito entero. Qué es Suscripta y qué problema resuelve, estado real (fases 0–19, 1 420 tests, PHPStan 8), arranque en cinco minutos con `make up` + `make migrate`, tabla de órdenes, cómo integrar la API con `curl`, índice de los doce documentos e índice del propio README. El contenido de `symfony-docker` queda al final, **etiquetado como infraestructura** y sin la afirmación falsa. |
| `CHANGELOG.md` | Formato Keep a Changelog. Una sección por fase (0–18) con `Añadido`/`Cambiado`/`Corregido`, fechadas con el commit real de cierre, y las seis correcciones de la fase 18 descritas por síntoma y arreglo, no por «se arregló». Incluye la convención para escribir la siguiente entrada. |
| `docs/deployment.md` | Guía del **primer** despliegue, complementaria a `docs/operations.md` (que cubre el día a día): requisitos, preparación del servidor, los dos niveles de secretos, orden del primer arranque, cinco comprobaciones de verificación y una tabla de los siete errores que se cometen la primera vez. |

### Corregido por el camino

Escribir la documentación de entrega obliga a leer lo que se documenta, y aparecieron dos
defectos:

| # | Defecto | Síntoma | Causa raíz | Arreglo |
|---|---|---|---|---|
| 1 | El botón «rellenar credenciales de demo» del login no hacía nada | En desarrollo, al pulsarlo no pasaba nada; ningún error visible | `templates/security/login.html.twig` llamaba a `document.getElementByById(...)` —`ByById` en lugar de `ById`—, que devuelve `null` y el `if (!button) return;` abandonaba en silencio | `getElementById` |
| 2 | `docs/DECISIONS.md` D-71 tenía una frase rota y una referencia imprecisa | «tendría que(handle cookies»; el panel citado era «el de integraciones» | Frase a medio traducir de un borrador, y el panel real es `/api-keys`, no el de integraciones (que es el de IA) | Frase reescrita; referencia corregida a `/api-keys` |

El primero es exactamente el tipo de defecto que ningún test de PHP detecta y que solo aparece al
usar la aplicación con las manos: la documentación de entrega lo encontró porque obligó a
recorrer el flujo de arranque paso a paso.

### Lo que se decidió no hacer

- **Capturas de pantalla en el README.** La especificación de la fase las pedía. No se han
  añadido: versionar binarios que cambian con cada retoque de interfaz envejece mal y nadie los
  actualiza. El README describe las pantallas con texto y remite a la aplicación, que es la
  fuente de verdad. Si algún día hay un pipeline de capturas automáticas, se añaden sin cambiar
  nada más.
- **`composer install --no-dev` en el servidor.** La especificación lo mencionaba como paso de
  despliegue. No aplica: en este proyecto la imagen de producción se construye con el target
  `frankenphp_prod` del `Dockerfile` y se publica en un registro. En el servidor **no hay PHP ni
  Composer**, y por tanto no hay nada que instalar. `docs/deployment.md` documenta el
  procedimiento real, no el genérico.
- **Duplicar `docs/operations.md`.** La guía de despliegue no repite el runbook: separa lo que se
  hace una vez (esta guía) de lo que se hace siempre (el runbook) y se enlazan entre sí.

### Verificación de cierre

| Comprobación | Resultado |
|---|---|
| Suite completa | **1 420 tests**, 5 664 aserciones, en verde |
| PHPStan nivel 8 | Sin errores |
| `php-cs-fixer --dry-run` | Sin cambios pendientes |
| `lint:twig templates/` | 52 plantillas correctas |
| `lint:yaml --parse-tags config/` | 38 ficheros correctos |
| `lint:container` | Sin errores |
| `doctrine:schema:validate` | Mapeo y esquema sincronizados |
| Enlaces del README | Los doce documentos enlazados existen; los 13 nombres de ruta de la API, también |

**Verificación de la fase:** alguien que no ha visto el proyecto consigue instalarlo, arrancar la
aplicación, autenticarse contra la API y entender cada respuesta sin leer el código. El README es
ahora el único documento que hace falta leer para empezar, y no contiene ninguna afirmación que
el repositorio no respalde.

## Fase 20 — Webhooks de salida

> **Estado:** ✅ **Completada.**

**Objetivo:** que un integrador no tenga que preguntar cada minuto si ha pasado algo. La API se
consulta; un webhook avisa. Es la diferencia entre gastar cuota y latencia en descubrir un hecho
y recibirlo resuelto y firmado.

**Lo que había antes de esta fase.** Un apartado en este mismo documento diciendo que los
webhooks eran «una idea, no un compromiso», y una pregunta abierta con la misma respuesta. La
API paginada cubría el caso de uso, así que nada se rompía por no tenerlos; lo que no había era
forma de reaccionar a un cobro sin sondear.

### Entregado

| Pieza | Fichero | Qué hace |
|---|---|---|
| Suscriptor | `src/Webhooks/Domain/Entity/Webhook.php` | Nombre, URL, secreto cifrado, eventos suscritos, activo/pausado, última entrega. `isSubscribedTo()` decide a quién le toca. |
| Entrega | `src/Webhooks/Domain/Entity/WebhookDelivery.php` | Una fila por suscriptor y evento: cuerpo ya serializado, estado (`PENDING`/`DELIVERED`/`ABANDONED`), intentos, próximo intento, código de respuesta, último error y `Idempotency-Key` único. |
| Firma | `src/Webhooks/Domain/Service/WebhookSigner.php` | HMAC-SHA-256 de `"{timestamp}.{cuerpo}"`, cabecera `X-Suscripta-Signature: t=…,v1=…`, verificación en tiempo constante y tolerancia de 300 s. |
| Reintentos | `src/Webhooks/Domain/Service/RetryPolicy.php` | Ocho envíos: 30 s, 2 min, 10 min, 1 h, 6 h, 24 h y 24 h. Después, abandono. |
| Notificador | `src/Webhooks/Application/WebhookNotifier.php` | Crea una entrega por suscriptor activo y encola un mensaje. **Nunca lanza**: un webhook es una notificación, no una transacción. |
| Entrega | `src/Webhooks/Application/DeliverWebhook.php` | Un intento: entra en la organización con `runAs()`, firma, hace el `POST` con timeout de 10 s y 20 s de duración máxima, y guarda el resultado. |
| Cola | `src/Webhooks/Application/Message/DeliverWebhookHandler.php` | `#[AsMessageHandler]` sobre el transporte `webhooks`. |
| Mantenimiento | `src/Webhooks/UI/Command/MaintainWebhookDeliveriesCommand.php` | `app:webhooks:maintain`: reencola lo vencido y abandona lo caducado (más de 7 días pendiente). |
| Panel | `src/Webhooks/UI/Controller/WebhookController.php` + 4 plantillas | Listado, alta, secreto una sola vez, ficha con historial, revelar secreto, enviar prueba y borrar. |
| Migración | `migrations/Version20261028215037.php` | Tablas `webhook` y `webhook_delivery` con sus índices. |

### Eventos emitidos

| Evento | Dónde se emite |
|---|---|
| `service.created` | `CreateService` |
| `proposal.created` | `ProcessEmailMessage`, solo cuando la propuesta es nueva (el camino de deduplicación no anuncia nada) |
| `proposal.confirmed` | `ConfirmDiscovery` |
| `charge.detected` | `ConfirmDiscovery::applyToExisting()`, solo cuando el importe cambia de verdad |
| `ai.budget_exhausted` | `AiExtractionService`, **una vez por periodo** (ver abajo) |

### Las tres decisiones que costaron pensar

1. **Se llama a mano, no hay bus de eventos.** Introducir un bus para cuatro eventos obligaba a
   reescribir los casos de uso existentes. Cada caso de uso llama a `notify()` al final, con los
   datos que ya tiene. Es explícito y se ve en el diff; el precio es que hay que acordarse, y por
   eso cada llamada tiene su test. El parámetro es **opcional** (`?WebhookNotifier $webhooks =
   null`) para no romper la construcción manual de los casos de uso en los tests.
2. **El reintento manda los mismos bytes.** El cuerpo se serializa una vez y se guarda en la fila
   de entrega. Reintentar reutiliza esa cadena, así que la firma cubre exactamente lo que el
   receptor recibió la primera vez y `Idempotency-Key` no cambia. Serializar de nuevo en cada
   intento habría dado firmas distintas para el mismo evento, que es justo lo que la idempotencia
   existe para evitar.
3. **`max_retries: 0` en el transporte.** La política de reintentos vive en `RetryPolicy`. Si
   Messenger también reintentara, habría dos sistemas decidiendo a la vez y el receptor recibiría
   ráfagas que nadie ha diseñado.

Y una cuarta, que apareció al escribir el aviso de presupuesto: **`ai.budget_exhausted` se emite
una vez por periodo, no por correo**. `AiExtractionService` se ejecuta una vez por mensaje; sin
freno, un buzón con cien correos pendientes mandaría cien avisos idénticos el día que se agota el
presupuesto. El aviso se marca en `cache.app` con la clave del periodo y solo se marca si se ha
programado alguna entrega, para que quien se suscriba a mitad de mes se entere igualmente.

Están registradas como **D-73** (el cuerpo se serializa una vez), **D-74**
(`RetryPolicy` manda sobre Messenger), **D-75** (el secreto del webhook se cifra y es recuperable,
a diferencia de la clave de API), **D-76** (el aviso de presupuesto, una vez por periodo) y **D-77**
(la guarda anti-SSRF: qué URLs se rechazan, por qué se fija la IP y por qué no se siguen redirecciones).

### Defectos encontrados y corregidos al verificar

| # | Defecto | Síntoma | Causa raíz | Arreglo |
|---|---|---|---|---|
| 1 | El número de intentos no cuadraba con los envíos | Un test de la política esperaba abandono y obtenía «pendiente»; el recuento de intentos se quedaba en 7 tras 8 envíos | `abandon()` no incrementaba `attempts`, y `maxAttempts()` se leía como «envíos» cuando contaba **reintentos** | `abandon()` cuenta el intento que lo provoca; el método pasa a llamarse `maxRetries()` y se añade `maxDeliveries()` = reintentos + 1 |
| 2 | El test de cabeceras no encontraba `Idempotency-Key` | `Undefined array key` | `MockHttpClient` entrega las cabeceras como **lista de cadenas** `"Nombre: valor"`, no como mapa | Helper `headersOf()` que las vuelve a partir; el test comprueba el contrato real |
| 3 | El test funcional del panel usaba `https://127.0.0.1:1` | `422`: el formulario rechaza la URL | `Assert\Url(requireTld: true)` rechaza direcciones IP, y con razón: un webhook apunta a un dominio público | El test usa `https://host.docker.internal:1`, que resuelve dentro del contenedor y falla al instante (y está en la allowlist de `.env.test` desde D-77) |

### Lo que se decidió no hacer

- ~~**Guarda anti-SSRF.**~~ **Ya no se deja fuera.** Al cerrar la fase se decidió que no podía
  esperar a la 24 y se implementó en el acto: ver «Guarda anti-SSRF (D-77)» más abajo.
- **Entrega «exactamente una vez».** No existe sin coordinación con el receptor. Se ofrece «al
  menos una vez» con `Idempotency-Key` y se documenta cómo deduplicar.
- **Challenge de verificación de endpoint al dar de alta.** El botón «Enviar prueba» cumple esa
  función sin añadir un estado intermedio al suscriptor.
- **Webhooks de entrada.** Stripe (fase 24) tendrá su propia ruta de recepción, con su propia
  firma; no tiene nada que ver con esto.

### Verificación de cierre

| Comprobación | Resultado |
|---|---|
| Suite completa | **1 483 tests**, 5 904 aserciones, en verde |
| Tests nuevos | 6 del firmante, 4 de la política, 7 de la entrega, 11 del panel, 1 del aviso de presupuesto |
| PHPStan nivel 8 | Sin errores |
| `php-cs-fixer --dry-run` | Sin cambios pendientes |
| `lint:container` | Sin errores |
| `doctrine:schema:validate` | Mapeo y esquema sincronizados |
| Migración | Aplicada en `dev` y en `test` (20 migraciones) |
| `debug:router` | Las 7 rutas del panel registradas |

**Verificación de la fase:** un suscriptor se da de alta en el backoffice, recibe su secreto una
vez, pulsa «Enviar prueba» y ve el código de respuesta de su endpoint; a partir de ahí, crear un
servicio o confirmar una propuesta le llega firmado, con `Idempotency-Key`, y se reintenta solo
durante algo menos de dos días antes de abandonarse.

## Fase 21 — `Subscription` como entidad propia (planificada)

> **Estado:** ⬜ **Planificada.** Decidida con el usuario; no empezada.

**Objetivo:** separar «servicio contratado» de «suscripción» sin romper lo que ya funciona.

**Por qué ahora.** `Service` es hoy el término único del dominio (D-22 lo decidió así a
propósito). El catálogo comercial de la fase 22 y el cobro de la fase 24 necesitan distinguir el
**producto** que se vende de la **suscripción** que un cliente tiene a ese producto: un plan puede
cambiar de precio sin tocar las suscripciones vivas, y una suscripción tiene estado propio
(activa, impagada, cancelada) que no es el estado de un servicio detectado en el correo.

**Alcance.**

- Entidad `Subscription` con `organizationId`, `planId`, estado, periodo, fechas de alta y baja.
- Relación con `Service` **opcional y explícita**: un servicio detectado en una factura no es una
  suscripción del catálogo, y confundirlos es el error que esta fase evita.
- Migración con relleno de datos: los servicios con `source = MANUAL` que correspondan a un plan
  del catálogo se enlazan; el resto se quedan como están.
- Sin cambios en la API pública todavía (el contrato se amplía en la fase 24).

**Verificación:** el backoffice enseña servicios y suscripciones como dos listas distintas; los
tests existentes de `Service` siguen pasando sin tocarlos.

## Fase 22 — Catálogo comercial en el backoffice (planificada)

> **Estado:** ⬜ **Planificada.**

**Objetivo:** que un administrador pueda definir qué se vende —planes, precios, ciclos,
características— sin tocar código.

**Alcance.**

- Entidades `Plan`, `Price` y `Feature`, con ciclo (`monthly`/`yearly`), moneda e importe en
  unidades menores.
- Panel de catálogo: alta, edición, activar/desactivar, orden de presentación.
- Precios con historial: cambiar un precio **no** reescribe las suscripciones vivas.
- Los límites del plan (`config/packages/entitlements.yaml`) siguen siendo la fuente de verdad de
  las capacidades; el catálogo comercial no los duplica.

**Verificación:** se crea un plan con dos precios, se publica y aparece en la web corporativa
(fase 23) sin desplegar.

## Fase 23 — Web corporativa (planificada)

> **Estado:** ⬜ **Planificada.** Decidida con el usuario: **en la misma aplicación**.

**Objetivo:** que `https://suscripta…/` venda el producto. Hoy `/` redirige al login.

**Alcance.**

- `/` sirve la página pública **si no hay sesión** y el dashboard si la hay. Es una decisión
  explícita: dos aplicaciones separadas habrían duplicado el diseño y el despliegue.
- Once páginas: inicio, producto, cómo funciona, precios, integraciones, seguridad, preguntas
  frecuentes, contacto, aviso legal, privacidad y condiciones.
- Los precios se leen del catálogo (fase 22), no se escriben en la plantilla.
- Sin blog (descartado explícitamente).
- SEO mínimo: `title`, `description`, `canonical`, `sitemap.xml` y `robots.txt`.

**Verificación:** un visitante sin sesión recorre las once páginas, ve los precios reales del
catálogo y llega al alta; con sesión, `/` sigue siendo el dashboard.

## Fase 24 — Checkout con Stripe (planificada)

> **Estado:** ⬜ **Planificada.** Pasarela decidida: **Stripe**.

**Objetivo:** cobrar suscripciones del catálogo sin trabajo manual.

**Alcance.**

- Checkout de Stripe (sesión alojada) desde la web corporativa.
- Webhooks **de entrada** de Stripe: firma propia, idempotencia por `event.id`, y estados de
  suscripción (`active`, `past_due`, `canceled`) reflejados en `Subscription`.
- Facturas del cobro enlazadas a la organización, no a los servicios detectados en el correo.
- Reintento y conciliación: si un webhook se pierde, el estado se reconcilia consultando a Stripe
  en el siguiente arranque del worker, no esperando a que llegue otro.
- Cumplimiento: el cobro lo hace Stripe; Suscripta no almacena datos de tarjeta.

**Verificación:** alta desde la web, cobro en modo test, webhook recibido, suscripción activa en
el backoffice, y cancelación que se refleja al recibir el evento.

## Fase 25 — CSRF unificado con el gestor de sesión (planificada)

> **Estado:** ⬜ **Planificada.** Decidida con el usuario: **unificar**.

**Objetivo:** que todas las comprobaciones CSRF usen el mismo mecanismo.

**Por qué.** Hoy conviven dos: los formularios que dependen de la sesión (confirmar/descartar
descubrimiento, probar buzón) usan el gestor con sesión, y el resto tokens sin estado —el valor
por defecto de Symfony 8—. Dos mecanismos son dos formas de equivocarse, y en los tests se nota:
hay que leer el token de la página para cada POST manual.

**Alcance.** Configurar `framework.csrf_protection` con el gestor de sesión para toda la
aplicación, ajustar los formularios que hoy dependen del token sin estado y actualizar los tests
funcionales que hacen POST a mano.

**Verificación:** ningún formulario ni acción `POST` usa ya el token sin estado; la suite entera
en verde.

## Deuda técnica conocida

Lo que se sabe que está flojo y no justifica una fase propia.

| # | Deuda | Por qué se acepta | Cuándo duele |
|---|---|---|---|
| ~~1~~ | ~~**Sin guarda anti-SSRF en los webhooks**~~ — **resuelta** (D-77): la URL se valida al darla de alta y en cada entrega, la conexión se ata a la IP comprobada y no se siguen redirecciones | — | — |
| 2 | **Rotación del pepper de la API manual** | Son dos comandos y hoy hay pocas claves | Cuando haya muchas integraciones que migrar |
| 3 | **Ámbitos de API solo `read`/`write`** | Cubre el caso actual | Cuando un integrador pida escritura sin lectura |
| 4 | **La API comparte cuota con el backoffice** | Es el mismo plan | Si la API se vende como producto aparte |
| 5 | **Verificación con un buzón real pendiente** | La vertical está probada con un doble de IMAP | Antes de la primera venta: es la hipótesis del producto |
| 6 | **Sin capturas de pantalla en el README** | Envejecen mal fuera del repositorio | Cuando haya marketing |
| 7 | **Recarga en caliente de FrankenPHP desde un CDN** | Solo afecta a `dev` | Nunca, salvo auditoría de privacidad |

## Fuera del roadmap (explícitamente descartado)

- Contabilidad, ERP, conciliación bancaria, impuestos.
- Gestor documental genérico.
- Cliente de correo.
- CRM y facturación a clientes.
- App móvil nativa y extensión de navegador.
- Microservicios, event sourcing, CQRS de framework.
- Schema por tenant.
- Conexión bancaria (PSD2) en v1.
- OAuth de Gmail/Microsoft en v1 (la abstracción queda preparada; IMAP cubre ambos).

## Preguntas abiertas

Decisiones que estaban tomadas y funcionando pero que convenía revisar con el usuario. **Se
revisaron todas el 2026-10-28 y están resueltas**; se dejan aquí con la respuesta para que nadie
vuelva a abrir el mismo debate.

### Resueltas por el usuario

| # | Pregunta | Respuesta |
|---|---|---|
| 1 | ¿`Subscription` como entidad separada de `Service`? | **Sí, reintroducirla.** Es la fase 21. |
| 2 | ¿Recarga en caliente de FrankenPHP en desarrollo? | **Se mantiene.** Solo afecta a `dev` y el ahorro de tiempo es real. |
| 3 | ¿CSRF sin estado o con sesión? | **Unificar con el gestor de sesión.** Es la fase 25. |
| 4 | ¿`AGENTS.md` y `CLAUDE.md` en la raíz? | **Eliminar los dos.** No aportan al producto. |
| 5 | Orden de clasificación de documentos | **Corregido**: `INVOICE` antes que `RENEWAL_NOTICE`. Ver abajo. |
| 7 | ¿Rotación del pepper de la API? | **Manual por ahora.** Se automatiza cuando haya volumen. |
| 8 | ¿Bastan los ámbitos `read`/`write`? | **Se dejan como están.** Se amplían cuando un integrador los pida. |
| 9 | ¿La API tiene cuota propia? | **No: contador compartido con el backoffice.** Es el mismo plan. |
| 10 | ¿Webhooks de salida? | **Sí, ahora.** Es la fase 20, ya entregada. |
| 11 | ¿Guarda anti-SSRF en las URLs de webhook? | **Implementarla ya**, sin esperar a la fase 24. Hecho: D-77. |

### Resueltas y ya implementadas

**La clasificación de documentos estaba al revés.** `DeterministicExtractor::classify()` evaluaba
`RENEWAL_NOTICE` antes que `INVOICE`, así que una factura que menciona «se renovará el 1 de
noviembre» —que es como están escritas casi todas las facturas de suscripción— se tipaba como
`OTHER`. Era deliberado y estaba cubierto por un test, pero la justificación no se sostenía: el
tipo del documento lo decide lo que **es**, no lo que **anuncia**. El orden pasa a ser
`PRICE_CHANGE → PLAN_CHANGE → INVOICE → RECEIPT → RENEWAL_NOTICE → UNKNOWN`, el test que fijaba
el comportamiento anterior se renombra a
`testAnInvoiceThatAlsoAnnouncesItsRenewalIsStillAnInvoice`, y se añade
`testARenewalNoticeWithoutAnInvoiceIsStillARenewalNotice` para que el cambio no se lleve por
delante el caso legítimo.

**La guarda anti-SSRF de los webhooks** era la deuda técnica número uno y ya está implementada
(D-77): la URL se valida al dar de alta el suscriptor y otra vez en cada entrega, la conexión se
ata a la dirección comprobada para que un cambio de DNS no la convierta en interna, y la entrega
no sigue redirecciones. La lista de rangos prohibidos, los cuatro mensajes de error y la
allowlist de desarrollo están en `docs/api/webhooks.md` § «Qué URLs se rechazan».

**El panel de integraciones y la elección de IA por necesidad** ya existían desde la fase 11
(`/integrations`): credenciales cifradas por organización y un selector por propósito
(`EXTRACTION`, `SUMMARY`, …). No era una pregunta abierta; se comprobó al revisar.

### Siguen abiertas

1. **Verificación con un buzón real.** La vertical está probada de punta a punta con un doble de
   IMAP (`RecordingImapClient`). Falta ejecutarla contra un proveedor real (Gmail, Microsoft 365,
   hosting propio) para validar `WebklexImapClient` y la hipótesis de producto de
   `PRODUCT.md` §3.2. **Es la más importante de la lista**: valida la premisa del producto, no una
   funcionalidad.
2. **Qué pasa cuando un plan baja de nivel con suscripciones vivas.** La fase 24 introduce cobros
   y, con ellos, la pregunta de qué se hace con el exceso de servicios o de consumo al bajar de
   plan: ¿se bloquea el alta, se avisa, se recorta? No está decidido y conviene decidirlo **antes**
   de la fase 24, no durante.
3. **Precio del producto.** El catálogo (fase 22) permite fijar precios, pero no hay decisión de
   negocio sobre cuánto cuesta Suscripta ni qué incluye cada plan.
