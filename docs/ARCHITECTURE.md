# Arquitectura

> Estado: **propuesta inicial**. Este documento describe la arquitectura objetivo antes de
> escribir código. No hay todavía entidades, migraciones ni infraestructura implementadas.

## 1. Principios rectores

1. **Un producto, una cosa bien hecha.** Todo lo que no contribuya a *descubrir y controlar
   servicios recurrentes, costes, renovaciones y cambios* queda fuera (ver `PRODUCT.md`).
2. **Monolito modular.** Una sola aplicación Symfony desplegable, dividida en módulos con
   límites explícitos. No microservicios, no event sourcing, no CQRS de framework.
3. **Determinista primero, IA después.** Las reglas de negocio críticas (cálculo de
   periodicidad, próximo cobro, comparación de precios, detección de duplicados) viven en
   código determinista y testeable. La IA se usa solo donde aporta valor real: clasificar
   texto libre y extraer datos de documentos no estructurados. La extracción es **por capas**
   (determinista → reglas → OCR → IA) y los proveedores de IA son **intercambiables**
   (ver §4.10 y `DECISIONS.md` D-24).
4. **Nada crítico sin supervisión.** El sistema propone; el usuario confirma. Ninguna
   suscripción, cambio de precio o cancelación se registra automáticamente si hay
   incertidumbre.
5. **Aislamiento por tenant desde la primera línea.** Toda entidad de negocio lleva
   `organization_id` y toda consulta se filtra por él.
6. **Abstracción solo donde hay una variación real prevista** (proveedor de correo,
   proveedor de IA, almacenamiento de documentos, canal de notificación). No se crean
   interfaces "por si acaso".
7. **El correo es una fuente, no el producto.** La conexión IMAP es una **capacidad
   estratégica de primera clase**, presente en la arquitectura desde el principio, pero la
   aplicación **no es un cliente de correo**. El dominio no depende de Gmail ni de ningún
   proveedor concreto (ver §4.10 y `DECISIONS.md` D-23).
8. **Privacidad como principio arquitectónico, no como marketing.** Solo lectura,
   minimización de datos, separación de correo/documentos/datos estructurados, cifrado de
   credenciales, borrado por cuenta, retención controlada y registro de accesos sensibles
   (ver `SECURITY.md`). **No se hacen afirmaciones de "100 % privado" ni "100 % UE"** hasta
   que la implementación y los proveedores utilizados permitan sostenerlas (D-25).

## 2. Stack técnico

| Pieza | Elección | Notas |
|---|---|---|
| Lenguaje | PHP 8.5 | La imagen base del repositorio es `dunglas/frankenphp:1-php8.5`. Ver `DECISIONS.md` (D-01). |
| Framework | Symfony 8.x | Instalado vía `composer create-project symfony/skeleton` en el primer arranque. |
| Servidor | FrankenPHP + Caddy | Ya configurado en el repositorio (worker mode, HTTPS automático, Mercure). |
| ORM | Doctrine ORM 3 + Migrations | Mapeo por **Attributes**. |
| Base de datos | PostgreSQL 16+ | `compose.yaml` fija `POSTGRES_VERSION=15` por defecto; se subirá a 16/17. |
| Mensajería | Symfony Messenger | Transporte Doctrine, varios transports + `failed`. |
| Vistas | Twig (SSR) + Turbo + Stimulus | Sin SPA. Ver §12 y `DECISIONS.md` (D-17). |
| Assets | **AssetMapper** (sin Node/npm) | `symfony/asset-mapper`. Evita un toolchain Node en la imagen Docker. |
| Tiempo real | **Mercure** | Ya incluido en la plantilla FrankenPHP. Progreso de sincronización en vivo. |
| Seguridad | Symfony Security | Form login + sesión. OAuth de correo es aparte. |
| Validación | Symfony Validator | Mensajes en español. |
| Identificadores | `symfony/uid` — **UUIDv7** | Ordenables temporalmente, buenos para índices. |
| Cifrado | `ext-sodium` (libsodium) | Cifrado de credenciales de correo. |
| Contenedores | Docker / Docker Compose | Ya presente. |

## 3. Estructura de módulos

Organización **por módulo de negocio**, no por tipo técnico. Dentro de cada módulo, las capas
existen solo si aportan valor; los módulos pequeños pueden ser planos.

```text
src/
├── Shared/            # Kernel transversal, sin lógica de negocio
│   ├── Domain/        # Value Objects: Uuid, Money, Currency, BillingPeriod, DateRange
│   ├── Application/   # Bus de comandos/consultas, Clock, TenantContext, Entitlements
│   └── Infrastructure/# Doctrine types, TenantFilter, Audit, Storage, Mailer
│
├── Identity/          # User, Organization, Membership, autenticación
├── Catalog/           # Provider, Category (catálogo global + entradas propias del tenant)
├── Services/          # Service, ServicePrice, ServiceEvent  ← núcleo del producto
├── Documents/         # Document, Invoice
├── Mailbox/           # EmailAccount, EmailMessage, EmailSyncCursor, EmailSyncRun, IMAP
├── Discovery/         # Discovery, matching, detección de recurrencia y de cambios
├── Notifications/     # Alert, Notification, NotificationPreference, canales
└── Dashboard/         # Consultas de lectura agregadas (sin entidades propias)
```

