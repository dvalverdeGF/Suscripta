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
   (ver §4.10, §13 y `DECISIONS.md` D-24, D-29).
4. **Enriquecimiento progresivo.** Cada mensaje se procesa con el mecanismo **más barato,
   rápido y determinista** que pueda resolverlo, y solo se escala al siguiente nivel cuando
   el anterior no alcanza el umbral de confianza. El sistema **aprende del propio buzón**
   para que el uso de IA decrezca con el tiempo. La regla que gobierna todo el pipeline es:
   *la IA debe resolver incertidumbre, no sustituir a la lógica de negocio* (§13).
5. **La IA propone; el sistema decide.** Ninguna salida de un modelo se persiste sin pasar
   por el `Validator` de Symfony y por las reglas de negocio. Un importe negativo, una fecha
   incoherente o una periodicidad imposible se rechazan aunque el modelo los devuelva con
   confianza alta (§13.9, D-34).
6. **La IA es un recurso con presupuesto.** Cada llamada se registra y se factura contra un
   límite configurable por organización. Nunca hay llamadas ilimitadas durante una
   sincronización (§13.13, D-36).
7. **Nada crítico sin supervisión.** El sistema propone; el usuario confirma. Ninguna
   suscripción, cambio de precio o cancelación se registra automáticamente si hay
   incertidumbre.
8. **Aislamiento por tenant desde la primera línea.** Toda entidad de negocio lleva
   `organization_id` y toda consulta se filtra por él.
9. **Abstracción solo donde hay una variación real prevista** (proveedor de correo,
   proveedor de IA, almacenamiento de documentos, canal de notificación). No se crean
   interfaces "por si acaso".
10. **El correo es una fuente, no el producto.** La conexión IMAP es una **capacidad
    estratégica de primera clase**, presente en la arquitectura desde el principio, pero la
    aplicación **no es un cliente de correo**. El dominio no depende de Gmail ni de ningún
    proveedor concreto (ver §4.10 y `DECISIONS.md` D-23).
11. **Privacidad como principio arquitectónico, no como marketing.** Solo lectura,
    minimización de datos, separación de correo/documentos/datos estructurados, cifrado de
    credenciales, borrado por cuenta, retención controlada y registro de accesos sensibles
    (ver `SECURITY.md`). **No se hacen afirmaciones de "100 % privado" ni "100 % UE"** hasta
    que la implementación y los proveedores utilizados permitan sostenerlas (D-25).
12. **Todo paso es idempotente, reintentable y observable.** Un reintento no puede duplicar
    un `Discovery`, una `Invoice`, un `Document` ni un coste de IA (§13.14, D-37).

## 2. Stack técnico

| Pieza | Elección | Notas |
|---|---|---|
| Lenguaje | PHP 8.5 | La imagen base del repositorio es `dunglas/frankenphp:1-php8.5`. Ver `DECISIONS.md` (D-01). |
| Framework | Symfony 8.x | Instalado vía `composer create-project symfony/skeleton` en el primer arranque. |
| Servidor | FrankenPHP + Caddy | Ya configurado en el repositorio (worker mode, HTTPS automático, Mercure). |
| ORM | Doctrine ORM 3 + Migrations | Mapeo por **Attributes**. |
| Base de datos | PostgreSQL 16+ | `compose.yaml` fija `POSTGRES_VERSION=16` por defecto. |
| Mensajería | Symfony Messenger | Transporte Doctrine, varios transports + `failed`. |
| Texto de PDF | `smalot/pdfparser` (capa de texto) | Extracción determinista, sin salir del sistema. |
| OCR | Tesseract vía `thiagoalessio/tesseract_ocr` | **Opcional**, solo para PDF/imagen sin capa de texto. Se activa por configuración. |
| IA | `symfony/http-client` + `AiProviderInterface` | Proveedores intercambiables (económico y avanzado). Desactivada por defecto. Ver §13. |
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
├── Catalog/           # Provider, Category, ProviderIdentity, ProviderParser (conocimiento global)
├── Services/          # Service, ServicePrice, ServiceEvent  ← núcleo del producto
├── Documents/         # Document, Invoice
├── Mailbox/           # EmailAccount, EmailMessage, EmailSyncCursor, EmailSyncRun, IMAP, reenvío
├── Processing/        # Pipeline: BillingClassifier, extractores, validación, estados, MailboxKnowledge
├── Ai/                # AiProviderInterface, adaptadores, AiUsage, AiBudget, guard de coste
├── Discovery/         # Discovery, ServiceMatcher, detección de recurrencia y de cambios
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
| `ProviderIdentity` | id, providerId, type, value, confidence, source, hitCount, lastSeenAt, createdAt | **Cómo reconocemos a un proveedor.** `type`: `domain`, `sender`, `subject_pattern`, `attachment_pattern`. `source`: `seed`, `learned`, `user`. `UNIQUE (type, value)`. |
| `ProviderParser` | id, providerId, key, version, enabled, config (json), successCount, failureCount, lastUsedAt | **Cómo extraemos de un proveedor.** `key` referencia un parser de código (`ovh`, `github`, `microsoft`); `config` permite patrones declarativos sin tocar código. |

**Conocimiento de proveedores separado del catálogo.** `Provider` responde *quién es*; 
`ProviderIdentity` responde *cómo lo reconocemos* (un proveedor puede facturar desde varios
dominios y direcciones); `ProviderParser` responde *cómo extraemos sus datos*. Esta separación
es la que permite que el sistema **aprenda** un proveedor nuevo sin crear código, y que un
parser de código conviva con patrones configurados y con conocimiento aprendido (§13.7, D-33).

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
| `EmailMessage` | id, organizationId, emailAccountId, folder, uid, messageId, threadId, fromAddress, fromName, replyTo, senderDomain, toAddresses (json), subject, receivedAt, sizeBytes, contentType, hasAttachments, attachmentNames (json), attachmentTypes (json), bodyHash, contentHash, bodyExcerpt (nullable), billingScore, billingReasons (json), processingState, classification, classificationConfidence, extractionTier, extractorUsed, aiUsed, aiCostMinor, attempts, lastError, processedAt, createdAt |
| `MessageProcessingEvent` | id, emailMessageId, fromState, toState, reason, extractor, tier, aiUsageId (nullable), durationMs, data (json), occurredAt |

**`EmailAccount.provider`**: `imap`, `forwarding`, `gmail`, `microsoft` (los dos últimos, reservados).
**`EmailAccount.status`**: `pending`, `active`, `error`, `disabled`.
**`EmailMessage.processingState`**: máquina de estados explícita — ver §13.5.
**`EmailMessage.classification`**: `invoice`, `receipt`, `payment_confirmation`,
`renewal_notice`, `price_change`, `plan_change`, `expiration_notice`, `other`, `unknown`.
**`EmailMessage.extractionTier`**: `deterministic`, `known_parser`, `ai_cheap`, `ai_advanced`.

