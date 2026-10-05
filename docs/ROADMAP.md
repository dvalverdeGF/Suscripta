# Roadmap

> Estado: **propuesta inicial**. Fases pequeñas y verificables. Cada fase termina con algo
> demostrable y con criterios de aceptación explícitos.

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

> **Estado:** ⬜ Pendiente.


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

## Fase 10 — Extracción determinista y conocimiento de proveedores (niveles 3–4)

> **Estado:** ⬜ Pendiente.


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

## Fase 11 — IA económica y avanzada con validación (niveles 5–7)

> **Estado:** ⬜ Pendiente.


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

## Fase 12 — Service matching y descubrimientos (nivel 8)

> **Estado:** ⬜ Pendiente.


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

## Fase 13 — Aprendizaje del buzón

> **Estado:** ⬜ Pendiente.


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

## Fase 14 — Control de coste de IA y observabilidad

> **Estado:** ⬜ Pendiente.


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

## Fase 15 — Detección de cambios

> **Estado:** ⬜ Pendiente.


**Objetivo:** detectar lo que ha cambiado y avisar.

- Cambio de precio detectado desde facturas nuevas.
- Cambio de plan.
- Posible duplicado (inferencia).
- Factura recurrente que deja de recibirse (inferencia).
- Servicio sin actividad reciente (inferencia).
- Todas las inferencias marcadas con `isInference` y presentadas como sugerencias.

**Verificación:** cada tipo de alerta tiene test con datos sintéticos; las inferencias se
muestran como sugerencias, no como hechos.

## Fase 16 — Hardening de seguridad y RGPD

> **Estado:** ⬜ Pendiente.


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

## Fase 17 — Preparación SaaS

> **Estado:** ⬜ Pendiente.


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

Decisiones que están tomadas y funcionando, pero que conviene revisar con el usuario antes de
consolidarlas. Ninguna bloquea el avance del roadmap.

1. **`Subscription` como entidad separada (D-22).** El dominio usa `Service` como término
   único. Si en algún momento se quiere distinguir «suscripción de consumo» de «servicio
   contratado», habría que reintroducir la entidad. Hoy no aporta valor.
2. **Recarga en caliente de FrankenPHP en desarrollo.** La imagen de desarrollo inyecta un
   script desde un CDN, lo que choca con los principios de privacidad (`SECURITY.md` §1). Solo
   afecta a `APP_ENV=dev`; en producción no se carga. Alternativa: desactivarla y recargar a
   mano.
3. **CSRF sin estado (valor por defecto de Symfony 8) frente a CSRF con sesión.** Los
   formularios que dependen de la sesión (confirmar/descartar descubrimiento, probar buzón)
   usan el gestor con sesión; el resto, tokens sin estado. Unificar simplificaría las pruebas.
4. **`AGENTS.md` y `CLAUDE.md`.** Aparecieron en la raíz al instalar recetas de Symfony. Hay
   que decidir si se conservan, se adaptan al proyecto o se eliminan.
5. **Orden de clasificación de documentos.** `DeterministicExtractor::classify()` evalúa
   `RENEWAL_NOTICE` antes que `INVOICE`, de modo que una factura que menciona «se renovará»
   se tipa como `OTHER`. Es deliberado y está cubierto por una prueba, pero puede perjudicar
   el tipado de documentos.
6. **Verificación con un buzón real.** La vertical está probada de punta a punta con un doble
   de IMAP (`RecordingImapClient`). Falta ejecutarla contra un proveedor real (Gmail,
   Microsoft 365, hosting propio) para validar `WebklexImapClient` y la hipótesis de producto
   de `PRODUCT.md` §3.2.