Cada módulo que lo necesite usa:

```text
<Module>/
├── Domain/            # Entidades, Value Objects, servicios de dominio, interfaces de repositorio
├── Application/       # Casos de uso (comandos/consultas), DTOs, handlers de Messenger
├── Infrastructure/    # Repositorios Doctrine, adaptadores (IMAP, IA, storage, mailer)
└── UI/                # Controladores, formularios, plantillas Twig
```

**Regla de dependencias:** `UI → Application → Domain`. `Infrastructure` implementa interfaces
declaradas en `Domain`/`Application`. Un módulo nunca accede a las entidades de otro módulo
directamente: usa su capa `Application` (casos de uso) o interfaces publicadas.

## 4. Modelo de dominio

### 4.1 Identidad y tenancy

| Entidad | Campos clave | Notas |
|---|---|---|
| `User` | id, email (único), passwordHash, roles, locale, timezone, notificationSettings, createdAt, lastLoginAt | |
| `Organization` | id, name, slug, ownerId, createdAt | Tenant. Se crea automáticamente al registrar un usuario. |
| `Membership` | userId, organizationId, role (`owner`/`admin`/`member`), createdAt | En v1 siempre un único `owner`. La tabla existe para no migrar después. |

**Decisión:** `Organization` existe desde v1 aunque en la primera versión sea 1:1 con el
usuario. Motivo y alternativas en `DECISIONS.md` (D-02).

### 4.2 Catálogo

| Entidad | Campos clave | Notas |
|---|---|---|
| `Provider` | id, organizationId (nullable), name, slug, aliases (json), website, logoPath, defaultCategoryId, isSystem | `organizationId = null` → catálogo global (OVH, Microsoft, GitHub…). No nulo → proveedor propio del tenant. |
| `Category` | id, organizationId (nullable), name, slug, color, icon, isSystem | Categorías por defecto: Software, Hosting, Telecomunicaciones, Seguros, Suministros, Marketing, Formación, Otros. |

### 4.3 Núcleo: servicios y precios

| Entidad | Campos clave |
|---|---|
| `Service` | id, organizationId, providerId (nullable), name, planName (nullable), categoryId (nullable), status, currency, billingPeriod, billingIntervalCount, startedAt, nextChargeAt, renewalAt, noticePeriodDays, autoRenews, commitmentEndAt, cancelledAt, paymentMethodLabel, notes, source, createdByUserId, createdAt, updatedAt |
| `ServicePrice` | id, serviceId, amountMinor, currency, billingPeriod, billingIntervalCount, validFrom, validTo (nullable), source, invoiceId (nullable), note, createdAt |
| `ServiceEvent` | id, serviceId, type, occurredAt, data (json), actorUserId (nullable), createdAt |

**`Service.status`**: `active`, `paused`, `cancelled`, `pending_review`.
**`Service.source`**: `manual`, `email_discovery`.
**`ServiceEvent.type`**: `created`, `price_changed`, `plan_changed`, `renewed`, `paused`,
`resumed`, `cancelled`, `document_added`, `discovered`, `note_added`.

**El precio es histórico, nunca un campo mutable.** El precio vigente es la fila de
`ServicePrice` con `validTo IS NULL`. Un cambio de precio cierra la fila anterior
(`validTo = fecha del cambio`) e inserta una nueva. Esto permite responder
*"este servicio ha subido un 19,8 % en el último año"* sin cálculos frágiles.

**`ServiceEvent` es un log append-only** del ciclo de vida del servicio. Alimenta la pregunta
*"¿qué ha cambiado?"* del dashboard y sirve de base para las alertas de cambio.

**`Renewal` no es una entidad en v1.** La renovación es un conjunto de propiedades del
`Service` (`renewalAt`, `noticePeriodDays`, `autoRenews`, `commitmentEndAt`) más un evento
generado. Ver `DECISIONS.md` (D-03).

### 4.4 Documentos y facturas

| Entidad | Campos clave |
|---|---|
| `Document` | id, organizationId, serviceId (nullable), invoiceId (nullable), originalFilename, storageDriver, storageKey, mimeType, sizeBytes, checksumSha256, type, source, emailMessageId (nullable), createdAt, deletedAt |
| `Invoice` | id, organizationId, serviceId (nullable), providerId (nullable), documentId (nullable), number (nullable), issuedAt, totalAmountMinor, currency, periodStart, periodEnd, paidAt (nullable), status, source, createdAt |

**`Document.type`**: `invoice`, `receipt`, `contract`, `other`.
**`Document.source`**: `email_attachment`, `email_body`, `manual_upload`.
**`Invoice.status`**: `pending`, `paid`, `failed`, `refunded`, `unknown`.