**`MessageProcessingEvent` es un log append-only** de las transiciones de estado de cada
mensaje. Responde a *qué ocurrió, cuándo, con qué extractor, si intervino IA, con qué
resultado y por qué se decidió* (§13.5, D-32). Es la base de la observabilidad del pipeline y
de la explicación que se muestra al usuario.

**Identidad estable del mensaje.** Un mensaje se identifica por
`UNIQUE (email_account_id, folder, uid)` y, además, por `UNIQUE (email_account_id, message_id)`
y `contentHash` (SHA-256 del contenido normalizado). El UID de IMAP **no es estable entre
buzones ni tras un cambio de `uidValidity`**, y el `Message-ID` puede faltar o repetirse en
copias; por eso se combinan los tres. Ver §13.2 y D-37.

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

**Conocimiento del buzón:**

| Entidad | Campos clave |
|---|---|
| `MailboxKnowledgeEntry` | id, emailAccountId, kind, key, value (json), confidence, source, hitCount, lastUsedAt, createdAt |

`kind`: `sender_mapping` (dirección → proveedor), `subject_pattern`, `ignored_sender`,
`document_pattern`, `provider_hint`. `source`: `user_confirmed`, `user_corrected`, `learned`.

Es el mecanismo por el que **el sistema aprende del propio buzón** y reduce el uso de IA con
el tiempo: lo que se resolvió una vez con IA se resuelve la siguiente vez con conocimiento
persistente (§13.12, D-33). Es **por cuenta de correo**, no por organización: dos buzones del
mismo tenant pueden tener patrones distintos.

**No es machine learning.** Es conocimiento estructurado y persistente, inspeccionable y
editable por el usuario. No se introduce ML en v1 (D-39).

### 4.6 Descubrimientos

| Entidad | Campos clave |
|---|---|
| `Discovery` | id, organizationId, type, status, confidence, confidenceScore, matchScore, matchReasons (json), proposedData (json), matchedServiceId (nullable), sourceEmailMessageId (nullable), extractionTier, aiUsed, detectedAt, reviewedAt, reviewedByUserId, resultingServiceId (nullable), notes |
| `DiscoveryEvidence` | id, discoveryId, emailMessageId (nullable), documentId (nullable), weight |

**`Discovery.matchScore` y `matchReasons`** guardan el resultado del `ServiceMatcher` (§13.10):
la puntuación de coincidencia con servicios existentes y el desglose de por qué. Permiten
explicar al usuario *"creemos que esto es el mismo servicio que ya tienes porque coincide el
proveedor y el importe"*, y son la materia prima del aprendizaje (§13.12).

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

### 4.8 Auditoría y coste de IA

| Entidad | Campos clave |
|---|---|
| `AuditLog` | id, organizationId (nullable), actorUserId (nullable), actorType, action, targetType, targetId, metadata (json), ipAddress, userAgent, createdAt |
| `AiUsage` | id, organizationId, emailAccountId (nullable), emailMessageId (nullable), documentId (nullable), operation, provider, model, tier, inputTokens, outputTokens, estimatedCostMinor, currency, latencyMs, success, errorCode, metadata (json), createdAt |
| `AiBudget` | id, organizationId, period, limitMinor, currency, hardStop, currentPeriodStart, currentSpendMinor, updatedAt |

Append-only. `AuditLog` se escribe para acciones sensibles (ver `SECURITY.md`).

**`AiUsage` es obligatorio, no opcional.** Cada llamada a un proveedor de IA —económico o
avanzado— deja una fila, incluso si falla. Responde a dos preguntas de negocio que no se
pueden contestar a posteriori si no se registran desde el primer día:

- *¿Cuánto nos cuesta procesar el buzón de este usuario?*
- *¿Cuánto cuesta de IA un usuario medio al mes?*

`AiBudget` define el techo por organización y periodo. Cuando se alcanza, **no se hacen más
llamadas de IA**: el pipeline continúa por reglas, el mensaje queda en `DEFERRED` y se
reanuda en el siguiente periodo o cuando el usuario amplía el presupuesto. Nunca hay llamadas
ilimitadas durante una sincronización (§13.13, D-36).

**`AiUsage.tier`**: `cheap`, `advanced`. **`AiUsage.operation`**: `classify`, `extract`,
`escalate`, `embed` (reservado).

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
  │  EmailAccount, EmailMessage, EmailSyncCursor, EmailSyncRun
  ▼
PROCESAMIENTO
Clasificación / extracción / validación / matching / aprendizaje
  │  BillingClassifier, DocumentExtractor, ProviderResolver, ServiceMatcher,
  │  RecurrenceDetector, MailboxKnowledgeEntry, MessageProcessingEvent
  │  (el coste de IA se registra en AiUsage / AiBudget)
  ▼
DOMINIO
Servicios / costes / renovaciones
  │  Service, ServicePrice, ServiceEvent, Invoice, Document, Discovery
  ▼
VALOR
Alertas / histórico / previsiones
     Alert, Notification, dashboard, cálculos de coste
```

**Regla de dependencia:** `Mailbox` (fuente) puede depender de `Shared`; `Processing` y
`Discovery` (procesamiento) pueden depender de `Mailbox`, `Documents`, `Catalog` y `Ai`;
`Services` (dominio) **no depende de `Mailbox`, `Processing`, `Ai` ni `Discovery`**. Un
`Service` creado a mano y uno creado por descubrimiento son indistinguibles salvo por
`Service.source`. Esto permite que el producto funcione sin correo, que el correo se pueda
sustituir o ampliar sin tocar el dominio, y que el proveedor de IA cambie sin reescribir nada
del negocio.

### 4.11 Extracción por capas

La extracción **no asume que todos los documentos deban enviarse a una API externa de IA**.
Se aplica en cascada, de lo más barato y determinista a lo más caro y difuso, y **se detiene
en cuanto hay suficiente confianza**. Esta sección resume el principio; el diseño completo
del pipeline (niveles, estados, interfaces, coste y aprendizaje) está en **§13**.

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
| `ocr` | OCR de documentos sin capa de texto | CPU-intensivo, concurrencia muy baja. |
| `ai` | Llamadas a proveedores de IA | Concurrencia limitada, rate limit propio y guard de presupuesto. |
| `notifications` | Entrega de avisos | Reintentos con backoff. |
| `failed` | Mensajes agotados | Inspección manual + alerta a administración. |

### 6.2 Pipeline

```text
SyncEmailAccountMessage          (IMAP)    ─┐
IngestForwardedEmailMessage     (reenvío) ─┤
                                           ↓
IngestEmailMessageMessage        Nivel 0 · identidad estable + deduplicación
        ↓
ExtractEmailMetadataMessage      Nivel 1 · cabeceras, adjuntos, dominio (sin descargar cuerpo)
        ↓
ScoreEmailMessageMessage         Nivel 2 · BillingClassifier → billingScore 0..100
        │
        ├── score < umbral ──────────────────────────────→ IGNORED (fin)
        ↓