**`Payment` no es una entidad en v1.** Un cobro observado se representa como `Invoice` con
`paidAt` y `status = paid`. Se introducirá `Payment` solo si aparece una necesidad real
(pagos parciales, varios métodos por factura). Ver `DECISIONS.md` (D-04).

Los adjuntos de correo que decidamos conservar **se convierten en `Document`**; no existe una
entidad `EmailAttachment` separada.

### 4.5 Correo

| Entidad | Campos clave |
|---|---|
| `EmailAccount` | id, organizationId, provider, emailAddress, displayName, status, credentialsEncrypted, oauthTokenEncrypted (nullable), imapHost, imapPort, imapEncryption, imapUsername, lastSyncAt, lastSyncStatus, lastSyncError, createdAt, updatedAt, deletedAt |
| `EmailSyncCursor` | id, emailAccountId, folderName, uidValidity, lastSeenUid, lastSyncAt |
| `EmailSyncRun` | id, emailAccountId, startedAt, finishedAt, status, messagesSeen, messagesProcessed, messagesSkipped, discoveriesCreated, error |
| `EmailMessage` | id, organizationId, emailAccountId, folder, uid, messageId, threadId, fromAddress, fromName, toAddresses (json), subject, receivedAt, sizeBytes, hasAttachments, bodyHash, bodyExcerpt (nullable), processingStatus, classification, classificationConfidence, attempts, lastError, processedAt, createdAt |

**`EmailAccount.provider`**: `imap`, `forwarding`, `gmail`, `microsoft` (los dos últimos, reservados).
**`EmailAccount.status`**: `pending`, `active`, `error`, `disabled`.
**`EmailMessage.processingStatus`**: `pending`, `processing`, `processed`, `skipped`, `failed`.
**`EmailMessage.classification`**: `invoice`, `receipt`, `payment_confirmation`,
`renewal_notice`, `price_change`, `plan_change`, `expiration_notice`, `other`, `unknown`.

**Varias cuentas por organización (1:N).** `EmailAccount` pertenece a la organización, no al
usuario: una organización puede conectar **varias cuentas** (p. ej. la del titular, la del
asesor, una cuenta de facturación y una dirección de reenvío), y cada una se sincroniza de
forma independiente con su propio cursor y su propio estado. No hay límite estructural; el
límite, si lo hay, es de plan (`EntitlementCheckerInterface`, §4.9). Ver `DECISIONS.md` D-27.

**No se almacena el cuerpo completo del mensaje.** Solo metadatos, un hash del cuerpo y, como
mucho, un extracto corto (`bodyExcerpt`) cuando existe un descubrimiento que el usuario debe
revisar. Ver `SECURITY.md`.

**Deduplicación en dos niveles.** El mismo mensaje puede llegar por dos cuentas distintas (p.
ej. una factura reenviada a la cuenta de facturación y también presente en el buzón original),
así que la deduplicación no puede ser solo por cuenta:

- **Nivel de mensaje (por cuenta):** `UNIQUE (email_account_id, folder, uid)` y
  `UNIQUE (email_account_id, message_id)`. Evita reprocesar el mismo mensaje dentro de una
  cuenta.
- **Nivel de organización (entre cuentas):** la deduplicación de **documentos** por
  `checksumSha256` y de **descubrimientos** por proveedor + importe + fecha + periodicidad
  (`Discovery` es tenant-scoped, no cuenta-scoped). Evita proponer dos veces el mismo servicio
  porque llegó por dos buzones.

**Cursor de sincronización:** `EmailSyncCursor` guarda `uidValidity` + `lastSeenUid` por
carpeta **y por cuenta**. Si `uidValidity` cambia, el cursor se invalida y se reprocesa la
ventana acotada. Esto responde al requisito *"conocer hasta dónde se ha sincronizado cada
cuenta"*.

### 4.6 Descubrimientos

| Entidad | Campos clave |
|---|---|
| `Discovery` | id, organizationId, type, status, confidence, confidenceScore, proposedData (json), matchedServiceId (nullable), sourceEmailMessageId (nullable), detectedAt, reviewedAt, reviewedByUserId, resultingServiceId (nullable), notes |
| `DiscoveryEvidence` | id, discoveryId, emailMessageId (nullable), documentId (nullable), weight |

**`Discovery.type`**: `new_service`, `price_change`, `plan_change`, `cancellation`, `duplicate`.
**`Discovery.status`**: `pending`, `confirmed`, `edited`, `ignored`, `expired`.
**`Discovery.confidence`**: `high`, `medium`, `low` (derivado de `confidenceScore`).

`proposedData` es un JSON con la propuesta normalizada (proveedor, servicio, plan, importe,
moneda, periodicidad, próximo cobro, categoría sugerida). Se usa JSON deliberadamente: es una
propuesta en revisión, no una entidad de negocio, y su forma evolucionará con el extractor.

`DiscoveryEvidence` permite mostrar al usuario *por qué* el sistema propone algo (correo y
documentos de origen).

### 4.7 Alertas y notificaciones

| Entidad | Campos clave |
|---|---|
| `Alert` | id, organizationId, serviceId (nullable), type, severity, title, message, data (json), isInference, status, detectedAt, resolvedAt |
| `Notification` | id, organizationId, userId, alertId (nullable), channel, status, payload (json), sentAt, readAt, createdAt |
| `NotificationPreference` | id, userId, alertType, channel, enabled |

**`Alert.type`**: `upcoming_charge`, `upcoming_renewal`, `price_increase`, `plan_change`,
`new_service_detected`, `possible_duplicate`, `missing_recurring_invoice`, `cost_changed`,
`annual_renewal_soon`, `inactive_service`.
**`Alert.status`**: `open`, `acknowledged`, `dismissed`, `resolved`.
**`Notification.channel`**: `in_app`, `email` (ampliable a `push`, `slack`, `telegram`).

**Separación detección / entrega:** `Alert` es *qué ha pasado*; `Notification` es *cómo y a
quién se ha avisado*. Añadir un canal nuevo no toca el dominio: solo se registra un nuevo
`NotificationChannelInterface`.

**`Alert.isInference`** marca las alertas que son sugerencias (duplicados, servicio inactivo,
cambio de precio inferido) para que la UI las presente explícitamente como tales.

### 4.8 Auditoría

| Entidad | Campos clave |
|---|---|
| `AuditLog` | id, organizationId (nullable), actorUserId (nullable), actorType, action, targetType, targetId, metadata (json), ipAddress, userAgent, createdAt |

Append-only. Se escribe para acciones sensibles (ver `SECURITY.md`).

### 4.9 Límites de plan (SaaS)

**No contaminan el dominio.** Se define una interfaz en la capa de aplicación:

```php
interface EntitlementCheckerInterface
{
    public function can(Organization $organization, string $capability): bool;
    public function limit(Organization $organization, string $capability): ?int;
}
```

En v1 se registra una implementación `UnlimitedEntitlements`. Cuando existan planes, se
sustituye por una implementación basada en el plan contratado, sin tocar entidades ni casos
de uso.

### 4.10 Separación fuente / procesamiento / dominio / valor

El correo es una **fuente de información**, no el producto. La arquitectura mantiene cuatro
capas con dependencias en un solo sentido: el dominio **no conoce** el correo.

```text
FUENTE
Correo electrónico (IMAP genérico, reenvío)
  │  EmailAccount, EmailMessage
  ▼
PROCESAMIENTO
Clasificación / extracción / matching
  │  EmailClassifier, DocumentExtractor, ServiceMatcher, RecurrenceDetector
  ▼
DOMINIO
Servicios / costes / renovaciones
  │  Service, ServicePrice, ServiceEvent, Invoice, Document, Discovery
  ▼
VALOR
Alertas / histórico / previsiones
     Alert, Notification, dashboard, cálculos de coste
```

**Regla de dependencia:** `Mailbox` (fuente) puede depender de `Shared`; `Discovery`
(procesamiento) puede depender de `Mailbox`, `Documents` y `Services`; `Services` (dominio)
**no depende de `Mailbox` ni de `Discovery`**. Un `Service` creado a mano y uno creado por
descubrimiento son indistinguibles salvo por `Service.source`. Esto permite que el producto
funcione sin correo y que el correo se pueda sustituir o ampliar sin tocar el dominio.

### 4.11 Extracción por capas

La extracción **no asume que todos los documentos deban enviarse a una API externa de IA**.
Se aplica en cascada, de lo más barato y determinista a lo más caro y difuso, y **se detiene
en cuanto hay suficiente confianza**:

| Capa | Qué hace | Coste | Datos salen del sistema |
|---|---|---|---|
| 1. Determinista | Cabeceras, remitente, `message-id`, adjuntos, metadatos del PDF, texto embebido | Nulo | No |
| 2. Reglas | Patrones de proveedor, plantillas de factura, expresiones de importe/periodicidad, diccionario de proveedores conocidos | Bajo | No |
| 3. OCR | Solo cuando el documento es una imagen o un PDF sin capa de texto | Medio (CPU) | No |
| 4. IA | Solo cuando las capas anteriores no alcanzan el umbral de confianza: texto libre, plantillas desconocidas | Alto | **Sí, si el proveedor es externo** |

**Reglas de la capa de IA:**

- **Desactivada por defecto.** Requiere consentimiento explícito de la organización y un
  contrato de encargado de tratamiento (DPA) con el proveedor (D-08).
- **Proveedores intercambiables** mediante `DocumentExtractorInterface` /
  `AiProviderInterface`. Cambiar de proveedor (o pasar a uno autoalojado/europeo) no toca el
  dominio ni los casos de uso.
- **Minimización antes de enviar:** se envían solo los fragmentos necesarios, nunca el buzón
  completo ni el cuerpo íntegro de los mensajes.