ExtractDocumentDataMessage       Nivel 3 · extracción determinista
        ↓
ResolveProviderMessage           Nivel 4 · proveedor conocido + parser
        │
        ├── parser suficiente ────────────────────────────→ EXTRACTED
        ↓
ExtractWithAiMessage             Nivel 5 · IA económica
        │
        ├── confianza insuficiente ───────────────────────→ Nivel 6 · IA avanzada
        ↓
ValidateExtractionMessage        Nivel 7 · Validator + reglas de negocio
        ↓
MatchServiceMessage              Nivel 8 · ServiceMatcher ponderado
        │
        ├── HIGH   → asociar a Service existente ─────────→ MATCHED
        ├── MEDIUM → Discovery para revisión ─────────────→ DISCOVERY
        └── LOW    → Discovery de servicio nuevo ─────────→ DISCOVERY
        ↓
DetectRecurrenceMessage          periodicidad, próximo cobro, cambios de precio
        ↓
CreateDiscoveryMessage           Discovery + Alert + Notification
        ↓
SendNotificationMessage
```

Las dos vías de ingesta (acceso al buzón y reenvío, ver `DECISIONS.md` D-21) convergen en
`IngestEmailMessageMessage`: el resto del pipeline no distingue el origen.

Cada paso aplica las **capas de §4.11 en orden** y se detiene en cuanto alcanza el umbral de
confianza. El paso a IA es el último recurso y solo se ejecuta si la organización lo ha
autorizado y queda presupuesto. El diseño completo está en **§13**.

### 6.3 Garantías

- **Idempotencia.** Cada mensaje lleva una clave natural (`emailAccountId:folder:uid`,
  `documentId`, `discoveryId`). Un middleware `DeduplicationMiddleware` consulta una tabla
  `processed_message (message_class, dedup_key, processed_at)` y descarta duplicados. Además,
  los handlers son idempotentes por diseño (comprobar estado antes de escribir).
- **Nunca se paga dos veces por el mismo mensaje.** Antes de cualquier llamada de IA se
  comprueba si el mensaje ya tiene una extracción válida o si su `contentHash` ya fue
  procesado. Un reintento, una resincronización o un mensaje duplicado en dos carpetas **no
  consumen IA de nuevo** (§13.14, D-37).
- **Reintentos.** `retry_strategy` con backoff exponencial (3–5 intentos) y transporte
  `failed` al agotarse. Los errores permanentes (credenciales inválidas, formato no
  soportado) se marcan como no reintentables.
- **Tolerancia a fallos.** Un mensaje que falla no bloquea el resto del lote. Los errores se
  registran en `EmailMessage.lastError` / `EmailSyncRun.error`. Un fallo de IA **no pierde el
  mensaje**: queda en `DEFERRED` con el motivo y se reintenta más tarde.
- **Observabilidad.** `EmailSyncRun` por sincronización, `EmailMessage.processingState` por
  mensaje, `MessageProcessingEvent` como traza de transiciones, `AiUsage` como traza de coste,
  logs estructurados (Monolog) con `organizationId` y `emailAccountId`, y métricas de
  Messenger.
- **Backpressure.** Lotes acotados (`--limit`), `--time-limit` y `--memory-limit` en los
  workers, rate limiting en IMAP y en el transporte `ai`.
- **Sincronización acotada.** Nunca se recorre el buzón completo en cada sincronización: se
  parte del cursor y se limita la ventana inicial (por defecto, últimos 24 meses) y el número
  de mensajes por ejecución. El backfill histórico es progresivo y opcional.

### 6.4 Tareas programadas

No todo es reactivo. Hay trabajo que no lo dispara un usuario ni un mensaje, sino el paso del
tiempo, y ese trabajo se ejecuta como **comando de consola idempotente** para que pueda
programarse con cualquier planificador (cron, systemd timer, un `CronJob` de Kubernetes o el
`Scheduler` de Symfony) sin acoplar el dominio a ninguno de ellos.

| Comando | Frecuencia sugerida | Qué hace | Idempotente |
|---|---|---|---|
| `app:mail:sync` | Cada 15–60 min | Sincroniza los buzones activos y encola el procesado. | Sí (dedup por `folder:uid`) |
| `app:alerts:generate` | Diaria, de madrugada | Recalcula los avisos de cobros, renovaciones, plazos de preaviso y subidas de precio; resuelve los que ya no aplican. | Sí (clave de deduplicación por tipo + servicio + fecha) |
| `app:discoveries:expire` | Diaria | Caduca las propuestas que nadie ha revisado. | Sí |
| `app:data:purge` | Diaria | Aplica la retención de §SECURITY.md §6. | Sí |

**Por qué comandos y no un worker permanente.** Un planificador externo es más fácil de
observar (código de salida, logs, alertas de «no se ha ejecutado»), no consume memoria entre
ejecuciones y no obliga a mantener un proceso vivo más. El coste es que la frecuencia es
gruesa, y eso es aceptable: ningún aviso de este producto pierde valor por llegar unas horas
más tarde.

**Idempotencia como requisito, no como virtud.** Un planificador puede ejecutar el mismo
comando dos veces (reintento tras un fallo, solapamiento de ventanas, arranque duplicado). Por
eso `app:alerts:generate` no crea un aviso si ya existe uno con la misma clave
`tipo|servicio|fecha`, y **nunca reabre** un aviso que el usuario ya descartó o marcó como
visto: repetir la ejecución no puede resucitar algo que el usuario ya cerró.

**En desarrollo**, `app:alerts:generate` se ejecuta al arrancar el contenedor
(`frankenphp/docker-entrypoint.sh`) para que el entorno tenga datos con los que trabajar sin
esperar a un planificador. En producción esa línea se sustituye por la programación real.

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
- **Messenger:** el mensaje transporta el identificador de la entidad, no la organización. El
  caso de uso que lo consume (`ProcessEmailMessage`) resuelve la organización desde la entidad y
  envuelve el trabajo en `TenantContext::runAs()`. Un handler sin tenant válido falla
  explícitamente.
- **Evolución posible:** Row Level Security de PostgreSQL como capa adicional, sin cambiar el
  modelo de datos.

### 8.1 Publicación del contexto en el filtro

Cambiar la organización activa **no basta**: el filtro de Doctrine no se entera solo. Por eso
`TenantContext` publica cada cambio a través de `TenantFilterSynchronizerInterface`
(implementado por `DoctrineTenantFilterSynchronizer`), y lo hace en **todos** los caminos:

| Camino | Quién cambia el contexto | Cómo se publica |
|---|---|---|
| Petición HTTP | `ActiveOrganizationListener` | `setOrganizationId()` |
| Comando de consola | `TenantContext::runAs()` | `setOrganizationId()` al entrar y al salir |
| Worker de Messenger | el caso de uso (`ProcessEmailMessage`) | `runAs()` |

`runAs()` sincroniza **al entrar y al salir**, y restaura el contexto anterior incluso si el
bloque lanza. Sin esto, un comando que recorre organizaciones (`app:alerts:generate`,
`app:mail:sync`) leería los datos de todas y generaría avisos cruzados: el peor fallo posible en
un producto multi-tenant.

**Sin organización activa el filtro no restringe** (procesos de sistema: migraciones, purgas,
tareas de administración). Es una decisión deliberada, no un descuido: esos procesos deben ser
explícitos al operar sobre todas las organizaciones.

> **Aviso de implementación.** `SQLFilter::getParameter()` devuelve el valor **ya
> entrecomillado** para SQL, así que la ausencia de organización llega como `''` (dos comillas) y
> no como cadena vacía. Comparar contra `''` no detecta nada y la consulta acaba con
> `organization_id = ''`, que PostgreSQL rechaza por no ser un UUID válido. Ver
> `TenantFilter::NO_ORGANIZATION`.

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

## 13. Pipeline de análisis de correo

### 13.1 Principio rector y flujo completo

**La IA debe resolver incertidumbre, no sustituir a la lógica de negocio.**

El pipeline **no envía cada correo a una IA**. Construye un **enriquecimiento progresivo**:
cada mensaje se procesa con el mecanismo más barato, rápido y determinista capaz de
resolverlo, y solo se escala al siguiente nivel cuando el anterior no alcanza el umbral de
confianza. El sistema **aprende del propio buzón**, de modo que el uso de IA decrece con el
tiempo.

```text
IMAP
  ↓