- **Trazabilidad:** cada extracción registra qué capa la resolvió (`extractionMethod`), para
  poder medir cuánto valor aporta realmente la IA y reducir su uso.

**Objetivo de evolución:** poder migrar a procesamiento más privado o europeo (OCR y modelos
autoalojados) **sin reescribir el dominio**. La arquitectura lo permite porque el dominio solo
ve el resultado de la extracción, no cómo se obtuvo.

### 4.12 Vocabulario del dominio

El brief de producto nombra conceptos que no todos son entidades. Este es el mapeo explícito:

| Concepto | Representación en v1 | Notas |
|---|---|---|
| `EmailAccount` | Entidad | Cuenta de correo conectada (IMAP o reenvío). |
| `EmailMessage` | Entidad | Metadatos del mensaje; **no se guarda el cuerpo completo**. |
| `Document` | Entidad | Adjunto o documento conservado como evidencia. |
| `Discovery` | Entidad | Propuesta pendiente de revisión. |
| `Provider` | Entidad | Proveedor (OVH, Microsoft…), global o propio del tenant. |
| `Service` | Entidad | **Núcleo del dominio.** Es el servicio recurrente que se paga. |
| `Subscription` | **No es entidad separada** | Se modela como `Service` con `status = active` y periodicidad. Ver D-22. |
| `Invoice` | Entidad | Factura o recibo detectado. |
| `Payment` | **No es entidad en v1** | Un cobro es `Invoice` con `paidAt` + `status = paid`. Ver D-04. |
| `Renewal` | **No es entidad en v1** | Propiedades de `Service` (`renewalAt`, `noticePeriodDays`, `autoRenews`, `commitmentEndAt`) + `ServiceEvent`. Ver D-03. |

**Por qué `Service` y no `Subscription`:** en el segmento objetivo (autónomos y pymes) el
mismo objeto cubre suscripciones SaaS, hosting, dominios, seguros, suministros y cuotas
profesionales. "Suscripción" describe solo una parte y arrastra connotación de consumo. El
término de dominio es `Service`; en la interfaz se habla de "servicios" y "gastos recurrentes".

**Disparador de escisión:** si aparece la necesidad real de modelar contratos con múltiples
líneas, componentes o precios por usuario (p. ej. licencias por asiento), se introduce
`Subscription` como entidad hija de `Service`. No antes.

## 5. Casos de uso (capa de aplicación)

**Identity**
`RegisterUser`, `CreateOrganization`, `InviteMember` (post-v1), `UpdateProfile`.

**Services**
`CreateServiceManually`, `UpdateService`, `ChangeServicePrice`, `PauseService`,
`ResumeService`, `CancelService`, `DeleteService`, `AddServiceNote`.

**Documents**
`UploadDocument`, `AttachDocumentToService`, `DeleteDocument`, `DownloadDocument`.

**Mailbox**
`ConnectEmailAccount`, `TestEmailConnection`, `DisconnectEmailAccount`, `StartEmailSync`.

**Discovery**
`ReviewDiscovery` (confirmar / editar / ignorar), `ExpireStaleDiscoveries`.

**Notifications**
`GenerateAlerts` (programado), `SendNotification`, `MarkNotificationRead`,
`UpdateNotificationPreferences`.

**Dashboard (consultas)**
`GetDashboardSummary`, `GetUpcomingCharges`, `GetRecurringCost`, `GetPriceHistory`,
`GetRecentChanges`, `GetPendingDiscoveries`.

**Privacidad**
`ExportOrganizationData`, `DeleteOrganizationData`, `PurgeExpiredData`.

Los controladores **no contienen lógica de negocio**: validan la entrada, invocan un caso de
uso y renderizan. Las operaciones pesadas se delegan a Messenger.

## 6. Procesamiento asíncrono (Messenger)

### 6.1 Transports

| Transport | Uso | Notas |
|---|---|---|
| `async` | Trabajo general | Transporte Doctrine. |
| `mail_sync` | Sincronización de buzones | Concurrencia baja, respeta límites del servidor IMAP. |
| `mail_processing` | Procesado por mensaje | Alto volumen, reintentable. |
| `ai` | Llamadas a proveedores de IA | Concurrencia limitada y rate limit propio. |
| `notifications` | Entrega de avisos | Reintentos con backoff. |
| `failed` | Mensajes agotados | Inspección manual + alerta a administración. |

### 6.2 Pipeline

```text
SyncEmailAccountMessage          (IMAP)  ─┐
IngestForwardedEmailMessage      (reenvío)─┤
                                          ↓
ProcessEmailMessageMessage        (prefiltro determinista barato)
        ↓
ClassifyEmailMessageMessage       (DocumentClassifierInterface)
        ↓
ExtractAttachmentMessage          (descarga + Document + dedup por hash)
        ↓
ExtractInvoiceDataMessage         (InvoiceExtractorInterface)
        ↓
MatchServiceMessage               (ServiceDiscoveryInterface + reglas deterministas)
        ↓
DetectRecurrenceMessage           (periodicidad, próximo cobro, cambios de precio)
        ↓
CreateDiscoveryMessage            (Discovery + Alert + Notification)
        ↓
SendNotificationMessage
```

Las dos vías de ingesta (acceso al buzón y reenvío, ver `DECISIONS.md` D-21) convergen en
`ProcessEmailMessageMessage`: el resto del pipeline no distingue el origen.

Cada paso de extracción (`ClassifyEmailMessageMessage`, `ExtractInvoiceDataMessage`,
`MatchServiceMessage`) aplica las **capas de §4.11 en orden** y se detiene en cuanto alcanza
el umbral de confianza. El paso a IA es el último recurso y solo se ejecuta si la organización
lo ha autorizado.

### 6.3 Garantías

- **Idempotencia.** Cada mensaje lleva una clave natural (`emailAccountId:folder:uid`,
  `documentId`, `discoveryId`). Un middleware `DeduplicationMiddleware` consulta una tabla
  `processed_message (message_class, dedup_key, processed_at)` y descarta duplicados. Además,
  los handlers son idempotentes por diseño (comprobar estado antes de escribir).
- **Reintentos.** `retry_strategy` con backoff exponencial (3–5 intentos) y transporte
  `failed` al agotarse. Los errores permanentes (credenciales inválidas, formato no
  soportado) se marcan como no reintentables.
- **Tolerancia a fallos.** Un mensaje que falla no bloquea el resto del lote. Los errores se
  registran en `EmailMessage.lastError` / `EmailSyncRun.error`.
- **Observabilidad.** `EmailSyncRun` por sincronización, `EmailMessage.processingStatus` por
  mensaje, logs estructurados (Monolog) con `organizationId` y `emailAccountId`, y métricas
  de Messenger.
- **Backpressure.** Lotes acotados (`--limit`), `--time-limit` y `--memory-limit` en los
  workers, rate limiting en IMAP y en el transporte `ai`.
- **Sincronización acotada.** Nunca se recorre el buzón completo en cada sincronización: se
  parte del cursor y se limita la ventana inicial (por defecto, últimos 24 meses) y el número
  de mensajes por ejecución. El backfill histórico es progresivo y opcional.

## 7. Persistencia

- **PostgreSQL**, esquema único compartido. **Nunca schema por tenant.**
- **UUIDv7** como clave primaria, generado en la aplicación (`Uuid::v7()`), mapeado con un
  `Doctrine\DBAL\Types\Type` propio (`uuid_v7`).
- **Dinero:** entero en unidades menores (`amountMinor`, céntimos) + código ISO 4217.
  Nunca `float`. Value Object `Money` en `Shared\Domain`.
- **Fechas:** `timestamptz` en UTC. Fechas "de calendario" (próximo cobro, renovación) como
  `date` en la zona horaria de la organización.
- **Soft delete** solo donde la retención lo exige (`Document`, `EmailAccount`).
- **Índices mínimos:**
  - `service (organization_id, status)`
  - `service (organization_id, next_charge_at)`
  - `service_price (service_id, valid_from)`
  - `service_price (service_id) WHERE valid_to IS NULL` (precio vigente)
  - `email_message (organization_id, received_at)`
  - `email_message (email_account_id, folder, uid)` único
  - `email_message (email_account_id, message_id)` único
  - `discovery (organization_id, status, detected_at)`
  - `alert (organization_id, status, detected_at)`
  - `audit_log (organization_id, created_at)`

## 8. Multi-tenancy

- **Modelo:** base de datos única, esquema único, columna `organization_id` en toda entidad de
  negocio.
- **Defensa en profundidad:**
  1. `TenantContext` (servicio de request/worker) con la organización activa.
  2. **Doctrine Filter** (`TenantFilter`) que añade automáticamente
     `organization_id = :currentOrganization` a toda consulta de entidades tenant-scoped.
  3. Repositorio base que exige organización explícita en los métodos de escritura.
  4. El `organization_id` **nunca** se acepta del cliente: se deriva de la `Membership` del
     usuario autenticado.
  5. Tests de aislamiento que verifican que un usuario no puede leer ni escribir datos de otra
     organización (fallan si el filtro se desactiva).
- **Messenger:** el `organizationId` viaja en un *stamp* del envelope y se restaura en el
  `TenantContext` al consumir. Un handler sin tenant válido falla explícitamente.
- **Evolución posible:** Row Level Security de PostgreSQL como capa adicional, sin cambiar el
  modelo de datos.

## 9. Integraciones

Todas detrás de interfaces, con implementación por defecto y adaptadores alternativos.