Ingesta
  ↓
Deduplicación
  ↓
Metadatos / headers
  ↓
Filtros deterministas
  ↓
Billing Score
  ↓
¿Es probablemente relevante?
  ├── NO → Ignorar
  └── SÍ
        ↓
Extracción determinista
        ↓
Identificación de proveedor conocido
        ↓
¿Existe conocimiento/parser suficiente?
        ├── SÍ → Parser conocido
        └── NO
              ↓
          IA económica
              ↓
        ¿Confianza suficiente?
        ├── SÍ → continuar
        └── NO → IA avanzada
                         ↓
                 datos estructurados
                         ↓
                 Service Matching
                         ↓
                 decisión
```

**Objetivo de coste.** No se trata de "usar poca IA" por sí mismo, sino de que **el coste
marginal de procesar un buzón tienda a cero** a medida que el sistema conoce ese buzón. Un
buzón nuevo puede requerir IA; el mismo buzón al mes siguiente, casi ninguna (§13.12).

**Orden de los niveles.** Cada nivel es más caro que el anterior en CPU, red, almacenamiento
o dinero. El pipeline **nunca ejecuta un nivel si el anterior ya resolvió el mensaje**.

| Nivel | Nombre | Coste | Sale del sistema |
|---|---|---|---|
| 0 | Ingesta y deduplicación | Nulo | No |
| 1 | Metadatos (cabeceras) | Nulo | No |
| 2 | Filtros deterministas + billing score | Nulo | No |
| 3 | Extracción determinista | Bajo | No |
| 4 | Conocimiento de proveedores y parsers | Bajo | No |
| 5 | IA económica | Medio | **Sí** |
| 6 | IA avanzada | Alto | **Sí** |
| 7 | Validación | Nulo | No |
| 8 | Service matching | Nulo | No |

### 13.2 Nivel 0 — Ingesta, identidad estable y deduplicación

**El problema.** Un mensaje puede aparecer varias veces: en dos carpetas, en dos cuentas del
mismo tenant, tras un reenvío, o al repetir una sincronización. Reprocesarlo no es solo
desperdicio de CPU: **puede costar dinero** si vuelve a pasar por IA.

**Señales de identidad y sus límites:**

| Señal | Estable entre sincronizaciones | Única globalmente | Disponible sin descargar | Problema |
|---|---|---|---|---|
| `folder` + `uid` | Sí, mientras no cambie `uidValidity` | **No** (los UID se repiten entre buzones) | Sí | El servidor puede renumerar |
| `Message-ID` | Sí | Casi (puede faltar, repetirse o falsificarse) | Sí | Ausente en algunos envíos |
| `contentHash` (SHA-256) | Sí | **No** (dos correos idénticos legítimos) | No (requiere el contenido) | Coste de descarga |
| `emailAccountId` + `folder` + `uid` | Sí | **Sí**, dentro de la cuenta | Sí | — |

**Estrategia adoptada:**

1. **Clave de deduplicación primaria:** `emailAccountId + folder + uid`. Es la única
   combinación que es a la vez estable, única y gratuita. Se guarda junto con `uidValidity`
   del cursor: si `uidValidity` cambia, la clave se invalida y se reprocesa la ventana
   acotada.
2. **Clave secundaria:** `emailAccountId + messageId`. Detecta el mismo mensaje movido de
   carpeta (el UID cambia, el `Message-ID` no).
3. **`contentHash`:** SHA-256 del contenido normalizado (cabeceras relevantes + texto). Se usa
   para (a) deduplicar **documentos** por `checksumSha256`, (b) detectar el mismo mensaje
   llegado a **dos cuentas** del mismo tenant, y (c) servir de **clave de caché de
   extracción**: si ya existe una extracción válida para ese hash, no se vuelve a llamar a
   IA.
4. **Deduplicación entre cuentas:** a nivel de organización, no de cuenta. `Discovery` es
   tenant-scoped (D-27), así que el mismo servicio detectado por dos buzones produce **un**
   descubrimiento, no dos.

**Idempotencia.** El procesamiento es idempotente en todos los niveles: cada handler
comprueba el estado actual antes de escribir y registra su transición en
`MessageProcessingEvent`. Repetir un mensaje ya procesado es una operación de coste nulo.

**Regla explícita:** *si el mismo correo vuelve a aparecer durante una sincronización, no
debe volver a consumir IA.* Se garantiza comprobando, antes de cualquier llamada de IA, que
no exista ya un `ExtractedDocument` válido para ese `contentHash` (§13.14).

### 13.3 Nivel 1 — Metadatos

Antes de descargar o procesar contenido pesado se extraen **solo cabeceras**. IMAP permite
pedir `BODY.PEEK[HEADER]` sin marcar el mensaje como leído y sin traer el cuerpo.

| Campo | Uso en el pipeline |
|---|---|
| `From` | Identificación de proveedor, lista de ignorados |
| `Reply-To` | Detección de remitentes de facturación enmascarados |
| `To` | Detección de correo dirigido a la organización |
| `Subject` | Palabras clave, patrones, nombre de servicio |
| `Date` | Fecha de factura, orden temporal, periodicidad |
| `Message-ID` | Identidad secundaria, deduplicación |
| `IMAP UID` | Identidad primaria |
| `Content-Type` | Detección de `multipart`, HTML vs texto |
| Nombres de adjuntos | Patrón de factura, tipo de documento |
| Tipos de adjuntos | PDF, imagen (candidata a OCR), XML (factura electrónica) |
| Tamaño | Límite de descarga, coste estimado |
| Dominio del remitente | Reconocimiento de proveedor, agrupación |

**Regla:** el cuerpo y los adjuntos **no se descargan** hasta que el nivel 2 decide que el
mensaje es candidato. Esto reduce tráfico, almacenamiento, CPU y coste de IA (§13.14).

### 13.4 Nivel 2 — Filtros deterministas y billing score

El primer filtro es barato y **explicable**. No depende solo de palabras clave: combina
señales de remitente, dominio, adjuntos, patrones conocidos y listas configurables.

**Señales léxicas** (en asunto, nombre de adjunto y, si ya se dispone, extracto del cuerpo):

```text
invoice · factura · receipt · recibo · payment · pago · subscription · suscripción
renewal · renovación · billing · billing notice · statement · charge
```

**Señales estructurales:** remitente, dominio, existencia de PDF, nombre del adjunto,
patrones conocidos, listas de remitentes ignorados, listas de proveedores conocidos.

**Puntuación.** El resultado es un `billingScore` de 0 a 100, con los motivos desglosados:

| Señal | Peso por defecto |
|---|---|
| Asunto con palabra clave de facturación | +25 |
| Remitente o dominio en `ProviderIdentity` (proveedor conocido) | +20 |
| Adjunto PDF | +15 |
| Importe detectable en asunto o cuerpo | +15 |
| Lenguaje de renovación o próximo cobro | +10 |
| `local-part` de facturación (`invoice@`, `billing@`, `facturas@`) | +10 |
| Nombre de adjunto con patrón de factura | +10 |
| Remitente en lista de ignorados | −100 |
| Boletín, `no-reply` de marketing, notificación social | −30 |
| Respuesta o reenvío dentro de un hilo propio | −10 |

**Umbral por defecto: `billingScore ≥ 40` → `CANDIDATE`.** Por debajo, el mensaje pasa a
`IGNORED` y **no se descarga su contenido**.

**Los pesos son configurables y están documentados**, no son reglas rígidas enterradas en el
código. Viven en configuración versionada, se pueden ajustar por organización y se registran
en `EmailMessage.billingReasons` para poder explicar la decisión.

**Por qué no solo palabras clave.** Un boletín con la palabra "invoice" en el asunto no es una
factura; una factura de un proveedor conocido con asunto en otro idioma sí lo es. El score
pondera señales independientes en lugar de aplicar una lista de términos.

### 13.5 Máquina de estados y trazabilidad

Cada mensaje tiene un **estado explícito** que responde a *qué ocurrió, cuándo, con qué
extractor, si intervino IA, con qué resultado y por qué*.

| Estado | Significado | Transiciones |
|---|---|---|
| `RECEIVED` | Ingestado, identidad asignada, sin analizar | → `IGNORED`, `CANDIDATE`, `FAILED` |
| `IGNORED` | Descartado por billing score o lista de exclusión | terminal (revisable a mano) |
| `CANDIDATE` | Superó el filtro determinista; merece extracción | → `EXTRACTED`, `DEFERRED`, `FAILED` |
| `EXTRACTED` | Datos extraídos y validados | → `CLASSIFIED`, `REQUIRES_REVIEW`, `FAILED` |
| `CLASSIFIED` | Tipo de documento determinado con confianza suficiente | → `MATCHED`, `DISCOVERY`, `REQUIRES_REVIEW` |
| `MATCHED` | Asociado a un `Service` existente con confianza alta | terminal |
| `DISCOVERY` | Generó un `Discovery` pendiente de revisión | terminal (el `Discovery` sigue su ciclo) |
| `REQUIRES_REVIEW` | Confianza insuficiente o datos contradictorios | → `EXTRACTED`, `IGNORED` |
| `DEFERRED` | Sin presupuesto de IA o fallo transitorio | → `CANDIDATE`, `EXTRACTED`, `FAILED` |
| `FAILED` | Error permanente | terminal |

**Trazabilidad.** Cada transición escribe una fila en `MessageProcessingEvent` (append-only)
con: estado origen, estado destino, motivo, extractor utilizado, nivel de extracción, si
intervino IA (`aiUsageId`), duración y datos adicionales. Es la base de la observabilidad
(§13.16) y de la explicación que se muestra al usuario (§13.11).

**Por qué una máquina de estados explícita.** Sin ella, un pipeline con reintentos, escalado
a IA y procesamiento diferido es imposible de depurar: no se sabe si un mensaje se ignoró por
score, falló, o está esperando presupuesto. Con ella, cada mensaje tiene una respuesta
consultable.

### 13.6 Nivel 3 — Extracción determinista

Antes de llamar a IA se extrae por código **todo lo que sea fiablemente extraíble**:

- importes y monedas;
- fechas (factura, vencimiento, periodo);
- números de factura;
- periodos de facturación;
- direcciones de correo y dominios;
- URLs;
- identificadores y referencias de pedido.

**Herramientas:** expresiones regulares, parsers, análisis de HTML, extracción de texto de PDF
(`smalot/pdfparser`) y OCR **solo cuando el documento es una imagen o un PDF sin capa de
texto**.

**Regla:** no se usa IA para extraer algo que se puede obtener de forma fiable por código.

**Resultado: `ExtractedDocument`.** Es un **DTO**, no una entidad. Se persiste como JSON en
`Document` / `Discovery.proposedData`, coherente con D-14.

```php
final readonly class ExtractedDocument
{
    public function __construct(
        public ?int $amountMinor,
        public ?string $currency,
        public ?string $invoiceNumber,
        public ?\DateTimeImmutable $invoiceDate,
        public ?\DateTimeImmutable $dueDate,
        public ?BillingPeriod $billingPeriod,
        public ?string $sender,
        public ?string $senderDomain,
        public ?string $subject,
        public DocumentType $documentType,
        public ?string $providerName,
        public ?string $serviceName,
        public ?string $plan,
        public ?\DateTimeImmutable $renewalDate,
        public float $confidence,
        public ExtractionTier $tier,
        public array $rawSignals,
    ) {}
}
```

`rawSignals` guarda las coincidencias concretas (qué regex, qué fragmento) para poder
explicar y depurar la extracción sin volver a procesar el documento.

### 13.7 Nivel 4 — Conocimiento de proveedores y parsers

El sistema distingue entre **proveedor conocido** (`invoice@ovh.com` → `OVH`) y **proveedor
desconocido** (cualquier remitente que todavía no sabemos identificar).

**Modelo adoptado.** El conocimiento de proveedores se separa en tres piezas, porque
responden a tres preguntas distintas:

| Entidad | Pregunta que responde | Ámbito |
|---|---|---|
| `Provider` | *¿Quién es?* | Global o del tenant |
| `ProviderIdentity` | *¿Cómo lo reconocemos?* | Global |
| `ProviderParser` | *¿Cómo extraemos sus datos?* | Global |

`ProviderIdentity` permite que un proveedor tenga **varios** dominios y direcciones
(`ovh.com`, `ovh.es`, `invoice@ovh.com`, `billing@ovh.com`) sin duplicar el proveedor.
`ProviderParser` referencia un parser de código por `key` (`ovh`, `github`, `microsoft`) y
admite `config` declarativa para patrones que no requieren código.

**Escalera de resolución:**

```text
remitente / dominio
      ↓