| Interfaz | v1 | Futuro |
|---|---|---|
| `MailboxProviderInterface` | IMAP genérico (cliente PHP puro) + **ingesta por reenvío** | Gmail API, Microsoft Graph (OAuth) |
| `DocumentClassifierInterface` | Reglas deterministas (remitente, asunto, adjuntos) | Clasificador IA |
| `InvoiceExtractorInterface` | Parser determinista de PDF/texto cuando sea fiable | Extractor IA |
| `ServiceDiscoveryInterface` | Matching por proveedor + importe + periodicidad | Matching asistido por IA |
| `RecurrenceDetectorInterface` | Determinista (intervalos entre facturas) | — |
| `DocumentStorageInterface` | Local (`var/storage`) | S3 / Backblaze B2 / compatible |
| `NotificationChannelInterface` | `in_app`, `email` | push, Slack, Telegram |
| `AiProviderInterface` | Sin proveedor externo por defecto | OpenAI, Anthropic, Mistral, Ollama local |

**Nota técnica (IMAP):** `ext-imap` dejó de formar parte del núcleo de PHP en 8.4. Se usará un
cliente IMAP en PHP puro (p. ej. `webklex/php-imap`) para no depender de una extensión PECL.
Ver `DECISIONS.md` (D-05).

**No hay integración privilegiada.** Gmail y Microsoft 365 son **proveedores entre otros**, no
el camino principal. El camino principal es IMAP estándar, que cubre Gmail, Microsoft 365,
servidores propios, correo de hosting y proveedores corporativos. Las APIs propietarias
(Gmail API, Microsoft Graph) se añaden **después**, como adaptadores de `MailboxProviderInterface`,
y solo si aportan algo que IMAP no da (p. ej. notificaciones push de buzón). Ver D-23.

**Proveedores de IA intercambiables.** `AiProviderInterface` permite cambiar de proveedor —o
pasar a un modelo autoalojado— sin tocar el dominio. La IA está desactivada por defecto y su
uso se decide por capa de extracción (§4.11).

## 10. Cálculos de negocio

**Equivalente mensual** (normaliza cualquier periodicidad a un mes):

```text
monthly   → amount
weekly    → amount * 52 / 12
quarterly → amount / 3
semiannual→ amount / 6
annual    → amount / 12
biennial  → amount / 24
custom    → amount * (365.25 / intervalDays) / 12
```

**Coste anual estimado** = equivalente mensual × 12.
**Próximo cobro** = `nextChargeAt` si está fijado; si no, se calcula desde el último cobro
conocido + periodicidad, y se recalcula al cambiar precio o periodicidad.
**Variación de precio** = comparación entre la fila vigente y la anterior de `ServicePrice`,
expresada en porcentaje y en importe absoluto.

Todos estos cálculos viven en `Shared\Domain` / `Services\Domain` como funciones puras
testeables, no en plantillas ni controladores.

## 11. Dashboard

Consultas de lectura agregadas, sin entidades propias. Responden a las preguntas de
`PRODUCT.md` §3.1:

1. **¿Qué servicios estoy pagando?** → recuento por estado y por categoría.
2. **¿Cuánto me cuestan?** → equivalente mensual y anual del conjunto de servicios activos.
3. **¿Cuándo volverán a cobrarme?** → cobros de los próximos 30/60 días con total.
4. **¿Qué servicios se van a renovar?** → renovaciones próximas con preaviso.
5. **¿Qué ha cambiado?** → últimos `ServiceEvent` y alertas de cambio.
6. **¿Qué facturas nuevas ha detectado el sistema?** → descubrimientos pendientes.
7. **¿Qué gastos recurrentes han aumentado?** → variaciones de precio destacadas.
8. **¿Qué servicios debería revisar?** → alertas abiertas + descubrimientos pendientes.

Se implementan como *query handlers* con SQL/DBAL o DQL optimizado, no cargando agregados
completos en memoria.

## 12. Frontend

**Principio: renderizado en servidor, sin SPA.** La interfaz es HTML generado con Twig. El
JavaScript se usa solo donde aporta algo que el HTML no puede dar. Esto reduce código,
superficie de ataque y tiempo de construcción, y encaja con un producto cuyo valor está en los
datos, no en la interacción.

| Pieza | Elección | Motivo |
|---|---|---|
| Plantillas | **Twig** | Renderizado en servidor, escape automático (XSS), herencia de plantillas. |
| Navegación y formularios | **Turbo** (Hotwired) | Navegación y envío de formularios sin recarga completa, sin escribir una SPA. |
| Interacciones puntuales | **Stimulus** | Controladores pequeños y locales (filtros, diálogos, contadores). |
| Assets | **AssetMapper** | Sin Node ni `npm`: no se añade un toolchain al Dockerfile. Versionado por *digest*. |
| Tiempo real | **Mercure** | Ya disponible en la plantilla FrankenPHP. Empuja el progreso de sincronización. |
| CSS | **CSS propio con tokens de diseño** | Sin preprocesador ni build step. Ver D-28. |

**Progreso de operaciones asíncronas.** La sincronización de correo y la extracción son
asíncronas (`ARCHITECTURE.md` §6), así que la UI debe mostrar progreso sin bloquear:

- El servidor publica eventos de progreso en **Mercure** (`EmailSyncRun`: mensajes vistos,
  procesados, descubrimientos creados).