ProviderIdentity (dominio, remitente, patrón de asunto)
      ↓
¿proveedor conocido?
  ├── NO → proveedor desconocido → IA (nivel 5)
  └── SÍ
        ↓
   ¿ProviderParser disponible y habilitado?
     ├── SÍ → parser específico → datos estructurados (sin IA)
     └── NO → patrones configurados → si no basta, IA (nivel 5)
```

**La ventaja clave:** una vez conocido un proveedor, **no se llama a IA para cada factura
futura**. El primer documento puede necesitar IA para comprender la estructura; a partir de
ahí el sistema usa conocimiento persistente.

**No se crean cientos de parsers a mano desde el primer día.** La arquitectura admite cuatro
mecanismos que conviven y se complementan:

1. **Parsers codificados** — para los proveedores de mayor volumen, escritos y testeados.
2. **Patrones configurables** — `ProviderParser.config` declarativo, sin desplegar código.
3. **Conocimiento aprendido** — `ProviderIdentity` y `MailboxKnowledgeEntry` generados a
   partir de confirmaciones y correcciones del usuario (§13.12).
4. **Fallback a IA** — cuando ninguno de los anteriores alcanza el umbral.

**Confianza.** Cada `ProviderIdentity` lleva `confidence`, `source` (`seed`, `learned`,
`user`) y `hitCount`. Una identidad confirmada por el usuario pesa más que una inferida, y una
identidad que acierta repetidamente se refuerza.

### 13.8 Niveles 5 y 6 — IA económica y escalado a IA avanzada

**Nivel 5 — IA económica.** Se usa cuando las reglas y el conocimiento existente no bastan.
Recibe **únicamente el contenido relevante**, nunca el correo completo:

- se elimina el HTML innecesario y se conserva el texto;
- se eliminan firmas, avisos legales y pies de página;
- se eliminan píxeles de seguimiento y enlaces de tracking;
- se elimina contenido irrelevante (hilos citados, navegación, publicidad);
- se limita la longitud;
- se extrae el texto útil del PDF;
- se **aprovechan los datos ya obtenidos determinísticamente** y se envían como contexto
  estructurado, para que el modelo no tenga que redescubrirlos.

**Contrato de salida.** La IA devuelve **exclusivamente una estructura validable**:

```json
{
  "provider": "...",
  "service": "...",
  "plan": "...",
  "documentType": "invoice",
  "amount": 29.90,
  "currency": "EUR",
  "billingPeriod": "monthly",
  "invoiceDate": "2026-10-03",
  "renewalDate": null,
  "confidence": 0.93
}
```

**Nivel 6 — IA avanzada.** Se escala a un modelo más capaz **solo** cuando:

- la confianza del nivel 5 es insuficiente;
- hay campos contradictorios entre sí;
- el proveedor es desconocido y el documento es complejo;
- el PDF es difícil (escaneado, multipágina, maquetación irregular);
- falta información para decidir.

El umbral es **configurable** y la IA avanzada **no es el camino por defecto**: es la
excepción. Cada escalado queda registrado en `AiUsage` con `tier = advanced`, de modo que se
puede medir cuánto cuesta y si aporta valor.

**Desacoplamiento del proveedor.** El dominio no conoce ningún proveedor de IA concreto. Todo
pasa por interfaces:

```php
interface DocumentExtractorInterface
{
    public function supports(ExtractionContext $context): bool;
    public function extract(ExtractionContext $context): ExtractedDocument;
    public function tier(): ExtractionTier;
}