- Turbo actualiza el fragmento de estado al recibir el evento.
- **Fallback sin JavaScript:** la página se refresca por meta-refresh o el usuario recarga; el
  estado siempre está en la base de datos, no en el cliente.

**Mejora progresiva.** Todo flujo crítico (registro, conectar correo, confirmar un
descubrimiento, editar un servicio) funciona **sin JavaScript**. Turbo y Stimulus solo lo
hacen más agradable.

**Diseño responsive.** Es un requisito de primer nivel, no un ajuste posterior: un autónomo
consulta sus gastos desde el móvil. El CSS propio lo cubre sin dificultad porque el CSS
moderno ya trae las herramientas que antes justificaban un framework.

- **Mobile-first.** Los estilos base son para pantalla estrecha; los `@media` son
  `min-width`. Tres puntos de ruptura como máximo: **30rem** (480 px), **48rem** (768 px) y
  **64rem** (1024 px). Si un componente necesita más, se resuelve con *container queries*, no
  añadiendo breakpoints globales.
- **Tipografía y espaciado fluidos** con `clamp()` sobre los tokens. Reduce el número de
  breakpoints necesarios y evita saltos bruscos entre tamaños.
- **Container queries** (`@container`) para los componentes reutilizables (tarjeta de servicio,
  fila de cobro, badge). Un componente se adapta al ancho de **su contenedor**, no al de la
  ventana: así funciona igual en el dashboard a pantalla completa que dentro de una columna
  lateral. Es una ventaja real frente a un framework basado solo en breakpoints de viewport.
- **Layout con Grid y Flexbox**, sin *floats* ni posicionamiento absoluto para maquetar.

**El caso difícil: las tablas.** El dashboard, el historial de precios y la bandeja de
descubrimientos son tablas, y una tabla ancha es lo que rompe el responsive. No es un problema
de framework —Tailwind tampoco lo resuelve— sino de diseño. Patrón adoptado:

- Por encima de **48rem**, `<table>` normal.
- Por debajo, la misma tabla se convierte en **lista de tarjetas** con CSS puro: `display:
  block` en `table`/`tr`/`td`, cada celda precedida de su etiqueta mediante `data-label` y
  `::before`. **Un solo marcado en Twig**, sin duplicar plantillas ni ramas por dispositivo.
- Las columnas secundarias (notas, categoría, método de pago) se ocultan en pantalla estrecha y
  quedan accesibles en la ficha del servicio.

**Objetivos táctiles.** Mínimo **44 × 44 px** en cualquier elemento pulsable. Nada de acciones
que solo funcionen con *hover*: en táctil no existe.

**Criterio de aceptación.** Ninguna pantalla produce **scroll horizontal a 320 px de ancho**.
Se comprueba en cada fase del roadmap, no al final.

**Matriz de prueba.** 320 px (móvil pequeño), 390 px (móvil), 768 px (tableta), 1024 px y
1440 px (escritorio). En navegador con ventana redimensionable; no hace falta emulador.

**Estructura de plantillas.** `templates/` por módulo (`dashboard/`, `services/`, `mailbox/`,
`discovery/`, `alerts/`, `settings/`), con un `base.html.twig` y componentes Twig reutilizables
para las piezas repetidas (tarjeta de servicio, fila de cobro, badge de confianza).

**Idioma y accesibilidad.** Interfaz en **español** (mensajes de validación incluidos). HTML
semántico, foco visible, contraste suficiente y navegación por teclado: es una aplicación de
dinero, y se usa a menudo con prisa.

**Qué NO se hace en v1:**

- SPA (React/Vue/Angular) ni aplicación de escritorio.
- API pública REST/GraphQL. Los casos de uso ya están separados de los controladores, así que
  una API se puede añadir después sin tocar el dominio.
- App móvil nativa ni extensión de navegador.
- Gráficos decorativos en el dashboard (`PRODUCT.md` §8).
- **Tailwind, Bootstrap, Sass o PostCSS.** El CSS es propio, con tokens de diseño y anidamiento
  nativo, servido por AssetMapper sin paso de compilación (D-28).

## 13. Riesgos arquitectónicos abiertos

- **Precisión del matching proveedor/servicio.** Un falso positivo crea ruido; mitigación:
  umbral de confianza alto para propuestas automáticas y revisión obligatoria.
- **Detección de recurrencia con facturación irregular** (consumo variable, anual con importe
  distinto). Mitigación: marcar como `low confidence` y no fijar `nextChargeAt` automáticamente.
- **Coste y latencia de la IA.** Mitigación: prefiltro determinista, transporte `ai` con rate
  limit, caché por hash de documento.
- **Compatibilidad de librerías con Symfony 8 / PHP 8.5.** Verificar antes de adoptar cada
  dependencia.
- **Volumen de correo.** Mitigación: ventana acotada, cursores, lotes y backfill progresivo.
- **Multi-moneda.** v1 almacena la moneda pero no convierte; el dashboard agrupa por moneda.