interface AiProviderInterface
{
    public function name(): string;
    public function tier(): AiTier;          // cheap | advanced
    public function complete(AiRequest $request): AiResponse;
}
```

Cambiar de proveedor —o pasar a un modelo autoalojado o europeo— **no toca el dominio ni los
casos de uso**.

### 13.9 Nivel 7 — Validación posterior a la IA

**La IA no es una fuente de verdad.** Su salida es una *propuesta* que atraviesa una cadena de
validación antes de convertirse en dato:

```text
salida de IA
    ↓
DTO tipado (no array suelto)
    ↓
Symfony Validator (tipos, rangos, formatos, campos obligatorios)
    ↓
reglas de negocio (coherencia, periodicidad, moneda, dominio)
    ↓
resultado válido  →  continuar
resultado inválido →  REQUIRES_REVIEW o descarte
```

**Qué se valida:**

- **Tipos:** importe numérico, moneda ISO 4217, fechas reales.
- **Rangos:** importe positivo y por debajo de un máximo razonable; se rechaza
  `amount = -8738291`.
- **Coherencia interna:** `renewalDate` no puede ser anterior a `invoiceDate`; el periodo de
  facturación debe ser compatible con la diferencia entre fechas.
- **Periodicidad:** debe pertenecer al conjunto soportado (`monthly`, `quarterly`, `annual`…).
- **Campos obligatorios:** sin importe y sin moneda no hay factura.
- **Coherencia con el contexto:** si la extracción determinista ya encontró un importe y la IA
  devuelve otro muy distinto, se marca para revisión en lugar de elegir uno.

**Consecuencia:** un modelo que alucina no corrompe el dominio. Como mucho, genera un
`Discovery` que el usuario rechaza.

### 13.10 Nivel 8 — Service matching

**Regla fundamental: nunca se crea un servicio automáticamente.** Primero se intenta
**asociar** el documento a un `Service` existente. Solo si no hay coincidencia suficiente se
propone uno nuevo.

**Puntuación ponderada:**

| Señal | Peso |
|---|---|
| Proveedor coincide | +40 |
| Dominio del remitente coincide | +20 |
| Nombre del servicio coincide | +20 |
| Moneda coincide | +5 |
| Periodicidad coincide | +5 |
| Importe similar (±10 %) | +5 |
| Remitente exacto coincide | +5 |

**Umbrales (configurables):**

| Puntuación | Resultado | Acción |
|---|---|---|
| ≥ 70 | **HIGH** | Asociación automática al `Service` existente → `MATCHED` |
| 40–69 | **MEDIUM** | `Discovery` para revisión del usuario → `DISCOVERY` |
| < 40 | **LOW** | `Discovery` de servicio nuevo → `DISCOVERY` |

El desglose se guarda en `Discovery.matchScore` y `Discovery.matchReasons`, de modo que la
interfaz puede explicar *por qué* el sistema cree que dos cosas son el mismo servicio.

**Por qué ponderado y no reglas duras.** Un mismo servicio puede facturarse desde dominios
distintos, con importes que cambian o con nombres ligeramente diferentes. Una regla dura
("mismo dominio y mismo importe") falla en cuanto cambia cualquiera de los dos; una
puntuación tolera la variación y expresa la incertidumbre en lugar de ocultarla.

### 13.11 Discovery y confirmación

Cuando la confianza no es suficiente, se crea un `Discovery` en estado `pending`. La interfaz
muestra:

- proveedor y servicio propuestos;
- importe, moneda y periodicidad;
- **evidencia**: *"encontramos 4 documentos similares"*, con enlaces a los correos y
  documentos de origen;
- acciones: **[Confirmar]** · **[Editar]** · **[Ignorar]**.

**La confirmación se convierte en conocimiento.** Al confirmar, el sistema:

1. crea o actualiza el `Service` y su `ServicePrice`;
2. registra `ProviderIdentity` si el proveedor era nuevo;
3. registra `MailboxKnowledgeEntry` para ese remitente y ese patrón;
4. marca el `Discovery` como `confirmed` y el mensaje como `MATCHED`.

A partir de ese momento, **el mismo tipo de correo se resuelve sin IA**.

**Explicabilidad.** El usuario puede ver, para cualquier mensaje procesado, por qué se procesó
o por qué se ignoró: `billingScore` con sus motivos, extractor utilizado, si intervino IA y
qué `Discovery` generó. Es un requisito de producto, no un extra de depuración: el usuario
está conectando su buzón de facturación y tiene derecho a saber qué se ha hecho con él.

### 13.12 Aprendizaje del buzón

El sistema aprende **del propio buzón** y reduce el uso de IA con el tiempo. El conocimiento
vive en `MailboxKnowledgeEntry`, **por cuenta de correo**:

```text
invoice@ovh.com  →  OVH  →  factura  →  hosting
```

**No es machine learning.** Es conocimiento estructurado y persistente, inspeccionable y
editable por el usuario. No se introduce ML en v1 (D-39): no hay necesidad demostrada y
añadiría opacidad, coste y riesgo.

**De dónde aprende:**

- confirmaciones de `Discovery`;
- correcciones del usuario (proveedor, servicio, importe, periodicidad);
- proveedores y remitentes ya conocidos;
- patrones de asunto y de adjunto observados;
- parsers que han funcionado;
- asociaciones previas documento → servicio.

**Efecto esperado** (cifras ilustrativas del diseño, a validar con datos reales):

| | Primera sincronización | Sincronizaciones posteriores |
|---|---|---|
| Mensajes vistos | 10.000 | 200 nuevos |
| Candidatos (billing score) | 400 | 30 |
| Requieren IA económica | 100 | 5 |
| Requieren IA avanzada | 15 | 0 |

La curva es el argumento económico del diseño: **el coste de IA por buzón decrece**, y el
producto se vuelve más rentable cuanto más tiempo lleva un usuario.

### 13.13 Control de coste de IA

**Es un requisito crítico, no una optimización posterior.** Sin control de coste, un buzón
grande o un bucle de reintentos puede generar una factura ilimitada.

**Registro obligatorio.** Cada llamada deja una fila en `AiUsage` (§4.8), incluso si falla.
Esto permite responder a las dos preguntas que no se pueden contestar a posteriori:

- *¿Cuánto nos cuesta procesar el buzón de este usuario?*
- *¿Cuánto cuesta de IA un usuario medio al mes?*

**Presupuesto.** `AiBudget` define un techo por organización y periodo, con `hardStop`
configurable. Cuando se alcanza:

1. **no se hacen más llamadas de IA**;
2. el pipeline **continúa por reglas** con lo que pueda resolver;
3. los mensajes que necesitaban IA quedan en `DEFERRED` con el motivo;
4. se reanudan en el siguiente periodo, o cuando el usuario amplía el presupuesto.

**Nunca hay llamadas ilimitadas durante una sincronización.** El guard se comprueba **antes**
de cada llamada, no después, y el transporte `ai` tiene concurrencia limitada y rate limit
propio.

**Medición de valor.** Como cada extracción registra su `tier` y su coste, se puede medir
cuánto aporta realmente la IA frente a reglas y parsers, y ajustar los umbrales con datos.

### 13.14 Idempotencia, reintentos y procesamiento progresivo

**Idempotencia.** Todos los pasos son idempotentes y reintentables. Un reintento **no puede**:

- perder el mensaje;
- duplicar un `Discovery`;
- duplicar una `Invoice`;
- duplicar un coste de IA.

Esto es especialmente importante con Symfony Messenger, donde un mensaje puede reentregarse
tras un fallo del worker. La defensa es doble: el `DeduplicationMiddleware` (§6.3) descarta
duplicados por clave natural, y cada handler comprueba el estado antes de escribir.

**Antes de cualquier llamada de IA** se verifica que no exista ya un `ExtractedDocument`
válido para ese `contentHash`. Si existe, se reutiliza: **coste cero**.

**Fallo de IA.** Si la llamada falla, el mensaje **no se pierde**: queda en `DEFERRED` con el
error registrado y se reintenta con backoff. Un fallo de IA nunca debe degradar a `FAILED` si
el problema es transitorio.

**Procesamiento progresivo.** Cada nivel se ejecuta solo cuando el anterior lo justifica:

```text
cabeceras  →  metadatos  →  candidato  →  cuerpo  →  adjunto  →  OCR  →  IA
```

Esto reduce tráfico IMAP, almacenamiento, CPU, coste de IA y tiempo total. Un mensaje que se
ignora en el nivel 2 **nunca descarga su cuerpo ni sus adjuntos**.

### 13.15 Privacidad del pipeline

La privacidad es un principio arquitectónico (`SECURITY.md`), y el pipeline es donde más se
juega:

- **Minimización de lo que sale del sistema.** Solo se envía lo necesario: nunca el buzón
  completo, nunca correos irrelevantes, nunca el cuerpo íntegro si basta un fragmento.
- **Nada que se pueda extraer localmente se envía.** Si una regex o un parser resuelve el
  campo, no viaja a ningún proveedor.
- **Redacción previa.** Antes de enviar se eliminan firmas, avisos legales, tracking y
  contenido irrelevante (§13.8).
- **Trazabilidad de proveedor.** Se registra qué proveedor y qué modelo procesaron cada
  documento (`AiUsage.provider`, `AiUsage.model`), para poder auditar y para poder migrar.
- **Intercambiabilidad.** La arquitectura permite cambiar de proveedor de IA —o pasar a uno
  autoalojado o europeo— **sin tocar el dominio**.
- **Desactivada por defecto.** La capa de IA requiere consentimiento explícito de la
  organización y un contrato de encargado de tratamiento (D-08).

**No se hacen afirmaciones de "100 % privado" ni "100 % UE"** mientras la implementación y los
proveedores utilizados no permitan sostenerlas (D-25).

### 13.16 Observabilidad

| Pregunta | Dónde se responde |
|---|---|
| ¿Qué pasó con este mensaje? | `EmailMessage.processingState` + `MessageProcessingEvent` |
| ¿Por qué se ignoró? | `EmailMessage.billingScore` + `billingReasons` |
| ¿Qué extractor lo resolvió? | `EmailMessage.extractorUsed` + `extractionTier` |
| ¿Intervino IA? | `EmailMessage.aiUsed` + `aiUsageId` en el evento |
| ¿Cuánto costó? | `AiUsage.estimatedCostMinor` |
| ¿Cuánto llevamos gastado este mes? | `AiBudget.currentSpendMinor` |
| ¿Cómo va la sincronización? | `EmailSyncRun` + eventos Mercure |
| ¿Cuántos mensajes se resuelven sin IA? | Agregado sobre `extractionTier` |

**Métricas de producto derivadas** (ver `PRODUCT.md` §12): porcentaje de mensajes resueltos
sin IA, coste de IA por usuario y mes, tasa de confirmación de descubrimientos, tiempo medio
de procesamiento por mensaje.

### 13.17 Interfaces y responsabilidades

**Interfaces del pipeline** (capa de aplicación; las implementaciones viven en infraestructura):

```php
interface BillingClassifierInterface
{
    public function score(EmailMessage $message, EmailMetadata $metadata): BillingScore;
}

interface DocumentTextExtractorInterface
{
    public function supports(Document $document): bool;
    public function extractText(Document $document): ?string;
}

interface OcrEngineInterface
{
    public function isAvailable(): bool;
    public function extractText(Document $document): ?string;
}

interface ProviderResolverInterface
{
    public function resolve(EmailMetadata $metadata, ?ExtractedDocument $partial): ?ProviderMatch;
}

interface DocumentExtractorInterface
{
    public function supports(ExtractionContext $context): bool;
    public function extract(ExtractionContext $context): ExtractedDocument;
    public function tier(): ExtractionTier;
}

interface AiProviderInterface
{
    public function name(): string;
    public function tier(): AiTier;
    public function complete(AiRequest $request): AiResponse;
}

interface AiBudgetGuardInterface
{
    public function canSpend(Organization $organization, AiTier $tier): bool;
    public function record(AiUsage $usage): void;
}

interface ServiceMatcherInterface
{
    public function match(ExtractedDocument $document, Organization $organization): MatchResult;
}

interface MailboxKnowledgeRepositoryInterface
{
    public function find(EmailAccount $account, string $kind, string $key): ?MailboxKnowledgeEntry;
    public function remember(EmailAccount $account, string $kind, string $key, array $value, string $source): void;
}
```

**Responsabilidades por módulo:**

| Módulo | Responsabilidad en el pipeline | Lo que **no** hace |
|---|---|---|
| `Mailbox` | Ingesta (IMAP, reenvío), identidad del mensaje, cursores, `EmailSyncRun` | No interpreta el contenido |
| `Processing` | Clasificación, extracción, validación, máquina de estados, conocimiento del buzón | No conoce proveedores de IA concretos |
| `Ai` | Adaptadores de proveedor, `AiUsage`, `AiBudget`, guard de presupuesto | No conoce el dominio |
| `Catalog` | `Provider`, `ProviderIdentity`, `ProviderParser`: reconocimiento y parsers | No decide asociaciones |
| `Documents` | `Document`, `Invoice`: persistencia del resultado | No extrae |
| `Discovery` | `ServiceMatcher`, `Discovery`, recurrencia, cambios | No descarga correo |
| `Notifications` | Alertas y entrega | No procesa correo |

**Regla de dependencia:** `Processing` puede depender de `Mailbox`, `Documents`, `Catalog` y
`Ai`; **`Services` (dominio) no depende de ninguno de ellos** (§4.10). El dominio solo ve el
resultado de la extracción, nunca cómo se obtuvo.

## 14. Riesgos arquitectónicos abiertos

**Riesgos del dominio**

- **Precisión del matching proveedor/servicio.** Un falso positivo crea ruido; mitigación:
  umbral de confianza alto para propuestas automáticas y revisión obligatoria.
- **Detección de recurrencia con facturación irregular** (consumo variable, anual con importe
  distinto). Mitigación: marcar como `low confidence` y no fijar `nextChargeAt` automáticamente.
- **Volumen de correo.** Mitigación: ventana acotada, cursores, lotes y backfill progresivo.
- **Multi-moneda.** v1 almacena la moneda pero no convierte; el dashboard agrupa por moneda.

**Riesgos del pipeline de análisis (§13)**

- **Falsos negativos del billing score.** Un umbral alto descarta facturas reales; uno bajo
  dispara el coste. Mitigación: pesos configurables, `billingReasons` auditables, revisión
  periódica de los `IGNORED` y posibilidad de que el usuario marque un correo como "esto sí
  era una factura", lo que alimenta el aprendizaje.
- **Falsos positivos del billing score.** Boletines y notificaciones que pasan el filtro.
  Mitigación: pesos negativos explícitos, listas de ignorados y aprendizaje de remitentes.
- **Deriva de los parsers de proveedor.** Un proveedor cambia su plantilla y el parser falla
  en silencio. Mitigación: `ProviderParser.successCount` / `failureCount`, alerta al superar
  una tasa de fallo y degradación automática a IA en lugar de fallo duro.
- **Coste de IA descontrolado.** Mitigación: `AiBudget` con `hardStop`, guard **antes** de cada
  llamada, transporte `ai` con concurrencia limitada y caché por `contentHash`.
- **Alucinación del modelo.** Mitigación: validación obligatoria (§13.9) y confirmación del
  usuario para todo lo que no sea de confianza alta.
- **Crecimiento de `MailboxKnowledgeEntry`.** Un buzón con muchos remitentes genera muchas
  entradas. Mitigación: `hitCount` + `lastUsedAt` para poder podar el conocimiento que no se
  usa, y retención definida en `SECURITY.md`.
- **Confusión entre conocimiento global y del buzón.** Un patrón aprendido en un buzón no debe
  contaminar a otro. Mitigación: `MailboxKnowledgeEntry` es por cuenta; solo `ProviderIdentity`
  y `ProviderParser` son globales, y su promoción a global es una decisión explícita.
- **Dependencia de un proveedor de IA.** Mitigación: `AiProviderInterface`, IA desactivada por
  defecto y capacidad de operar solo con reglas y parsers.
- **Complejidad del pipeline.** Diez estados, nueve niveles e interfaces múltiples son mucho
  andamiaje. Mitigación: la vertical de `ROADMAP.md` Fase 3 implementa el pipeline **completo
  pero mínimo** (un proveedor, un parser, un umbral) antes de ampliarlo.

**Riesgos técnicos**

- **Compatibilidad de librerías con Symfony 8 / PHP 8.5.** Verificar antes de adoptar cada
  dependencia.
- **OCR en el contenedor.** Tesseract añade peso a la imagen y consumo de CPU. Mitigación:
  extensión opcional, transporte `ocr` con concurrencia muy baja y activación por
  configuración.
