# Arquitectura

> Estado: **propuesta inicial**. Este documento describe la arquitectura objetivo antes de
> escribir código. No hay todavía entidades, migraciones ni infraestructura implementadas.

## 1. Principios rectores

1. **Un producto, una cosa bien hecha.** Todo lo que no contribuya a _descubrir y controlar
   servicios recurrentes, costes, renovaciones y cambios_ queda fuera (ver `PRODUCT.md`).
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
   _la IA debe resolver incertidumbre, no sustituir a la lógica de negocio_ (§13).
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

| Pieza           | Elección                                      | Notas                                                                                       |
| --------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Lenguaje        | PHP 8.5                                       | La imagen base del repositorio es `dunglas/frankenphp:1-php8.5`. Ver `DECISIONS.md` (D-01). |
| Framework       | Symfony 8.x                                   | Instalado vía `composer create-project symfony/skeleton` en el primer arranque.             |
| Servidor        | FrankenPHP + Caddy                            | Ya configurado en el repositorio (worker mode, HTTPS automático, Mercure).                  |
| ORM             | Doctrine ORM 3 + Migrations                   | Mapeo por **Attributes**.                                                                   |
| Base de datos   | PostgreSQL 16+                                | `compose.yaml` fija `POSTGRES_VERSION=16` por defecto.                                      |
| Mensajería      | Symfony Messenger                             | Transporte Doctrine, varios transports + `failed`.                                          |
| Texto de PDF    | `smalot/pdfparser` (capa de texto)            | Extracción determinista, sin salir del sistema.                                             |
| OCR             | Tesseract vía `thiagoalessio/tesseract_ocr`   | **Opcional**, solo para PDF/imagen sin capa de texto. Se activa por configuración.          |
| IA              | `symfony/http-client` + `AiProviderInterface` | Proveedores intercambiables (económico y avanzado). Desactivada por defecto. Ver §13.       |
| Vistas          | Twig (SSR) + Turbo + Stimulus                 | Sin SPA. Ver §12 y `DECISIONS.md` (D-17).                                                   |
| Assets          | **AssetMapper** (sin Node/npm)                | `symfony/asset-mapper`. Evita un toolchain Node en la imagen Docker.                        |
| Tiempo real     | **Mercure**                                   | Ya incluido en la plantilla FrankenPHP. Progreso de sincronización en vivo.                 |
| Seguridad       | Symfony Security                              | Form login + sesión. OAuth de correo es aparte.                                             |
| Validación      | Symfony Validator                             | Mensajes en español.                                                                        |
| Identificadores | `symfony/uid` — **UUIDv7**                    | Ordenables temporalmente, buenos para índices.                                              |
| Cifrado         | `ext-sodium` (libsodium)                      | Cifrado de credenciales de correo.                                                          |
| Contenedores    | Docker / Docker Compose                       | Ya presente.                                                                                |

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
├── Services/          # Service, ServicePrice, ServiceEvent + read model  ← núcleo del producto
├── Subscriptions/     # Subscription: el contrato recurrente (plan, periodo, estado de cobro)
├── Documents/         # Document, Invoice
├── Mailbox/           # EmailAccount, EmailMessage, EmailSyncCursor, EmailSyncRun, IMAP, reenvío
├── Processing/        # Pipeline: BillingClassifier, extractores, validación, estados, MailboxKnowledge
├── Ai/                # AiProviderInterface, adaptadores, AiUsage, AiBudget, guard de coste
├── Integrations/      # Integration, catálogo de integraciones, credenciales cifradas
├── Discovery/         # Discovery, ServiceMatcher, detección de recurrencia y de cambios
├── Notifications/     # Alert, Notification, NotificationPreference, canales
├── Privacy/           # Exportación, borrado y purga por retención (Fase 16)
├── Api/               # API pública versionada bajo /api/v1 (Fase 18)
├── Webhooks/          # Webhooks de salida: suscriptores, entregas, firma y reintentos (Fase 20)
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

**Excepción de los puertos de privacidad:** el módulo `Privacy` no es un cliente de los demás
módulos sino su **coordinador**, y para eso los módulos publican interfaces en
`Shared/Application/Privacy/`. La dependencia va al revés de la habitual (el consumidor
declara el puerto, el proveedor lo implementa), y es lo que permite que borrar una
organización traverse siete módulos sin que el agregador conozca ninguno de ellos (§15).

### 3.1 Modelo de lectura (`ServiceView`)

Las **lecturas** no cargan la entidad de escritura `Service`. El módulo `Services` publica un
**read model** en `src/Services/Domain/Dto/`:

| DTO                | Qué aporta                                                                     |
| ------------------ | ------------------------------------------------------------------------------ |
| `ServiceView`      | Ficha del servicio con su precio actual, periodicidad, estado y próximo cobro. |
| `ServicePriceView` | Historial de precios aplanado, con su rango de fechas.                         |
| `ServiceEventView` | Eventos del servicio en forma plana, para la línea de tiempo.                  |

Se leen con `ServiceViewRepositoryInterface` (`findOne()`, `findAll()`,
`findForOrganization()`), que devuelve DTOs y no entidades. Motivo: atar las pantallas y los
cálculos a la forma interna de la entidad obliga a tocarlos cada vez que la entidad cambia, y una
entidad que además se serializa, se indexa o se exporta ya no es la misma entidad. El
emparejamiento del pipeline (`ServiceMatcher`) lee del read model por el mismo motivo: solo
necesita consultar. Ver D-50.

## 4. Modelo de dominio

### 4.1 Identidad y tenancy

| Entidad        | Campos clave                                                                                           | Notas                                                                   |
| -------------- | ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------- |
| `User`         | id, email (único), passwordHash, roles, locale, timezone, notificationSettings, createdAt, lastLoginAt |                                                                         |
| `Organization` | id, name, slug, ownerId, createdAt                                                                     | Tenant. Se crea automáticamente al registrar un usuario.                |
| `Membership`   | userId, organizationId, role (`owner`/`admin`/`member`), createdAt                                     | En v1 siempre un único `owner`. La tabla existe para no migrar después. |

**Decisión:** `Organization` existe desde v1 aunque en la primera versión sea 1:1 con el
usuario. Motivo y alternativas en `DECISIONS.md` (D-02).

### 4.2 Catálogo

| Entidad            | Campos clave                                                                                              | Notas                                                                                                                                                                  |
| ------------------ | --------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Provider`         | id, organizationId (nullable), name, slug, aliases (json), website, logoPath, defaultCategoryId, isSystem | `organizationId = null` → catálogo global (OVH, Microsoft, GitHub…). No nulo → proveedor propio del tenant.                                                            |
| `Category`         | id, organizationId (nullable), name, slug, color, icon, isSystem                                          | Categorías por defecto: Software, Hosting, Telecomunicaciones, Seguros, Suministros, Marketing, Formación, Otros.                                                      |
| `ProviderIdentity` | id, providerId, type, value, confidence, source, hitCount, lastSeenAt, createdAt                          | **Cómo reconocemos a un proveedor.** `type`: `domain`, `sender`, `subject_pattern`, `attachment_pattern`. `source`: `seed`, `learned`, `user`. `UNIQUE (type, value)`. |
| `ProviderParser`   | id, providerId, key, version, enabled, config (json), successCount, failureCount, lastUsedAt              | **Cómo extraemos de un proveedor.** `key` referencia un parser de código (`ovh`, `github`, `microsoft`); `config` permite patrones declarativos sin tocar código.      |

**Conocimiento de proveedores separado del catálogo.** `Provider` responde _quién es_;
`ProviderIdentity` responde _cómo lo reconocemos_ (un proveedor puede facturar desde varios
dominios y direcciones); `ProviderParser` responde _cómo extraemos sus datos_. Esta separación
es la que permite que el sistema **aprenda** un proveedor nuevo sin crear código, y que un
parser de código conviva con patrones configurados y con conocimiento aprendido (§13.7, D-33).

### 4.3 Núcleo: servicios y precios

| Entidad        | Campos clave                                                                                                                                                                                                                                                                                                                 |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Service`      | id, organizationId, providerId (nullable), name, planName (nullable), categoryId (nullable), status, currency, billingPeriod, billingIntervalCount, startedAt, nextChargeAt, renewalAt, noticePeriodDays, autoRenews, commitmentEndAt, cancelledAt, paymentMethodLabel, notes, source, createdByUserId, createdAt, updatedAt |
| `ServicePrice` | id, serviceId, amountMinor, currency, billingPeriod, billingIntervalCount, validFrom, validTo (nullable), source, invoiceId (nullable), note, createdAt                                                                                                                                                                      |
| `ServiceEvent` | id, serviceId, type, occurredAt, data (json), actorUserId (nullable), createdAt                                                                                                                                                                                                                                              |

**`Service.status`**: `active`, `paused`, `cancelled`, `pending_review`.
**`Service.source`**: `manual`, `email_discovery`.
**`ServiceEvent.type`**: `created`, `price_changed`, `plan_changed`, `renewed`, `paused`,
`resumed`, `cancelled`, `document_added`, `discovered`, `note_added`.

**El precio es histórico, nunca un campo mutable.** El precio vigente es la fila de
`ServicePrice` con `validTo IS NULL`. Un cambio de precio cierra la fila anterior
(`validTo = fecha del cambio`) e inserta una nueva. Esto permite responder
_"este servicio ha subido un 19,8 % en el último año"_ sin cálculos frágiles.

**`ServiceEvent` es un log append-only** del ciclo de vida del servicio. Alimenta la pregunta
_"¿qué ha cambiado?"_ del dashboard y sirve de base para las alertas de cambio.

**`Renewal` no es una entidad en v1.** La renovación es un conjunto de propiedades del
`Service` (`renewalAt`, `noticePeriodDays`, `autoRenews`, `commitmentEndAt`) más un evento
generado. Ver `DECISIONS.md` (D-03).

### 4.3.1 Suscripción: el contrato (Fase 21)

`Service` responde a _«¿qué estoy pagando?»_. `Subscription` responde a _«¿qué contrato
sostiene ese cobro?»_. Son dos preguntas distintas y por eso son dos entidades (D-78).

| Entidad        | Campos clave                                                                                                                                                                                                                                                                                                     |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Subscription` | id, organizationId, planId (nullable), serviceId (nullable), name, status, currency, billingPeriod, billingIntervalCount, startedAt, currentPeriodStart, currentPeriodEnd, trialEndsAt (nullable), canceledAt (nullable), endsAt (nullable), externalReference (nullable), createdByUserId, createdAt, updatedAt |

**`Subscription.status`**: `trialing`, `active`, `past_due`, `paused`, `canceled`. No se
reutiliza `ServiceStatus`: `past_due` es un estado de **cobro** que un servicio descubierto en
el correo nunca tendrá, y `pending_review` es un estado de **revisión** que una suscripción
nunca tendrá.

| Transición          | Método          | Regla                                                                   |
| ------------------- | --------------- | ----------------------------------------------------------------------- |
| `* → trialing`      | `startTrial()`  | Solo con fin posterior a hoy y si no está cancelada.                    |
| `trialing → active` | `activate()`    | El periodo **empieza hoy**: la prueba no se cobra.                      |
| `* → active`        | `activate()`    | Limpia `trialEndsAt` y `endsAt`. Una cancelada no vuelve.               |
| `* → past_due`      | `markPastDue()` | Sigue viva: se da servicio mientras se reintenta el cobro.              |
| `* → paused`        | `pause()`       | Idempotente. El periodo **no** avanza.                                  |
| `paused → active`   | `resume()`      | El periodo **se reinicia hoy**: los días de pausa no se cobraron.       |
| `* → canceled`      | `cancel()`      | Idempotente. `endsAt` = fin del periodo pagado; no corta el acceso hoy. |
| `active → active`   | `renew()`       | Solo lo activo se renueva. Si el periodo venció, el nuevo empieza hoy.  |

**El periodo se calcula con `BillingPeriod::advance()`**, no sumando días: una suscripción
mensual del 31 de enero renueva el 28 de febrero. `changeBillingPeriod()` recalcula el final
del periodo en curso **desde su inicio**, así que pasar de mensual a anual alarga el periodo
actual en lugar de dejarlo a medias.

**El nombre está congelado en la suscripción**, no se lee del plan: si el catálogo renombra el
producto, quien ya lo contrató sigue viendo el nombre con el que lo contrató (D-79).

**No hay columna de importe.** El precio vive en el catálogo (Fase 22) y en las facturas del
cobro (Fase 24). Congelarlo aquí obligaría a decidir hoy qué pasa con las subidas de precio, y
esa decisión se toma con la pasarela delante.

**`planId` y `serviceId` son UUID sueltos, sin clave ajena.** `Plan` vive en `Subscriptions`
(§4.3.2) y `Service` en `Services`; los módulos no se referencian por FK, igual que
`Service::$providerId` y `$categoryId`. La relación con el servicio es **opcional y explícita**:
un servicio detectado en una factura no es una suscripción del catálogo, y confundirlos es el
error que esta fase evita.

### 4.3.2 Catálogo comercial: lo que se vende (Fase 22)

El catálogo de §4.2 responde _qué proveedores conocemos_; este responde _qué vendemos nosotros_.
Son dos cosas distintas y por eso son dos módulos: `Catalog` describe el mundo (OVH, Microsoft,
GitHub…, con sus dominios y sus parsers) y no se factura; `Subscriptions` describe el producto
que Suscripta pone a la venta. Meter ambos en el mismo módulo habría dejado un `Plan` que no es
un proveedor codeándose con `Provider`, y un panel de operador que tendría que filtrar la mitad
de las tablas.

| Entidad       | Campos clave                                                                                                                                  | Notas                                                                                                                     |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `Plan`        | id, code (enum `OrganizationPlan`, único), name, slug (único), tagline, description, isActive, isHighlighted, sortOrder, createdAt, updatedAt | `code` es el **puente** con `entitlements.yaml`: no hay dos catálogos, hay uno con límites en configuración (D-64, D-81). |
| `PlanPrice`   | id, planId, amountMinor, currency, billingPeriod, validFrom, validTo (nullable), externalPriceId (nullable), createdAt                        | Vigente = `validTo IS NULL`. Nunca se sobrescribe: se cierra y se abre otro (D-13, D-82).                                 |
| `PlanFeature` | id, planId, label, included, sortOrder                                                                                                        | Texto de presentación de la tarjeta de precios. `included = false` sirve para enseñar tachado lo que **no** entra.        |

**El catálogo es global, no por tenant.** Un `Plan` no implementa `TenantAwareInterface`: los
precios de Suscripta son los mismos para todos. Lo que sí es por tenant es la _suscripción_ a
un plan, que es la Fase 21.

**El panel exige `ROLE_OPERATOR`**, un rol que **no se hereda de ningún plan** y se concede a
mano con `app:user:grant-operator <email>` (y se retira con `--revoke`). Es la primera pantalla
del backoffice que no está al alcance de un `ROLE_USER`: cambiar el precio que ven todos los
clientes no es una preferencia de la cuenta. La constante vive en
`App\Identity\Domain\Security\Roles::OPERATOR`, no en un literal suelto, y el enlace del menú
va envuelto en `is_granted()` porque un enlace que lleva a un 403 es peor que no tenerlo.

**Los precios tienen historial; los planes no se borran.** Un precio se cierra poniendo
`validTo` y se publica otro (`PublishPlanPrice`), que además **no crea fila si el importe no
cambia** — reenviar el mismo formulario no debe fabricar un historial de un solo día. Un plan se
retira con `isActive = false`: borrarlo dejaría suscripciones apuntando a un UUID que ya no
existe. Las **características sí se borran**, porque son texto de marketing y no sostienen nada.

**Los límites no se editan aquí y no se copian en la base de datos.** La ficha del plan los
muestra en solo lectura leyendo `EntitlementSettings`, que es la única fuente (D-64). Copiarlos
en `Plan` crearía una segunda verdad que se desincroniza el día que alguien edite el YAML.

**Dos comandos de operación.** `app:catalog:seed` siembra los planes por defecto de forma
idempotente (no toca fichas existentes, `--dry-run` para ver qué haría) y
`app:catalog:link-subscriptions` rellena el `planId` de las suscripciones antiguas emparejando
por nombre normalizado, **solo cuando hay exactamente un candidato**: ante la duda no enlaza, y
lo dice.

### 4.4 Documentos y facturas

| Entidad    | Campos clave                                                                                                                                                                                                          |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Document` | id, organizationId, serviceId (nullable), invoiceId (nullable), originalFilename, storageDriver, storageKey, mimeType, sizeBytes, checksumSha256, type, source, emailMessageId (nullable), createdAt, deletedAt       |
| `Invoice`  | id, organizationId, serviceId (nullable), providerId (nullable), documentId (nullable), number (nullable), issuedAt, totalAmountMinor, currency, periodStart, periodEnd, paidAt (nullable), status, source, createdAt |

**`Document.type`**: `invoice`, `receipt`, `contract`, `other`.
**`Document.source`**: `email_attachment`, `email_body`, `manual_upload`.
**`Invoice.status`**: `pending`, `paid`, `failed`, `refunded`, `unknown`.
**`Invoice.source`**: `manual`, `email_discovery`.

`Invoice.source` no es redundante con `Document.source`: una factura puede existir **sin
documento** (D-19 — hay cobros que solo conocemos por el cuerpo del correo), y en ese caso no
hay ningún `Document` que arrastre la procedencia. Es también la métrica que mide la propuesta
de valor (`PRODUCT.md` §12): qué parte del historial la descubrió el producto y no el usuario.

**`Payment` no es una entidad en v1.** Un cobro observado se representa como `Invoice` con
`paidAt` y `status = paid`. Se introducirá `Payment` solo si aparece una necesidad real
(pagos parciales, varios métodos por factura). Ver `DECISIONS.md` (D-04).

Los adjuntos de correo que decidamos conservar **se convierten en `Document`**; no existe una
entidad `EmailAttachment` separada.

### 4.5 Correo

| Entidad                  | Campos clave                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `EmailAccount`           | id, organizationId, provider, emailAddress, displayName, status, credentialsEncrypted, imapHost, imapPort, imapEncryption, imapUsername, forwardingAddress (nullable), forwardingSenders (json), forwardingEnabled, lastSyncAt, lastSyncStatus, lastSyncError, createdAt, updatedAt, deletedAt                                                                                                                                                                                                                         |
| `EmailSyncCursor`        | id, organizationId, emailAccountId, folder, uidValidity, lastSeenUid, backfillCursor (nullable), lastSyncAt, createdAt, updatedAt                                                                                                                                                                                                                                                                                                                                                                                      |
| `EmailSyncRun`           | id, emailAccountId, startedAt, finishedAt, status, messagesSeen, messagesProcessed, messagesSkipped, messagesBackfilled, discoveriesCreated, error                                                                                                                                                                                                                                                                                                                                                                     |
| `EmailMessage`           | id, organizationId, emailAccountId, folder, uid (nullable), source, messageId, threadId, fromAddress, fromName, replyTo, senderDomain, toAddresses (json), subject, receivedAt, sizeBytes, contentType, hasAttachments, attachmentNames (json), attachmentTypes (json), bodyHash, contentHash, bodyExcerpt (nullable), billingScore, billingReasons (json), processingState, classification, classificationConfidence, extractionTier, extractorUsed, aiUsed, aiCostMinor, attempts, lastError, processedAt, createdAt |
| `MessageProcessingEvent` | id, emailMessageId, fromState, toState, reason, extractor, tier, aiUsageId (nullable), durationMs, data (json), occurredAt                                                                                                                                                                                                                                                                                                                                                                                             |
| `ExtractionCache`        | id, organizationId, contentHash, document (json), tier, confidence, hitCount, lastUsedAt, createdAt                                                                                                                                                                                                                                                                                                                                                                                                                    |

**`EmailAccount.provider`**: `imap`, `forwarding`, `gmail`, `microsoft`, `yahoo`, `icloud`. Ver §4.5.1.
**`EmailAccount.status`**: `pending`, `active`, `error`, `disabled`.
**`EmailMessage.source`**: `imap` (leído del buzón) o `forwarding` (recibido por reenvío). Ver §4.5.2.
**`EmailMessage.processingState`**: máquina de estados explícita — ver §13.5.
**`EmailMessage.classification`**: `invoice`, `receipt`, `payment_confirmation`,
`renewal_notice`, `price_change`, `plan_change`, `expiration_notice`, `other`, `unknown`.
**`EmailMessage.extractionTier`**: `deterministic`, `known_parser`, `ai_cheap`, `ai_advanced`.

**`MessageProcessingEvent` es un log append-only** de las transiciones de estado de cada
mensaje. Responde a _qué ocurrió, cuándo, con qué extractor, si intervino IA, con qué
resultado y por qué se decidió_ (§13.5, D-32). Es la base de la observabilidad del pipeline y
de la explicación que se muestra al usuario.

**`ExtractionCache` es la memoria de extracción.** Guarda el `ExtractedDocument` ya resuelto
para un `contentHash`, con clave única `(organization_id, content_hash)`, para que un mensaje
repetido —o el mismo correo llegado a dos cuentas del mismo tenant— no vuelva a pagar
extracción ni IA. Guarda **solo el resultado estructurado**, nunca el cuerpo (D-10), y está
acotada por organización a propósito: compartirla entre tenants sería un canal lateral
(§13.2).

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
ventana acotada. Esto responde al requisito _"conocer hasta dónde se ha sincronizado cada
cuenta"_. El detalle del algoritmo está en §13.2.

**`EmailMessage.uid` es nullable.** Un mensaje leído del buzón siempre tiene UID; uno recibido
por reenvío no, porque nunca estuvo en una carpeta IMAP. La restricción
`UNIQUE (email_account_id, folder, uid)` sigue siendo válida porque PostgreSQL trata cada
`NULL` como distinto, así que varios reenvíos conviven en la misma cuenta y carpeta. La
deduplicación de los reenvíos se apoya entonces en `messageId` y en `contentHash`.

### 4.5.1 Proveedores, autenticación y autodiscovery

**El proveedor no es una etiqueta: es un catálogo.** `EmailAccountProvider` declara, para cada
caso, el servidor, el puerto, el cifrado, la página oficial donde se genera la contraseña de
aplicación, si admite OAuth2, si lo **exige** y si acepta contraseña. La aplicación impone el
preset: lo que llegue del formulario para un proveedor conocido se ignora (D-93).

| Proveedor    | Servidor                        | Autenticación aceptada            | Notas                                                        |
| ------------ | ------------------------------- | --------------------------------- | ------------------------------------------------------------ |
| `imap`       | el que escriba el usuario       | contraseña                        | Caso general; el servidor se detecta o se teclea.            |
| `gmail`      | `imap.gmail.com:993` SSL        | contraseña de aplicación u OAuth2 | La contraseña de aplicación exige verificación en dos pasos. |
| `microsoft`  | `outlook.office365.com:993` SSL | **solo OAuth2**                   | Cuentas personales (`consumers`). `LOGIN` ya no funciona.    |
| `yahoo`      | `imap.mail.yahoo.com:993` SSL   | contraseña de aplicación          | No hay OAuth2 para IMAP.                                     |
| `icloud`     | `imap.mail.me.com:993` SSL      | contraseña de aplicación          | Apple exige contraseña específica de aplicación.             |
| `forwarding` | —                               | —                                 | No abre conexión: recibe correo reenviado (§4.5.2).          |

**Una sola forma de guardar credenciales.** `credentialsEncrypted` guarda un sobre
(`EmailAccountCredentials`) con **o** una contraseña **o** un `accessToken` + `refreshToken` +
`expiresAt`. No hay columna nueva ni migración; un valor que no sea JSON se lee como contraseña
en claro, que es lo que había antes.

**Un solo punto traduce «buzón» a «parámetros de conexión».** `ResolveImapConnection` descifra,
renueva el token si caduca en menos de 120 s, **persiste** la credencial nueva y construye el
`ImapConnectionConfig`. Lo usan conectar, actualizar, probar, sincronizar y procesar: antes el
bloque estaba copiado en cuatro sitios y con dos formas de autenticación cada copia olvidada
sería un buzón que funciona al conectarlo y falla al sincronizar.

**OAuth2 es un puerto con adaptadores por etiqueta** (`app.mail_oauth_provider`), como los
canales de notificación o los parsers de proveedor. Flujo `authorization code` + PKCE; la
dirección del buzón se lee del `id_token` que devuelve el endpoint de tokens por TLS (no se
valida su firma: no viene del navegador). Sin credenciales de aplicación registradas, el botón
no se pinta y el proveedor sigue funcionando con contraseña de aplicación donde se pueda.

**Autodiscovery sin descargar XML.** `DiscoverImapSettings` prueba el registro SRV
`_imaps._tcp`, luego `_imap._tcp` y, si no hay suerte, sondea por convención `imap.<dominio>`,
`mail.<dominio>` y `<dominio>` en 993/SSL y después en 143/STARTTLS. **No** se descarga el XML de
autoconfiguración de Thunderbird: es una URL que elige quien escribe el dominio, la petición
sale de nuestros servidores y el resultado acaba en una conexión IMAP, o sea, una puerta a SSRF
que no hace falta. El resultado **rellena** el campo y el usuario lo confirma con «Probar
conexión»; no se conecta solo, porque el sondeo por convención puede acertar con el servidor
equivocado en un hosting que aloja varios dominios. La ruta tiene su propio limitador
(`mail_discovery`, 15/min por organización): es la única que abre conexiones hacia fuera a
petición del usuario.

### 4.5.2 Ingesta por reenvío

La vía de reenvío (D-21) existe para quien no quiere —o no puede— dar acceso a su buzón. El
usuario activa el reenvío en su proveedor hacia una dirección que **generamos nosotros** y que
solo acepta correo de los remitentes que él autorice.

| Concepto         | Decisión                                                                                                                                                                                                                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Dirección        | `inbox-<32 hex>@<dominio>`, generada con `random_bytes(16)`. Es un **secreto**: no se deriva de la cuenta ni de la organización, y por eso se puede rotar.                                                                                                   |
| Autorización     | `forwardingSenders` es una lista de direcciones o de dominios (`@miempresa.com`). Sin lista, la dirección no acepta a nadie: sería un buzón abierto.                                                                                                         |
| Comparación      | Por dirección completa o por dominio, con `str_ends_with` sobre el dominio normalizado. `@ovh.com` **no** autoriza `atacante@falso-ovh.com`.                                                                                                                 |
| Rotación         | Cambiar la dirección invalida la anterior de inmediato.                                                                                                                                                                                                      |
| Desactivación    | `forwardingEnabled = false` basta: `findByForwardingAddress()` exige que esté activo. La dirección se conserva por si se vuelve a activar.                                                                                                                   |
| Límite de tamaño | 25 MB por mensaje.                                                                                                                                                                                                                                           |
| Límite de caudal | _Rate limiter_ `inbound_email` (ventana deslizante, 120/hora por cuenta).                                                                                                                                                                                    |
| Respuesta        | `200` para aceptado **y** duplicado (para que el proveedor no reintente), `202` para cualquier rechazo, `400` para un cuerpo inválido. El motivo real **no** se revela: sería un oráculo para descubrir direcciones válidas. Queda en el libro de auditoría. |
| Procesamiento    | El mensaje se **encola** en `mail_processing`; nunca se procesa dentro de la petición HTTP del proveedor.                                                                                                                                                    |

El cuerpo del mensaje viaja con el mensaje de la cola (`ProcessEmailMessageMessage::$body`)
porque un reenvío no se puede volver a leer del buzón. Es un dato **transitorio**: no se
persiste (D-10); lo único que sobrevive es el extracto corto que el pipeline decide guardar.

**Desconectar una cuenta** borra credenciales, mensajes indexados y cursores, y desactiva el
reenvío. Los servicios ya confirmados permanecen: son datos del usuario, no del correo
(`SECURITY.md` §6).

**Conocimiento del buzón:**

| Entidad                 | Campos clave                                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------ |
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

| Entidad             | Campos clave                                                                                                                                                                                                                                                                              |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Discovery`         | id, organizationId, type, status, confidence, confidenceScore, matchScore, matchReasons (json), proposedData (json), matchedServiceId (nullable), sourceEmailMessageId (nullable), extractionTier, aiUsed, detectedAt, reviewedAt, reviewedByUserId, resultingServiceId (nullable), notes |
| `DiscoveryEvidence` | id, discoveryId, emailMessageId (nullable), documentId (nullable), weight                                                                                                                                                                                                                 |

**`Discovery.matchScore` y `matchReasons`** guardan el resultado del `ServiceMatcher` (§13.10):
la puntuación de coincidencia con servicios existentes y el desglose de por qué. Permiten
explicar al usuario _"creemos que esto es el mismo servicio que ya tienes porque coincide el
proveedor y el importe"_, y son la materia prima del aprendizaje (§13.12).

**`Discovery.type`**: `new_service`, `price_change`, `plan_change`, `cancellation`, `duplicate`.
**`Discovery.status`**: `pending`, `confirmed`, `edited`, `ignored`, `expired`.
**`Discovery.confidence`**: `high`, `medium`, `low` (derivado de `confidenceScore`).

`proposedData` es un JSON con la propuesta normalizada (proveedor, servicio, plan, importe,
moneda, periodicidad, próximo cobro, categoría sugerida). Se usa JSON deliberadamente: es una
propuesta en revisión, no una entidad de negocio, y su forma evolucionará con el extractor.

`DiscoveryEvidence` permite mostrar al usuario _por qué_ el sistema propone algo (correo y
documentos de origen).

### 4.7 Alertas y notificaciones

| Entidad                  | Campos clave                                                                                                                       |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `Alert`                  | id, organizationId, serviceId (nullable), type, severity, title, message, data (json), isInference, status, detectedAt, resolvedAt |
| `Notification`           | id, organizationId, userId, alertId (nullable), channel, status, payload (json), sentAt, readAt, createdAt                         |
| `NotificationPreference` | id, userId, alertType, channel, enabled                                                                                            |

**`Alert.type`**: `upcoming_charge`, `upcoming_renewal`, `price_increase`, `plan_change`,
`new_service_detected`, `possible_duplicate`, `missing_recurring_invoice`, `cost_changed`,
`annual_renewal_soon`, `inactive_service`.
**`Alert.status`**: `open`, `acknowledged`, `dismissed`, `resolved`.
**`Notification.channel`**: `in_app`, `email` (ampliable a `push`, `slack`, `telegram`).

**Separación detección / entrega:** `Alert` es _qué ha pasado_; `Notification` es _cómo y a
quién se ha avisado_. Añadir un canal nuevo no toca el dominio: solo se registra un nuevo
`NotificationChannelInterface`.

**`Alert.isInference`** marca las alertas que son sugerencias (duplicados, servicio inactivo,
cambio de precio inferido) para que la UI las presente explícitamente como tales.

### 4.8 Auditoría y coste de IA

| Entidad    | Campos clave                                                                                                                                                                                                                                          |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AuditLog` | id, organizationId (nullable), actorUserId (nullable), actorType, action, targetType, targetId, metadata (json), ipAddress, userAgent, createdAt                                                                                                      |
| `AiUsage`  | id, organizationId, emailAccountId (nullable), emailMessageId (nullable), documentId (nullable), operation, provider, model, tier, inputTokens, outputTokens, estimatedCostMinor, currency, latencyMs, success, errorCode, metadata (json), createdAt |
| `AiBudget` | id, organizationId, period, limitMinor, currency, hardStop, currentPeriodStart, currentSpendMinor, updatedAt                                                                                                                                          |

Append-only. `AuditLog` se escribe para acciones sensibles (ver `SECURITY.md`).

**`AiUsage` es obligatorio, no opcional.** Cada llamada a un proveedor de IA —económico o
avanzado— deja una fila, incluso si falla. Responde a dos preguntas de negocio que no se
pueden contestar a posteriori si no se registran desde el primer día:

- _¿Cuánto nos cuesta procesar el buzón de este usuario?_
- _¿Cuánto cuesta de IA un usuario medio al mes?_

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

La implementación vigente es `EntitlementSettings`: lee los límites de
`config/packages/entitlements.yaml` por `OrganizationPlan` (D-64), así que cambiar un límite es
editar configuración, no código ni base de datos. La interfaz se mantiene porque es la que
permite que el dominio pregunte «¿puede?» sin saber de planes, de YAML ni de Doctrine.

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

| Capa            | Qué hace                                                                                                                | Coste       | Datos salen del sistema            |
| --------------- | ----------------------------------------------------------------------------------------------------------------------- | ----------- | ---------------------------------- |
| 1. Determinista | Cabeceras, remitente, `message-id`, adjuntos, metadatos del PDF, texto embebido                                         | Nulo        | No                                 |
| 2. Reglas       | Patrones de proveedor, plantillas de factura, expresiones de importe/periodicidad, diccionario de proveedores conocidos | Bajo        | No                                 |
| 3. OCR          | Solo cuando el documento es una imagen o un PDF sin capa de texto                                                       | Medio (CPU) | No                                 |
| 4. IA           | Solo cuando las capas anteriores no alcanzan el umbral de confianza: texto libre, plantillas desconocidas               | Alto        | **Sí, si el proveedor es externo** |

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

| Concepto       | Representación en v1       | Notas                                                                                                                   |
| -------------- | -------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `EmailAccount` | Entidad                    | Cuenta de correo conectada (IMAP o reenvío).                                                                            |
| `EmailMessage` | Entidad                    | Metadatos del mensaje; **no se guarda el cuerpo completo**.                                                             |
| `Document`     | Entidad                    | Adjunto o documento conservado como evidencia.                                                                          |
| `Discovery`    | Entidad                    | Propuesta pendiente de revisión.                                                                                        |
| `Provider`     | Entidad                    | Proveedor (OVH, Microsoft…), global o propio del tenant.                                                                |
| `Service`      | Entidad                    | **Núcleo del dominio.** Es el servicio recurrente que se paga.                                                          |
| `Subscription` | **No es entidad separada** | Se modela como `Service` con `status = active` y periodicidad. Ver D-22.                                                |
| `Invoice`      | Entidad                    | Factura o recibo detectado.                                                                                             |
| `Payment`      | **No es entidad en v1**    | Un cobro es `Invoice` con `paidAt` + `status = paid`. Ver D-04.                                                         |
| `Renewal`      | **No es entidad en v1**    | Propiedades de `Service` (`renewalAt`, `noticePeriodDays`, `autoRenews`, `commitmentEndAt`) + `ServiceEvent`. Ver D-03. |

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

| Transport         | Uso                                 | Notas                                                            |
| ----------------- | ----------------------------------- | ---------------------------------------------------------------- |
| `async`           | Trabajo general                     | Transporte Doctrine.                                             |
| `mail_sync`       | Sincronización de buzones           | Concurrencia baja, respeta límites del servidor IMAP.            |
| `mail_processing` | Procesado por mensaje               | Alto volumen, reintentable.                                      |
| `ocr`             | OCR de documentos sin capa de texto | CPU-intensivo, concurrencia muy baja.                            |
| `ai`              | Llamadas a proveedores de IA        | Concurrencia limitada, rate limit propio y guard de presupuesto. |
| `notifications`   | Entrega de avisos                   | Reintentos con backoff.                                          |
| `failed`          | Mensajes agotados                   | Inspección manual + alerta a administración.                     |

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

| Comando                  | Frecuencia sugerida  | Qué hace                                                                                                              | Idempotente                                             |
| ------------------------ | -------------------- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| `app:mail:sync`          | Cada 15–60 min       | Sincroniza los buzones activos y encola el procesado.                                                                 | Sí (dedup por `folder:uid`)                             |
| `app:alerts:generate`    | Diaria, de madrugada | Recalcula los avisos de cobros, renovaciones, plazos de preaviso y subidas de precio; resuelve los que ya no aplican. | Sí (clave de deduplicación por tipo + servicio + fecha) |
| `app:discoveries:expire` | Diaria               | Caduca las propuestas que nadie ha revisado.                                                                          | Sí                                                      |
| `app:data:purge`         | Diaria               | Aplica la retención de §SECURITY.md §6.                                                                               | Sí                                                      |

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

| Camino              | Quién cambia el contexto               | Cómo se publica                            |
| ------------------- | -------------------------------------- | ------------------------------------------ |
| Petición HTTP       | `ActiveOrganizationListener`           | `setOrganizationId()`                      |
| Comando de consola  | `TenantContext::runAs()`               | `setOrganizationId()` al entrar y al salir |
| Worker de Messenger | el caso de uso (`ProcessEmailMessage`) | `runAs()`                                  |

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

| Interfaz                       | v1                                                         | Futuro                                   |
| ------------------------------ | ---------------------------------------------------------- | ---------------------------------------- |
| `MailboxProviderInterface`     | IMAP genérico (cliente PHP puro) + **ingesta por reenvío** | Gmail API, Microsoft Graph (OAuth)       |
| `DocumentClassifierInterface`  | Reglas deterministas (remitente, asunto, adjuntos)         | Clasificador IA                          |
| `InvoiceExtractorInterface`    | Parser determinista de PDF/texto cuando sea fiable         | Extractor IA                             |
| `ServiceDiscoveryInterface`    | Matching por proveedor + importe + periodicidad            | Matching asistido por IA                 |
| `RecurrenceDetectorInterface`  | Determinista (intervalos entre facturas)                   | —                                        |
| `DocumentStorageInterface`     | Local (`var/storage`)                                      | S3 / Backblaze B2 / compatible           |
| `NotificationChannelInterface` | `in_app`, `email`                                          | push, Slack, Telegram                    |
| `AiProviderInterface`          | Sin proveedor externo por defecto                          | OpenAI, Anthropic, Mistral, Ollama local |

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

### 9.1 Panel de integraciones (Fase 11)

Configurar un proveedor exigía editar `.env` y **redesplegar**: la configuración vivía fuera
de la aplicación, así que no había forma de verla ni de cambiarla. Con una integración se
sostiene; con cinco, no. En la Fase 11 la configuración pasa a ser **un dato**.

**El catálogo es el código, no la base de datos.** `IntegrationRegistry` declara las
integraciones que el sistema sabe usar: `groq`, `openai`, `anthropic` y `ollama`. Cada
`IntegrationDefinition` declara **qué campos necesita** (credenciales y ajustes), con su tipo, su
etiqueta, sus opciones y sus límites. La pantalla se construye recorriendo esa declaración, de
modo que añadir una integración es **añadir una entrada al registro**, no escribir un formulario
nuevo. Una clave escrita a mano en la base de datos no aparece en el panel: no tiene código
detrás, así que no puede parecer operativa.

**Los secretos van cifrados y no vuelven al navegador.** Las credenciales se cifran con el
mismo cifrador que usa la contraseña del buzón (`v1:<base64(nonce||caja)>`, clave derivada de
`APP_SECRET` con BLAKE2b). En la ficha el campo del secreto se pinta **vacío** y su estado se
dice aparte («ya configurada»): el operador no necesita leer su token, necesita saber que está
puesto. Un secreto guardado que volviera al HTML aparecería en el historial, en la caché del
navegador y en cualquier copia de la página.

**Dejar el secreto en blanco no lo borra.** Es la decisión de diseño que más importa en un
formulario de credenciales: con el campo vacío, «vacío» es ambiguo —«no lo he tocado» o
«quiero quitarlo»—. Se interpreta como **no tocado**, porque al revés, guardar la pantalla por
cualquier otro motivo borraría el token y dejaría la integración muerta sin que nadie lo haya
pedido. Quitarlo es una **acción explícita** (`clear_credentials`), con su propio botón y su
propio mensaje.

**Solo se leen los campos que la definición declara.** El controlador recorre
`definition.credentialFields` y `definition.settingsFields`; un `credential_inventado` o un
`setting_inventado` en el POST **se ignora**. Sin esto, un POST manipulado podría meter ajustes
arbitrarios en la configuración de la organización.

**El mismo catálogo gobierna la elección de IA.** Un proveedor de IA se declara con
`isAiProvider` y con su nivel (`AiTier::CHEAP` o `AiTier::ADVANCED`) en la misma definición, y
`AiPurposeSelector` usa esos mismos datos. El panel de integraciones y el de IA **no pueden
desincronizarse**: no hay dos listas que mantener.

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

Se implementan como _query handlers_ con SQL/DBAL o DQL optimizado, no cargando agregados
completos en memoria.

## 12. Frontend

**Principio: renderizado en servidor, sin SPA.** La interfaz es HTML generado con Twig. El
JavaScript se usa solo donde aporta algo que el HTML no puede dar. Esto reduce código,
superficie de ataque y tiempo de construcción, y encaja con un producto cuyo valor está en los
datos, no en la interacción.

| Pieza                    | Elección                            | Motivo                                                                              |
| ------------------------ | ----------------------------------- | ----------------------------------------------------------------------------------- |
| Plantillas               | **Twig**                            | Renderizado en servidor, escape automático (XSS), herencia de plantillas.           |
| Navegación y formularios | **Turbo** (Hotwired)                | Navegación y envío de formularios sin recarga completa, sin escribir una SPA.       |
| Interacciones puntuales  | **Stimulus**                        | Controladores pequeños y locales (filtros, diálogos, contadores).                   |
| Assets                   | **AssetMapper**                     | Sin Node ni `npm`: no se añade un toolchain al Dockerfile. Versionado por _digest_. |
| Tiempo real              | **Mercure**                         | Ya disponible en la plantilla FrankenPHP. Empuja el progreso de sincronización.     |
| CSS                      | **CSS propio con tokens de diseño** | Sin preprocesador ni build step. Ver D-28.                                          |

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
  **64rem** (1024 px). Si un componente necesita más, se resuelve con _container queries_, no
  añadiendo breakpoints globales.
- **Tipografía y espaciado fluidos** con `clamp()` sobre los tokens. Reduce el número de
  breakpoints necesarios y evita saltos bruscos entre tamaños.
- **Container queries** (`@container`) para los componentes reutilizables (tarjeta de servicio,
  fila de cobro, badge). Un componente se adapta al ancho de **su contenedor**, no al de la
  ventana: así funciona igual en el dashboard a pantalla completa que dentro de una columna
  lateral. Es una ventaja real frente a un framework basado solo en breakpoints de viewport.
- **Layout con Grid y Flexbox**, sin _floats_ ni posicionamiento absoluto para maquetar.

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
que solo funcionen con _hover_: en táctil no existe.

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

| Nivel | Nombre                                | Coste | Sale del sistema |
| ----- | ------------------------------------- | ----- | ---------------- |
| 0     | Ingesta y deduplicación               | Nulo  | No               |
| 1     | Metadatos (cabeceras)                 | Nulo  | No               |
| 2     | Filtros deterministas + billing score | Nulo  | No               |
| 3     | Extracción determinista               | Bajo  | No               |
| 4     | Conocimiento de proveedores y parsers | Bajo  | No               |
| 5     | IA económica                          | Medio | **Sí**           |
| 6     | IA avanzada                           | Alto  | **Sí**           |
| 7     | Validación                            | Nulo  | No               |
| 8     | Service matching                      | Nulo  | No               |

### 13.2 Nivel 0 — Ingesta, identidad estable y deduplicación

**El problema.** Un mensaje puede aparecer varias veces: en dos carpetas, en dos cuentas del
mismo tenant, tras un reenvío, o al repetir una sincronización. Reprocesarlo no es solo
desperdicio de CPU: **puede costar dinero** si vuelve a pasar por IA.

**Señales de identidad y sus límites:**

| Señal                               | Estable entre sincronizaciones       | Única globalmente                             | Disponible sin descargar   | Problema                    |
| ----------------------------------- | ------------------------------------ | --------------------------------------------- | -------------------------- | --------------------------- |
| `folder` + `uid`                    | Sí, mientras no cambie `uidValidity` | **No** (los UID se repiten entre buzones)     | Sí                         | El servidor puede renumerar |
| `Message-ID`                        | Sí                                   | Casi (puede faltar, repetirse o falsificarse) | Sí                         | Ausente en algunos envíos   |
| `contentHash` (SHA-256)             | Sí                                   | **No** (dos correos idénticos legítimos)      | No (requiere el contenido) | Coste de descarga           |
| `emailAccountId` + `folder` + `uid` | Sí                                   | **Sí**, dentro de la cuenta                   | Sí                         | —                           |

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

**Regla explícita:** _si el mismo correo vuelve a aparecer durante una sincronización, no
debe volver a consumir IA._ Se garantiza comprobando, antes de cualquier llamada de IA, que
no exista ya un `ExtractedDocument` válido para ese `contentHash` (§13.14).

**Caché de extracción.** La comprobación anterior se materializa en la tabla
`extraction_cache`, con clave única `(organization_id, content_hash)`. Guarda **solo el
resultado estructurado** (`ExtractedDocument` serializado), nunca el cuerpo del mensaje
(D-10). Cuando hay acierto:

1. se incrementa `hitCount` y se actualiza `lastUsedAt`;
2. se reutiliza el documento, refrescando `sender`, `senderDomain` y `subject` con los del
   mensaje actual —el mismo cuerpo puede llegar desde otra cuenta o con otro asunto, y
   proponer datos de un correo que el usuario no ha visto sería desconcertante—;
3. la transición a `EXTRACTED` se registra con el extractor `cache`, de modo que la
   observabilidad distingue «lo resolvió la caché» de «lo resolvió el extractor».

**La caché está acotada por organización, a propósito.** Compartirla entre tenants sería más
barato, pero convertiría el acierto de caché en un canal lateral: un cliente podría deducir
que otro ha recibido exactamente el mismo correo. El ahorro no compensa la fuga.

**Un cuerpo vacío nunca se cachea.** `contentHash('')` es un hash perfectamente válido, así
que la guarda no puede ser «el hash está vacío»: sería código muerto y haría que **todos** los
mensajes sin cuerpo compartieran una única entrada. La condición es que el cuerpo, ya
recortado, no esté vacío.

**Algoritmo de lectura incremental.** Cada pasada de sincronización sigue este orden:

1. **`UIDVALIDITY`.** Se pregunta al servidor antes de leer nada. Si cambió respecto al
   cursor, el servidor ha renumerado la carpeta: los UID guardados ya no significan lo mismo,
   así que el cursor se reinicia (`lastSeenUid = 0`, `backfillCursor = null`) y se deja
   constancia en el libro de auditoría (`EMAIL_SYNC_CURSOR_RESET`). El reinicio **no** se
   audita en el primer contacto, para no llenar el registro de ruido.
2. **Correo nuevo.** Si el cursor es inicial, se lee la **ventana acotada** (por defecto 6
   meses) con `fetchHeaders(since:)`. Si no, se lee **solo lo posterior a `lastSeenUid`** con
   `fetchHeadersAfter()`. En ambos casos se piden **solo cabeceras**: `setFetchBody(false)` y
   `setFetchFlags(false)`, de modo que el cuerpo no se descarga nunca en esta fase (§13.3).
3. **Backfill progresivo.** Si el cursor tiene `backfillCursor`, se lee un lote **anterior** a
   ese punto con `fetchHeadersBefore()`. El backfill avanza hacia atrás en lotes y termina
   cuando el lote vuelve vacío, cuando llega incompleto o cuando alcanza el UID 1. Así el
   histórico se completa sin bloquear la primera sincronización ni castigar al servidor.
4. **Persistencia y avance.** Los mensajes se guardan, el cursor avanza a
   `max(uid)` —**nunca hacia atrás**— y se registra `lastSyncAt`. El `EmailSyncRun` cierra con
   `messagesSeen`, `messagesProcessed`, `messagesSkipped` y `messagesBackfilled`.

**Rango de UID en IMAP.** La búsqueda por rango (`UID 100:*`) no se puede expresar con la API
de alto nivel del cliente: cualquier valor no numérico se entrecomilla y el servidor lo
rechaza. Se emite como criterio literal (`CUSTOM UID 100:*`), que es la única forma de que
llegue sin comillas. Está aislado en un único método para que el día que el cliente lo
soporte nativamente el cambio sea de una línea.

**Orden de lectura.** El cliente devuelve los mensajes en orden ascendente de UID, así que
para leer «lo más nuevo» hay que invertir el orden y para leer «lo más antiguo» no. La lectura
hacia delante usa orden ascendente y la de backfill descendente; confundirlos haría que el
backfill releyera siempre los mismos mensajes.

**Backpressure.** Cada pasada está acotada por `limit` (por defecto 200 mensajes) y por un
tope duro de 500 por lectura. Un buzón con años de correo no se procesa de golpe: se procesa
en pasadas sucesivas, y el backfill es lo último que se completa.

### 13.3 Nivel 1 — Metadatos

Antes de descargar o procesar contenido pesado se extraen **solo cabeceras**. IMAP permite
pedir `BODY.PEEK[HEADER]` sin marcar el mensaje como leído y sin traer el cuerpo.

| Campo                 | Uso en el pipeline                                       |
| --------------------- | -------------------------------------------------------- |
| `From`                | Identificación de proveedor, lista de ignorados          |
| `Reply-To`            | Detección de remitentes de facturación enmascarados      |
| `To`                  | Detección de correo dirigido a la organización           |
| `Subject`             | Palabras clave, patrones, nombre de servicio             |
| `Date`                | Fecha de factura, orden temporal, periodicidad           |
| `Message-ID`          | Identidad secundaria, deduplicación                      |
| `IMAP UID`            | Identidad primaria                                       |
| `Content-Type`        | Detección de `multipart`, HTML vs texto                  |
| Nombres de adjuntos   | Patrón de factura, tipo de documento                     |
| Tipos de adjuntos     | PDF, imagen (candidata a OCR), XML (factura electrónica) |
| Tamaño                | Límite de descarga, coste estimado                       |
| Dominio del remitente | Reconocimiento de proveedor, agrupación                  |

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

| Señal                                                             | Peso por defecto |
| ----------------------------------------------------------------- | ---------------- |
| Asunto con palabra clave de facturación                           | +25              |
| Remitente o dominio en `ProviderIdentity` (proveedor conocido)    | +20              |
| Adjunto PDF                                                       | +15              |
| Importe detectable en asunto o cuerpo                             | +15              |
| Lenguaje de renovación o próximo cobro                            | +10              |
| `local-part` de facturación (`invoice@`, `billing@`, `facturas@`) | +10              |
| Nombre de adjunto con patrón de factura                           | +10              |
| Remitente en lista de ignorados                                   | −100             |
| Boletín, `no-reply` de marketing, notificación social             | −30              |
| Respuesta o reenvío dentro de un hilo propio                      | −10              |

**Umbral por defecto: `billingScore ≥ 40` → `CANDIDATE`.** Por debajo, el mensaje pasa a
`IGNORED` y **no se descarga su contenido**.

**Los pesos son configurables y están documentados**, no son reglas rígidas enterradas en el
código. Viven en configuración versionada, se pueden ajustar por organización y se registran
en `EmailMessage.billingReasons` para poder explicar la decisión.

**Por qué no solo palabras clave.** Un boletín con la palabra "invoice" en el asunto no es una
factura; una factura de un proveedor conocido con asunto en otro idioma sí lo es. El score
pondera señales independientes en lugar de aplicar una lista de términos.

**Formas de entrada en `ignored_senders`.** La lista de remitentes ignorados admite tres
formas, porque en la práctica hacen falta las tres:

| Entrada                  | Coincide con                       | Ejemplo                  |
| ------------------------ | ---------------------------------- | ------------------------ |
| `facturas@proveedor.com` | Esa dirección exacta               | `facturas@proveedor.com` |
| `@proveedor.com`         | Cualquier dirección de ese dominio | `avisos@proveedor.com`   |
| `proveedor.com`          | El dominio del remitente           | `mail.proveedor.com`     |

La comparación de dominios es **por etiquetas completas**, no por sufijo de cadena: se acepta
`mail.linkedin.com` para la entrada `linkedin.com`, pero **no** `falsolinkedin.com` ni
`linkedin.com.evil.io`. Comparar con `str_ends_with()` a secas convertiría la lista de
ignorados en un vector de suplantación: bastaría con registrar un dominio que termine en el
nombre del proveedor legítimo para colarse en la bandeja de entrada.

La coincidencia se registra en `billingReasons` con la entrada concreta que la produjo, para
que la decisión siga siendo explicable. La semántica completa está en D-42.

**El clasificador es un puerto, no una clase.** `ProcessEmailMessage` depende de
`BillingClassifierInterface` (D-31), no de la implementación concreta. Así el score se puede
sustituir —por una versión por organización, por una que consulte conocimiento aprendido del
buzón (§13.12) o por un doble en pruebas— sin tocar el orquestador.

### 13.5 Máquina de estados y trazabilidad

Cada mensaje tiene un **estado explícito** que responde a _qué ocurrió, cuándo, con qué
extractor, si intervino IA, con qué resultado y por qué_.

| Estado            | Significado                                            | Transiciones                                |
| ----------------- | ------------------------------------------------------ | ------------------------------------------- |
| `RECEIVED`        | Ingestado, identidad asignada, sin analizar            | → `IGNORED`, `CANDIDATE`, `FAILED`          |
| `IGNORED`         | Descartado por billing score o lista de exclusión      | terminal (revisable a mano)                 |
| `CANDIDATE`       | Superó el filtro determinista; merece extracción       | → `EXTRACTED`, `DEFERRED`, `FAILED`         |
| `EXTRACTED`       | Datos extraídos y validados                            | → `CLASSIFIED`, `REQUIRES_REVIEW`, `FAILED` |
| `CLASSIFIED`      | Tipo de documento determinado con confianza suficiente | → `MATCHED`, `DISCOVERY`, `REQUIRES_REVIEW` |
| `MATCHED`         | Asociado a un `Service` existente con confianza alta   | terminal                                    |
| `DISCOVERY`       | Generó un `Discovery` pendiente de revisión            | terminal (el `Discovery` sigue su ciclo)    |
| `REQUIRES_REVIEW` | Confianza insuficiente o datos contradictorios         | → `EXTRACTED`, `IGNORED`                    |
| `DEFERRED`        | Sin presupuesto de IA o fallo transitorio              | → `CANDIDATE`, `EXTRACTED`, `FAILED`        |
| `FAILED`          | Error permanente                                       | terminal                                    |

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

**Serialización y reutilización.** `ExtractedDocument` sabe volver de su forma en array
(`fromArray()`), que es lo que permite persistirlo en `Discovery.proposedData`, en el evento
`EXTRACTED` y en la caché de extracción. La vuelta es **defensiva**: un campo desconocido o de
tipo inesperado degrada a `null` en lugar de lanzar, porque un dato corrupto en la caché no
debe tumbar el procesamiento de un mensaje. `withSenderContext()` refresca los tres campos que
dependen del mensaje concreto (`sender`, `senderDomain`, `subject`) al reutilizar una
extracción cacheada.

### 13.7 Nivel 4 — Conocimiento de proveedores y parsers

El sistema distingue entre **proveedor conocido** (`invoice@ovh.com` → `OVH`) y **proveedor
desconocido** (cualquier remitente que todavía no sabemos identificar).

**Modelo adoptado.** El conocimiento de proveedores se separa en tres piezas, porque
responden a tres preguntas distintas:

| Entidad            | Pregunta que responde        | Ámbito              |
| ------------------ | ---------------------------- | ------------------- |
| `Provider`         | _¿Quién es?_                 | Global o del tenant |
| `ProviderIdentity` | _¿Cómo lo reconocemos?_      | Global              |
| `ProviderParser`   | _¿Cómo extraemos sus datos?_ | Global              |

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
  "amount": 29.9,
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

#### 13.8.1 Lo que se implementó (Fase 11)

**El proveedor se elige por necesidad y por organización.** `AiPurpose` enumera las cuatro
necesidades (`extraction`, `advanced_extraction`, `summarization`, `classification`) y
`AiPurposeSelector` guarda en `Organization.settings` qué proveedor se usa en cada una:

```json
{
  "ai.enabled": true,
  "ai.providers": { "extraction": "groq", "advanced_extraction": "openai" }
}
```

La elección es **por cliente**, no global: el que paga la factura de IA es quien decide con qué
proveedor se le cobra. Un `purpose` o un `provider` que no exista se ignora —la lista válida la
pone el código, no el formulario— y la IA está **desactivada por defecto** (D-08): sin
`ai.enabled` no hay proveedor, aunque las claves estén configuradas.

**El escalado del nivel 6 comprueba el nivel del proveedor, no solo su nombre.** Un proveedor
económico elegido para `advanced_extraction` **no** se usa: se resolvería el proveedor real con
`forPurpose()` y se exige que su `AiTier` sea `ADVANCED`. Sin esa comprobación, un `CHEAP`
pagaría una llamada etiquetada como avanzada y el panel de coste mentiría.

**La redacción previa es un caso de uso propio.** `RedactContent` quita HTML, firmas, avisos
legales, tracking y contenido irrelevante, limita la longitud y devuelve el texto con el que se
llama al modelo. Nunca se envía el correo entero.

**El coste se persiste en el momento de la llamada** (D-36). `AiUsage` guarda tokens de entrada
y salida, el coste en céntimos y el nivel. Los precios salen de `AiPriceCatalog` (tarifas
públicas por defecto) y se pueden corregir por integración (`prompt_price`,
`completion_price`); un proveedor desconocido se registra como `0`, que es un cero conocido, no
una cifra inventada.

**El presupuesto se comprueba antes de llamar.** `AiBudget` cuenta el mes natural y
`allowsCall()` responde si se puede gastar; `AiExtractionService` lo pregunta **antes** de
invocar al proveedor, de modo que un tope agotado no cuesta una llamada.

### 13.9 Nivel 7 — Validación posterior a la IA

**La IA no es una fuente de verdad.** Su salida es una _propuesta_ que atraviesa una cadena de
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

#### 13.9.1 Lo que se implementó (Fase 11)

`AiExtractionResult` es el **DTO tipado** de la salida del modelo. Sus restricciones son
`Assert\*` sobre propiedades, y `ExtractionValidator` añade las reglas de negocio que el
Validator no expresa (coherencia de fechas, importe coherente con el contexto).

Una sutileza de Symfony 8 que conviene documentar porque **rompe en producción**: `Assert\Range`
no admite `min` y `max` **junto con** `minMessage`/`maxMessage`; la combinación está prohibida y
lanza `ConstraintDefinitionException` al construir la restricción. Con `min` y `max` hay que usar
`notInRangeMessage` (o dos restricciones separadas). Una validación que falla al construirse no
produce «un campo con error»: **tira toda la extracción**.

En el pipeline, el documento que devuelve la IA **solo se adopta si es accionable** (importe,
proveedor y periodicidad recurrente). Si no lo es, el pipeline sigue con lo que tenía y el caso
acaba en `REQUIRES_REVIEW`: una IA que no sabe leer un documento no debe poder empeorar lo que
las reglas ya habian conseguido.

### 13.10 Nivel 8 — Service matching

**Regla fundamental: nunca se crea un servicio automáticamente.** Primero se intenta
**asociar** el documento a un `Service` existente. Solo si no hay coincidencia suficiente se
propone uno nuevo.

**Puntuación ponderada:**

| Señal                          | Peso |
| ------------------------------ | ---- |
| Proveedor coincide             | +40  |
| Dominio del remitente coincide | +20  |
| Nombre del servicio coincide   | +20  |
| Moneda coincide                | +5   |
| Periodicidad coincide          | +5   |
| Importe similar (±10 %)        | +5   |
| Remitente exacto coincide      | +5   |

**Umbrales (configurables):**

| Puntuación | Resultado  | Acción                                                   |
| ---------- | ---------- | -------------------------------------------------------- |
| ≥ 70       | **HIGH**   | Asociación automática al `Service` existente → `MATCHED` |
| 40–69      | **MEDIUM** | `Discovery` para revisión del usuario → `DISCOVERY`      |
| < 40       | **LOW**    | `Discovery` de servicio nuevo → `DISCOVERY`              |

El desglose se guarda en `Discovery.matchScore` y `Discovery.matchReasons`, de modo que la
interfaz puede explicar _por qué_ el sistema cree que dos cosas son el mismo servicio.

**Por qué ponderado y no reglas duras.** Un mismo servicio puede facturarse desde dominios
distintos, con importes que cambian o con nombres ligeramente diferentes. Una regla dura
("mismo dominio y mismo importe") falla en cuanto cambia cualquiera de los dos; una
puntuación tolera la variación y expresa la incertidumbre en lugar de ocultarla.

### 13.11 Discovery y confirmación

Cuando la confianza no es suficiente, se crea un `Discovery` en estado `pending`. La interfaz
muestra:

- proveedor y servicio propuestos;
- importe, moneda y periodicidad;
- **evidencia**: _"encontramos 4 documentos similares"_, con enlaces a los correos y
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

|                            | Primera sincronización | Sincronizaciones posteriores |
| -------------------------- | ---------------------- | ---------------------------- |
| Mensajes vistos            | 10.000                 | 200 nuevos                   |
| Candidatos (billing score) | 400                    | 30                           |
| Requieren IA económica     | 100                    | 5                            |
| Requieren IA avanzada      | 15                     | 0                            |

La curva es el argumento económico del diseño: **el coste de IA por buzón decrece**, y el
producto se vuelve más rentable cuanto más tiempo lleva un usuario.

#### Cómo se implementó (Fase 13)

El conocimiento vive en `MailboxKnowledgeEntry`, en `src/Processing/Domain/Entity/`, con una fila
por regla y por buzón:

| Columna                            | Para qué sirve                                                                              |
| ---------------------------------- | ------------------------------------------------------------------------------------------- |
| `email_account_id`                 | El buzón. Todo el conocimiento es **por buzón**, nunca por organización.                    |
| `organization_id`                  | El tenant, para poder filtrar sin joins.                                                    |
| `type`                             | `sender_mapping`, `provider_hint`, `subject_pattern`, `document_pattern`, `ignored_sender`. |
| `value`                            | El dato: una dirección, un dominio o una **expresión regular** (patrones).                  |
| `provider_id`                      | Proveedor del catálogo, si el tipo lo lleva.                                                |
| `document_type` / `billing_period` | Lo que el tipo permite ascertain.                                                           |
| `source`                           | `observed`, `confirmation`, `user`, `promoted`: **de dónde** se aprendió.                   |
| `confidence`                       | 0–100. Por encima de 60, un remitente ignorado **excluye** el mensaje.                      |
| `hit_count` / `last_used_at`       | Para la poda.                                                                               |
| `promoted_at`                      | Marca de que el usuario lo ha promovido al catálogo global.                                 |

**Las piezas:**

- `ResolveMailboxKnowledge` — lee el conocimiento de un buzón y produce un `MailboxKnowledge`
  (proveedor, tipo de documento, periodicidad, reglas de exclusión). Se consulta **antes** que el
  catálogo global (D-51).
- `LearnFromMessage` — observa un correo ya procesado y anota lo que ha aprendido. **Reglas de
  guarda:** las reglas de remitente y de dominio solo se aprenden si el proveedor es conocido; las
  plantillas de asunto y de adjunto solo si el tipo de documento no es `OTHER`; como máximo tres
  plantillas de adjunto por correo.
- `LearnFromConfirmation` / `LearnFromDismissal` — el aprendizaje desde la acción del usuario.
  Confirmar es autoritativo (el dato del usuario **reemplaza** al deducido); descartar suma
  confianza al remitente ignorado (D-53).
- `PatternTemplate` — convierte un texto en una plantilla con los huecos `{fecha}`, `{n}` y
  `{txt}`, anchored al principio y al final. Los tokens pegados a un guion bajo (`factura_ABC123`)
  se sustituyen igual: los límites son miradas alrededor, no `\b`.
- `PromoteMailboxKnowledge` — la **única** vía al catálogo global (D-54).
- `PruneMailboxKnowledge` — borra lo que no se ha usado nunca y es viejo (90 días por defecto).
- `MailboxKnowledgeController` — la pantalla: ver, escribir, corregir, ignorar, promover y borrar.

**Dónde se engancha al pipeline.** `ProcessEmailMessage` consulta el conocimiento al empezar:

1. si el remitente está excluido, el mensaje **se descarta antes de descargar el cuerpo y antes de
   llamar a la IA**;
2. si hay proveedor aprendido, ese proveedor es el que se resuelve (D-51);
3. la DeterministicExtractor recibe el `MailboxKnowledge` y usa la periodicidad aprendida cuando
   el texto no la da (D-52);
4. al terminar, `LearnFromMessage` anota lo aprendido.

**El interruptor.** `config/packages/knowledge.yaml` fija la política del almacén: `stale_days` (90,
cuánto se recuerda una entrada que nunca se ha usado) y `max_entries_per_account` (500, un seguro
contra el ruido: un buzón con miles de remitentes ha dejado de ser un buzón de suscripciones).

### 13.13 Control de coste de IA

**Es un requisito crítico, no una optimización posterior.** Sin control de coste, un buzón
grande o un bucle de reintentos puede generar una factura ilimitada.

**Registro obligatorio.** Cada llamada deja una fila en `AiUsage` (§4.8), incluso si falla.
Esto permite responder a las dos preguntas que no se pueden contestar a posteriori:

- _¿Cuánto nos cuesta procesar el buzón de este usuario?_
- _¿Cuánto cuesta de IA un usuario medio al mes?_

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

#### Cómo se implementó (Fase 14)

**La política se guarda; el gasto se calcula.** `AiBudget` es una fila por organización y
periodo (`YYYY-MM`) con el **tope** y el **modo**. El gasto **no** se guarda: sale de agregar
`ai_usage`, que es el libro mayor y la única fuente de verdad. Dos verdades sobre la misma
cifra se contradicen en cuanto una escritura falla (D-55).

`AiBudgetGuard` es el guardián, y hace tres preguntas:

| Método             | Pregunta                                        |
| ------------------ | ----------------------------------------------- |
| `allowsCall()`     | ¿Se puede llamar a un modelo ahora mismo?       |
| `allowsTier(tier)` | ¿Se puede llamar a un modelo **de este nivel**? |
| `status()`         | ¿Cuánto he gastado, cuánto queda, y hay aviso?  |

El guard se comprueba **antes** de cada llamada, en `AiExtractionService` y también en
`AdvancedAiDocumentExtractor` (que es el que decide si se escala al nivel caro).

**Degradar o parar.** El modo lo elige la organización, por mes (D-56):

- `SOFT` (por defecto): se sigue usando la IA económica y **se apaga el nivel 6**, que es el
  caro. El pipeline continúa por reglas.
- `HARD`: no se llama a ningún modelo. El correo que **necesitaba** IA no va a revisión manual
  —no está mal, está esperando— sino que queda en `DEFERRED` con el motivo
  `budget_exhausted`, y se reintenta cuando hay presupuesto.

**El motivo viaja con el resultado.** La escalera de IA devuelve un `AiExtractionOutcome` con el
documento y un `AiSkipReason` (`NOT_CONFIGURED`, `NO_TEXT`, `NOT_NEEDED`, `FAILED`,
`RATE_LIMITED`, `BUDGET_EXHAUSTED`), en vez de un `null` pelado. Solo `BUDGET_EXHAUSTED` aplaza
el correo; los demás van a revisión manual, que es lo que corresponde (D-57).

**`DEFERRED` no es un estado terminal ni pendiente.** Es un estado de espera: el correo no se
marca como procesado (el tiempo medio mediría los días de espera en vez del trabajo hecho) y no
se reencola en cada sincronización, porque hasta que no haya presupuesto no va a poder avanzar.
Volver a ponerlo en cola es una operación explícita: `ReleaseDeferredMessages`, que lo
implementa `Processing` porque el módulo de IA no sabe qué es un correo aplazado (D-58).

**Límite de peticiones.** `AiCallRateLimiter` aplica una ventana deslizante por organización
(`config/packages/rate_limiter.yaml`). Es la segunda red: el presupuesto acota el **gasto**, el
limitador acota el **número de llamadas**, y un bucle de reintentos no puede disparar una
factura ni aunque el presupuesto alto lo permitiera.

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

| Pregunta                               | Dónde se responde                                               |
| -------------------------------------- | --------------------------------------------------------------- |
| ¿Qué pasó con este mensaje?            | `EmailMessage.processingState` + `MessageProcessingEvent`       |
| ¿Por qué se ignoró?                    | `EmailMessage.billingScore` + `billingReasons`                  |
| ¿Qué extractor lo resolvió?            | `EmailMessage.extractorUsed` + `extractionTier`                 |
| ¿Intervino IA?                         | `EmailMessage.aiUsed` + `aiUsageId` en el evento                |
| ¿Cuánto costó?                         | `AiUsage.estimatedCostMinor`                                    |
| ¿Cuánto llevamos gastado este mes?     | Suma de `ai_usage` del periodo (D-55)                           |
| ¿Cómo va la sincronización?            | `EmailSyncRun` + eventos Mercure                                |
| ¿Cuántos mensajes se resuelven sin IA? | Agregado sobre `extractionTier`                                 |
| ¿Cuánto descarta el nivel 2?           | `app:mail:stats` sobre `EmailMessageRepository::countByState()` |

**`app:mail:stats`.** Una decisión de coste que no se mide se degrada sola (D-36). El comando
imprime, por organización, el embudo completo del pipeline: leídos, descartados, porcentaje de
descarte, candidatos, descubrimientos, en revisión, fallidos y entradas en caché. Es de solo
lectura y acepta `--organization` para acotar. Cuando no hay mensajes, el porcentaje se
muestra como `—` y no como `0,0 %`: un cero fingiría una medición que no existe.

**Métricas de producto derivadas** (ver `PRODUCT.md` §12): porcentaje de mensajes resueltos
sin IA, coste de IA por usuario y mes, tasa de confirmación de descubrimientos, tiempo medio
de procesamiento por mensaje.

#### El panel de coste (Fase 14)

**`/ai/cost`** es la respuesta a «¿cuánto cuesta procesar el buzón de este usuario?». Es una
pantalla de backoffice (`ROLE_USER`) y la calcula `AiCostCalculator`, no la plantilla: el
informe cruza el libro mayor de IA con los mensajes procesados, que están en dos tablas
distintas, y hacer eso en Twig es hacer una consulta por celda.

La foto del mes:

| Dato                                       | De dónde sale                                             |
| ------------------------------------------ | --------------------------------------------------------- |
| Coste total, llamadas, tokens, fallos      | `ai_usage` del mes natural                                |
| Coste por proveedor y por necesidad        | `AiUsageRepository::costByProvider()` / `costByPurpose()` |
| Llamadas y latencia media por nivel        | `callsByTier()` / `durationByTier()`                      |
| Presupuesto: tope, gastado, restante, modo | `AiBudgetGuard::status()`                                 |
| Mensajes resueltos sin IA                  | `EmailMessageRepository::extractionStats()`               |
| Tasa de escalado a IA avanzada             | Llamadas de nivel avanzado ÷ llamadas totales             |
| Correos aplazados esperando presupuesto    | `countByState()['deferred']`                              |

El periodo es el **mes natural**, el mismo que usa el presupuesto: las facturas de los
proveedores de IA van por mes natural, así que el mes de Suscripta y el mes de la factura se
comparan sin traducción.

Los porcentajes se calculan en PHP y **no** se dejan en Twig. Cuando no hay llamadas, el
porcentaje es `0` y no `null`: un panel que dice «—» obliga al operador a decidir si es un dato
que falta o un cero.

La pantalla también ofrece **volver a poner en cola los correos aplazados**, que es la vía
manual de `ReleaseDeferredMessages`. Es un no-op si el presupuesto sigue agotado, y el mensaje
de la pantalla lo dice: «No hay correos aplazados, o el presupuesto sigue agotado».

**`/ai`** (ajustes de IA) incluye el tope —en euros, vacío significa «sin tope»— y el modo.
Al subir el tope se sueltan los correos aplazados y se avisa por _flash_ con cuántos han sido.

**Avisos de coste.** `GenerateCostAlerts` genera `ai_budget_warning` y `ai_budget_exceeded`, uno
por mes (la clave de deduplicación incluye el último día del mes). No vive en el barrido general
de alertas: el presupuesto lo lleva el módulo de IA, y meterlo en `AlertRules::generatedTypes()`
obligaría al generador general a resolver avisos de coste en cada pasada. Lo que sí se comprueba
es que **entre los dos generadores cubran todos los tipos**: `AlertRules::allGeneratedTypes()`.

### 13.17 Interfaces y responsabilidades

**Interfaces del pipeline** (capa de aplicación; las implementaciones viven en infraestructura):

```php
interface BillingClassifierInterface
{
    public function score(ImapMessageHeader $header, ?string $bodyText = null): BillingScoreResult;
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

interface AiBudgetChangeHandlerInterface
{
    public function onBudgetRaised(): int;
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

**Estado de implementación.** `BillingClassifierInterface` está implementada
(`BillingScoreCalculator`, D-31). El resto son el contrato acordado para las fases 10–14: se
documentan aquí para que las implementaciones no inventen firmas distintas, pero todavía no
existen en el código.

**El presupuesto no usa esa interfaz.** `AiBudgetGuardInterface` era el contrato previsto, pero
la Fase 14 lo implementó de otra forma: `AiBudgetGuard` es una **clase concreta** de la capa
`Application`, no una interfaz con una implementación de infraestructura. La razón es que el
guard no guarda nada: **lee** el libro mayor, **agrega** el gasto y **decide**. No hay una
variante de infraestructura que sea distinta de otra, y una interfaz sin dos implementaciones
es ruido.
El puerto que sí hace falta es el otro: `AiBudgetChangeHandlerInterface`, que el módulo de `Ai`
**declara** y `Processing` **implementa** (D-58).

**Responsabilidades por módulo:**

| Módulo          | Responsabilidad en el pipeline                                                                             | Lo que **no** hace                               |
| --------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| `Mailbox`       | Ingesta (IMAP, reenvío), identidad del mensaje, cursores, `EmailSyncRun`                                   | No interpreta el contenido                       |
| `Processing`    | Clasificación, extracción, validación, máquina de estados, conocimiento del buzón, liberación de aplazados | No conoce proveedores de IA concretos            |
| `Ai`            | Adaptadores de proveedor, `AiUsage`, `AiBudget`, guard de presupuesto, informe de coste                    | No conoce el dominio ni los estados del pipeline |
| `Catalog`       | `Provider`, `ProviderIdentity`, `ProviderParser`: reconocimiento y parsers                                 | No decide asociaciones                           |
| `Documents`     | `Document`, `Invoice`: persistencia del resultado                                                          | No extrae                                        |
| `Discovery`     | `ServiceMatcher`, `Discovery`, recurrencia, cambios                                                        | No descarga correo                               |
| `Notifications` | Alertas y entrega                                                                                          | No procesa correo                                |

**Regla de dependencia:** `Processing` puede depender de `Mailbox`, `Documents`, `Catalog` y
`Ai`; **`Services` (dominio) no depende de ninguno de ellos** (§4.10). El dominio solo ve el
resultado de la extracción, nunca cómo se obtuvo.

**La dependencia va en un solo sentido.** La Fase 14 introdujo un ciclo: `Ai` necesita
avisar de que el presupuesto ha cambiado, y `Processing` es quien sabe qué hacer con un correo
aplazado. Se rompe con `AiBudgetChangeHandlerInterface`: `Ai` **declara** el puerto y
`Processing` lo **implementa** (D-58). La regla es general, no un caso concreto: un puerto lo
declara quien **necesita** algo, y lo implementa quien **sabe** hacerlo.

### 13.18 Detección de cambios: hechos e inferencias

Hasta la fase 14, todo lo que aparecía en la bandeja de descubrimientos venía de un documento: el
sistema lo había **leído**. La fase 15 añade otra fuente —el propio inventario— y con ella una
distinción que hay que poder defender delante del usuario: **un hecho no es lo mismo que una
sospecha**.

**Las dos clases de propuesta**

|                | Hecho                                        | Inferencia                                          |
| -------------- | -------------------------------------------- | --------------------------------------------------- |
| Origen         | Un documento (factura, aviso de renovación)  | El inventario de servicios                          |
| Ejemplos       | `NEW_SERVICE`, `PRICE_CHANGE`, `PLAN_CHANGE` | `DUPLICATE`, `MISSING_CHARGE`, `DORMANT`            |
| Confianza      | Hasta `HIGH`                                 | Recortada a la banda `LOW` (`≤ 54`)                 |
| En la interfaz | Formulario de alta o de cambio               | «Esto es una sugerencia, no un hecho» + dos salidas |

La marca `is_inference` es una **columna**, no un dato del JSON `proposed_data`, porque la interfaz
la usa para dos cosas a la vez: **filtrar** la bandeja (`?scope=facts` / `?scope=suggestions`) y
**presentar** la propuesta de otra manera. Filtrar por un JSON sin índice es un filtro en memoria
sobre toda la tabla (D-59).

**El barrido**

`DetectChanges` es el agregador: recorre el inventario y delega en tres detectores. Cada uno
devuelve propuestas `Discovery` marcadas como inferencia, con su fuerza de señal en `matchScore` y
su explicación en `matchReasons`.

```text
DetectChanges
├── DetectDuplicates      → ¿dos servicios son el mismo?
├── DetectMissingCharges   → ¿falta un cobro que se esperaba?
└── DetectDormantServices  → ¿este servicio sigue vivo?
```

**Las reglas, con sus números**

- **Duplicado** — proveedor 45, importe 30, periodicidad 15, nombre 40 (solo si la similitud es
  ≥ 85) y categoría 5; **umbral 70**. El más antiguo es el original, el más reciente el
  sospechoso. Consecuencia práctica: dos servicios con nombres parecidos pero **proveedores
  distintos** no llegan al umbral, porque 30 + 15 = 45.
- **Cobro que no ha llegado** — servicio `ACTIVE` con periodicidad recurrente, con el cobro esperado
  más de `graceDays` (7) de retraso y **ninguna** factura emitida desde `expectedAt - graceDays`. La
  señal crece con el retraso: `60 + intdiv(overdueDays, 2)`.
- **Servicio inactivo** — servicio `ACTIVE` sin facturas ni señales de vida (eventos `RENEWED`,
  `RECURRENCE_DETECTED`, `DOCUMENT_ADDED`) desde hace más de `dormantServiceDays` (180). Si no hay
  ninguna señal, la referencia de inactividad es la fecha de alta. Fuerza: `60 + intdiv(inactiveDays, 4)`.

**Idempotencia.** El barrido está pensado para correr cada noche. `findByDedupKey()` busca la
propuesta en **cualquier** estado, no solo en las pendientes: si el usuario descartó una sospecha
ayer, la de hoy no vuelve a preguntarle lo mismo. Las claves son `serviceId|expectedAt`,
`serviceId|inactiveSince` y la pareja ordenada de ids.

**Confirmar una inferencia no borra historia.** `ConfirmInference` aplica la acción que
corresponde y **siempre** deja un rastro (`ServiceEvent::NOTE_ADDED`):

| Inferencia       | Qué hace                             | Qué deja                  |
| ---------------- | ------------------------------------ | ------------------------- |
| `DUPLICATE`      | Cancela el servicio **más reciente** | Nota en los dos servicios |
| `MISSING_CHARGE` | Adelanta `nextChargeAt`              | Nota en el servicio       |
| `DORMANT`        | Cancela el servicio                  | Nota en el servicio       |

El servicio cancelado conserva su historial completo. Cancelar no es borrar: es un estado más del
servicio, y el evento dice qué pasó y cuándo.

**Avisos.** Los cinco tipos nuevos cuelgan de propuestas **pendientes**
(`Alert::buildDedupKey($tipo, null, null, $discoveryId)`), con severidad `INFO` salvo
`POSSIBLE_DUPLICATE` y `MISSING_CHARGE`, que son `WARNING`. `resolveStaleAlerts()` mantiene
abierto cualquier aviso cuya propuesta siga pendiente: mientras el usuario no ha decidido, el
aviso sigue vivo.

**Dónde vive cada cosa**

| Pieza                | Ubicación                                                                 | Nota                                      |
| -------------------- | ------------------------------------------------------------------------- | ----------------------------------------- |
| Marca de inferencia  | `Discovery::$isInference` + columna `is_inference`                        | Filtro e interfaz                         |
| Recorte de confianza | `Discovery::markAsInference()`                                            | Tope `LOW->maxScore()`                    |
| Detección de plan    | `ServiceMatchResult::$servicePlanName` + `ServiceMatcher::scoreAgainst()` | El plan lo extrae el parser del proveedor |
| Barrido              | `src/Discovery/Application/Change/`                                       | Un agregador y tres detectores            |
| Umbrales             | `config/packages/discovery.yaml` → `ChangeSettings`                       | Decisiones de producto                    |
| Confirmación         | `Discovery\Application\ConfirmInference`                                  | Único punto que muta el inventario        |
| Comando              | `Discovery\UI\Command\DetectChangesCommand`                               | `app:changes:detect`                      |

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

## 15. Privacidad y retención (Fase 16)

### 15.1 El patrón: puertos publicados por el módulo

El borrado de una organización toca servicios, documentos, correos, propuestas, avisos,
integraciones, uso de IA y datos de identidad. Si el agregador de borrado conociera los
repositorios de cada módulo, cada módulo nuevo obligaría a tocar el agregador, y el
agregador se convertiría en el punto donde se rompen las dependencias entre módulos.

La solución es invertir el sentido: **cada módulo publica lo que sabe hacer**, y el
agregador solo conoce puertos.

```text
src/Shared/Application/Privacy/
├── OrganizationDataProviderInterface   # qué exporta el módulo y cuántas filas tiene
├── OrganizationDataRemoverInterface   # cómo borra el módulo sus datos
├── RetentionPurgerInterface           # cómo purga el módulo por antigüedad
└── PrivacySettings                    # la política de retención, leída de config
```

```text
src/<Módulo>/Application/Privacy/       # una implementación por módulo y por puerto
src/Privacy/Application/                # los agregadores que coordinan los puertos
├── ExportOrganizationData
├── DeleteOrganizationData
└── PurgeExpiredData
```

| Agregador                | Puertos que usa                     | Devuelve                              |
| ------------------------ | ----------------------------------- | ------------------------------------- |
| `ExportOrganizationData` | `OrganizationDataProviderInterface` | `DataExport` (secciones + recuentos)  |
| `DeleteOrganizationData` | `OrganizationDataRemoverInterface`  | `RemovalReport` (detalle por módulo)  |
| `PurgeExpiredData`       | `RetentionPurgerInterface`          | `PurgeReport` (detalle por categoría) |

La política de retención vive en `config/packages/privacy.yaml` y se interpreta en
`PrivacySettings`. Los plazos son **por categoría de dato**, no por módulo: el correo es
evidencia temporal y se purga antes; los servicios son el producto y sobreviven a la
desconexión del buzón.

### 15.2 El borrado se lleva la organización

`DeleteOrganizationData` borra los datos **y** la organización. Es la única forma de que el
borrado sea completo: si la organización sobreviviera, sus credenciales, sus correos y sus
binarios seguirían siendo recuperables por un `organization_id` huérfano (D-61).

El usuario al que se le borra la organización se queda **sin organización**, y el panel lo
lleva a crear una nueva (`/organization/new`). `ActiveOrganizationListener` cae al primer
`memberships[0]` cuando la organización de la sesión ya no le pertenece, de modo que la
aplicación nunca queda en un estado imposible.

### 15.3 Purga en tandas

PostgreSQL no admite `LIMIT` en un `DELETE`, así que la purga selecciona los identificadores
que cumplen el criterio de antigüedad y los borra con `DELETE ... WHERE id IN (:ids)`, en
lotes de `purge_batch_size` (500 por defecto).

> **Trampa de Doctrine ORM 3:** `Query::getScalarResult()` devuelve **filas asociativas**
> (`['id' => '…']`), no una lista plana de escalares. Los identificadores deben obtenerse con
> `getSingleColumnResult()` (`HYDRATE_SCALAR_COLUMN`) y normalizarse con
> `DoctrineRepository::scalarIds()`, que devuelve `list<string>`. Con `getScalarResult()` el
> `IN` recibe arrays, PHP los convierte a la cadena `"Array"` y el borrado no borra nada: el
> fallo es silencioso. Hay un test de integración (`RetentionPurgeTest`) que lo cubre.

### 15.4 Cabeceras de seguridad

`SecurityHeadersListener` (en `src/Shared/UI/EventListener/`) añade la CSP con **nonce** y el
resto de cabeceras en cada respuesta. La plantilla la lee con `csp_nonce()`
(`src/Shared/UI/Twig/CspExtension.php`), de modo que no hay scripts en línea sin nonce y
`unsafe-inline` no aparece en la política.

En modo `debug`, Symfony 8 elimina la CSP de las **páginas de error**
(`ErrorListener::removeCspHeader()`); en producción se mantiene. El resto de cabeceras
(`X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`) sí se aplican
siempre.

### 15.5 Rate limiting

`RateLimitGuard` (`src/Shared/Application/RateLimit/`) es la puerta de frecuencia
compartida. La clave es **por organización**, para que una organización no pueda agotar el
límite de otra. Los limitadores se declaran en `config/packages/rate_limiter.yaml`; el
entorno de test replica los límites reales de producción (`config/packages/test/rate_limiter.yaml`),
porque un test que prueba un límite de 1000 no prueba nada.

## 16. Planes, cuotas y preparación SaaS (Fase 17)

### 16.1 El plan es un techo, no una etiqueta

Un plan no dice «qué ha comprado el cliente»: dice **hasta dónde puede llegar la
organización**. `OrganizationPlan` (enum) declara qué planes existen; los números viven en
`config/packages/entitlements.yaml` (D-64). La pantalla `/plan` enseña el consumo real de la
organización contra ese techo.

| Capacidad         | Tipo        | Gratis | Profesional     |
| ----------------- | ----------- | ------ | --------------- |
| `services`        | límite      | 3      | sin techo       |
| `email_accounts`  | límite      | 1      | 5               |
| `documents`       | límite      | 20     | 500             |
| `ai`              | interruptor | no     | sí              |
| `ai_budget_minor` | límite      | 0      | 10 000 céntimos |

### 16.2 Límite y uso son dos cosas distintas

El **límite** lo dice la configuración; el **uso** lo sabe el módulo que tiene los datos
(cuántos servicios, cuántos buzones, cuántos documentos). Ninguno de los dos sabe lo
suficiente por sí solo, así que se cruzan en un sitio:

```text
src/Shared/Application/Entitlements/
├── Capability                        # los nombres de las capacidades
├── EntitlementCheckerInterface       # ¿qué permite el plan?  (límite)
├── EntitlementUsageProviderInterface  # ¿cuánto se usa?        (uso)
├── EntitlementSettings               # lee la configuración y la valida
├── QuotaGuard                        # cruza límite y uso
├── QuotaCheckerInterface
└── QuotaStatus                       # la foto que se pinta
```

Cada módulo publica su uso **con una etiqueta de servicio**, para que el módulo transversal
no importe repositorios de negocio:

```text
src/Services/Application/Entitlements/ServiceUsageProvider.php
src/Mailbox/Application/Entitlements/EmailAccountUsageProvider.php
src/Documents/Application/Entitlements/DocumentUsageProvider.php
src/Ai/Application/Entitlements/AiBudgetUsageProvider.php
```

Cada proveedor declara **su propia clave** con el atributo `#[AsTaggedItem]`:

```php
#[AsTaggedItem(Capability::SERVICES)]
final readonly class ServiceUsageProvider implements EntitlementUsageProviderInterface
```

No es un detalle menor: sin el atributo, el contenedor indexa el proveedor por el **nombre de
su clase**, el guard no encuentra el contador y **ningún límite se aplica** (D-70). La clave va
junto a la clase que la mide, y no en un YAML que habría que recordar actualizar.

### 16.3 La cuota se comprueba en el caso de uso, no en el controlador

`QuotaGuard::assertWithinQuota()` se llama desde el **caso de uso** (`CreateService`,
`ConnectEmailAccount`, `UploadDocument`), nunca desde el controlador. La razón es la Fase 18:
una escritura hecha por la API no pasa por un controlador, y si el límite viviera allí la
API podría saltarse el plan sin querer.

Cuando el límite se agota, el caso de uso lanza `QuotaExceededException`, que **no es un
error HTTP**: quien la recibe decide cómo presentarla. En la web, `QuotaExceededListener`
la convierte en un aviso y vuelve a la pantalla del módulo que pidió la operación; en la API
será un `402 Payment Required` (Fase 18).

### 16.4 La guía de bienvenida

`BuildOnboardingChecklist` calcula los pasos **en vivo** a partir del estado del producto
(D-66). Lo único guardado es «la guía está terminada» (`User.onboardingCompleted`). La guía
no bloquea la aplicación: `OnboardingRedirectListener` solo redirige desde el panel.

### 16.5 Observabilidad de producto

`src/Observability/` responde a cuatro preguntas en el orden en que se depura un problema
real: **sincronización** (¿entra el correo?), **extracción** (¿cómo se resolvió cada correo?),
**IA** (llamadas, fallos, presupuesto y coste) y **avisos** (¿qué me está pidiendo el
sistema?). El periodo (`24h`, `7d`, `30d`) va en la URL, no en la sesión: `/observability?period=30d`
es un enlace que se puede mandar tal cual.

> **Trampa de Doctrine ORM 3:** un enum mapeado con `enumType` vuelve de una agregación DQL
> como **objeto**, y no puede usarse como clave de array (`TypeError`). Hay que convertirlo
> explícitamente (D-69).
>
> **Trampa de coste de IA:** el coste se guarda en microcéntimos (`AiCost`, columna
> `cost_micro`), no en céntimos. Un redondeo a céntimos borraría el gasto real (D-68).

### 16.6 El módulo `Demo` es un compositor

`src/Demo/` es la **excepción declarada** a la regla de módulos (§3): escribe entidades de
otros módulos (`Services`, `Documents`, `Mailbox`, `Ai`) para sembrar un tenant de
demostración. Se justifica porque los casos de uso de esos módulos hablarían con un
servidor IMAP de verdad o cobrarían de verdad, y una demostración no puede depender de eso.

Reglas que lo acotan:

- Solo escribe; nunca borra nada del producto.
- Solo en desarrollo (`app:demo:seed` exige `--force` fuera de `dev`).
- Es idempotente: repetir la siembra no duplica servicios, avisos ni consumo.

### 16.7 Copias de seguridad y operación

`bin/backup.sh` y `bin/restore.sh` cubren el ciclo completo: volcado de la base de datos
(`pg_dump`, formato custom comprimido), copia de los documentos (`var/storage`), manifiesto
JSON con sumas SHA-256, revisión de código y poda por número de copias; la restauración
verifica la suma, puede tirar la base de datos entera (`--limpio`) y termina comprobando que
las migraciones están al día.

El runbook completo (servicios, despliegue, copias, restauración con simulacro, vigilancia,
incidentes, rotación de secretos y listas de comprobación) está en `docs/operations.md`.

## 17. La API pública (Fase 18)

### 17.1 Qué es y qué no es

La API es el **contrato de integración** de Suscripta: la forma en que otro programa —un ERP,
un panel de control, un bot, un script propio— consume los datos y las acciones del producto
sin pasar por la interfaz web. Vive bajo `/api/v1` y es **JSON puro**, sin HTML.

Lo que **no** es:

- **No es una segunda interfaz del backoffice.** El backoffice es una aplicación con sesión:
  un navegador, formularios, tokens CSRF, redirecciones. La API es sin sesión, sin cookies y
  sin formularios. Son dos worlds distintos, con dos firewalls distintos, y no se mezclan.
- **No expone las entidades de Doctrine.** Habla con **interfaces de read model** (§17.6), de
  modo que el contrato puede devolver exactamente los campos que promete y nada más.
- **No sustituye al producto.** Sigue siendo un SaaS multi-tenant con panel: la API es la
  puerta de entrada para integraciones, no la única forma de usar Suscripta.

### 17.2 Capas del módulo `Api`

`src/Api/` sigue la misma estructura modular que el resto del proyecto:

```text
src/Api/
├── Domain/          ApiKey (entidad), ApiScope (enumeración)
├── Application/     ApiKeyFactory, IssueApiKey, RevokeApiKey, ListApiKeys,
│                    ApiCollection (envoltorio de respuesta)
├── Infrastructure/  ApiKeyAuthenticator, ApiScopeVoter, ApiKeyRepository
└── UI/              Controller/V1/* (8 controladores), EventListener/*,
                     Http/Problem (RFC 9457), Controller/ApiKeyController (panel)
```

**La frontera del módulo.** `src/Api/` **no** contiene lógica de negocio de otros módulos. Las
operaciones de escritura —alta de servicio, confirmación de propuesta, carga de documento,
sincronización de buzón— **delegan en los casos de uso de cada módulo** (`Services`,
`Discovery`, `Documents`, `Mailbox`), igual que hace el backoffice. La API es una **nueva
entrada**, no una copia de las reglas. En particular, las cuotas del plan se comprueban en el
caso de uso (`QuotaGuard::assertWithinQuota()`, D-64), no en el controlador: por eso un
`POST /api/v1/services` con el plan gratuito devuelve `402`, igual que lo haría el panel.

### 17.3 La credencial

La tabla `api_key` guarda la huella HMAC-SHA-256 de la clave, sus ámbitos, su caducidad, su
revocación y su última utilización. El detalle completo (columnas, índices, generación,
razones de diseño) está en `docs/ROADMAP.md` §Fase 18 y en `docs/api/`.

Lo esencial para la arquitectura:

```text
Petición  →  ApiKeyAuthenticator  →  busca por secret_hash (índice único)
           →  valida caducidad y revocación
           →  fija la organización en el TenantContext
           →  ApiScopeVoter decide según #[IsGranted('SCOPE_READ'|'SCOPE_WRITE')]
```

El **`TenantContext` es el mismo** que usa el backoffice. El aislamiento entre organizaciones no
está implementado dos veces: está implementado una vez, y la API lo hereda. Si un día el
`TenantContext` se sustituye por otro mecanismo, la API lo hereda sin tocar una línea.

**Por qué HMAC y no bcrypt.** Una API key se presenta en cada petición y se busca en la base
de datos para autenticarla. `password_hash` es lento a propósito (contraseñas) y no es
determinista (no se puede indexar). El HMAC con pepper resuelve las dos cosas: búsqueda por
índice y coste constante, a cambio de que el pepper sea irrotable sin migrar (D-71).

### 17.4 El firewall

`config/packages/security.yaml` define un firewall `api` para `^/api/v1`, **stateless**, con el
`ApiKeyAuthenticator` y sin formulario de login ni CSRF. El backoffice conserva su firewall de
sesión. `/api/doc` y `/api/doc.json` quedan fuera de ambos, por `access_control`: el
integrador tiene que poder leer el contrato **antes** de tener una clave.

La credencial se acepta en `Authorization: Bearer …` y en `X-Api-Key: …`.

### 17.5 Ciclo de una petición y prioridades de eventos

Este es el punto donde la API se encuentra con el núcleo de Symfony, y donde está el defecto que
queda por corregir (Fase 18, defecto 1). Las prioridades en `KernelEvents::REQUEST`:

| Prioridad | Listener                           | Efecto                                                                         |
| --------- | ---------------------------------- | ------------------------------------------------------------------------------ |
| 100       | `LocaleListener::setDefaultLocale` | Locale por defecto de la petición.                                             |
| **32**    | `RouterListener::onKernelRequest`  | Resuelve la ruta; **responde 405 si el método no existe**.                     |
| 15        | `LocaleAwareListener`              | Aplica el locale de la ruta.                                                   |
| **9**     | `ApiCorsListener`                  | Pretende responder al preflight. **Por debajo del router → nunca se ejecuta.** |
| 8         | `Firewall::onKernelRequest`        | Autenticación.                                                                 |
| -8        | `ApiRateLimitListener`             | Límite de frecuencia.                                                          |

`RequestEvent::setResponse()` llama a `stopPropagation()`: un listener que fija la respuesta
corta el ciclo. Por eso un preflight CORS **debe** contestarse antes que el router (y por eso
el arreglo es subirlo a prioridad 40): para CORS, el `OPTIONS` no es «una petición a una ruta
que no admite ese método», es «una pregunta por CORS que hay que contestar sí o sí». Y como el
preflight no lleva credenciales, cortar el ciclo antes del firewall es exactamente lo correcto.

El orden de los listeners de la API es, por tanto, **load-bearing**: no es decorativo.

### 17.6 Read models

La API no consulta entidades Doctrine. Consulta interfaces:

| Interfaz                              | Implementación               | Módulo          |
| ------------------------------------- | ---------------------------- | --------------- |
| `ServiceViewRepositoryInterface`      | `ServiceViewRepository`      | `Services`      |
| `AlertViewRepositoryInterface`        | `AlertViewRepository`        | `Notifications` |
| `DocumentViewRepositoryInterface`     | `DocumentViewRepository`     | `Documents`     |
| `DiscoveryViewRepositoryInterface`    | `DiscoveryViewRepository`    | `Discovery`     |
| `EmailAccountViewRepositoryInterface` | `EmailAccountViewRepository` | `Mailbox`       |

El binding vive en `config/services.yaml` (`App\…\ViewRepositoryInterface: '@App\…\ViewRepository'`),
así que **cada módulo declara su propia implementación** y la API no depende de cómo se
guarden los datos. Dos beneficios: el contrato devuelve exactamente los campos que promete, y
un test puede sustituir la implementación por un doble sin base de datos.

Las **escrituras**, en cambio, van a los casos de uso de cada módulo, que sí usan las
entidades. Es intencionado: leer y escribir no tienen por qué compartir la misma fuente.

### 17.7 Formato de respuestas

Dos formas, y solo dos:

```jsonc
// colección
{ "data": [ … ], "meta": { "page": 1, "per_page": 25, "total": 3,
                           "total_pages": 1, "has_next": false, "has_previous": false } }

// recurso
{ "data": { … } }
```

`Pagination` (`src/Shared/Application/`) es una clase **compartida** con el backoffice, no una
clase de la API: paginar es una regla de presentación, y meterla en un módulo de infraestructura
sería un problema de capas. `per_page` por defecto 25, máximo 100; un valor mayor se recorta en
vez de rechazarse.

### 17.8 Errores

`ApiExceptionListener` traduce cualquier excepción a `application/problem+json` (RFC 9457):
`type`, `title`, `status`, `detail` y campos extra (`violations`, `retry_after`, y los del
`402`). Los constructores de `Problem` (`unauthorized()`, `forbidden()`, `notFound()`,
`unprocessable()`…) llevan los textos en español.

Un detalle que condiciona el diseño: en Symfony 8, `RequestPayloadValueResolver` **envuelve** la
`ValidationFailedException` en un `HttpException` (`HttpException::fromStatusCode(422, …,
new ValidationFailedException(…))`). Quien escuche excepciones tiene que **desempaquetar** de
`getPrevious()`, o se pierde la información de validación. Es el defecto 2 de la fase 18.

### 17.9 Límite de frecuencia

300 peticiones por minuto **por clave**, con `sliding_window` (`config/packages/rate_limiter.yaml`).
La unidad es la clave, no la IP ni la organización, porque la clave es la unidad que se puede
explicar al cliente y porque varias claves legítimas pueden salir del mismo servidor.

### 17.10 Documentación del contrato

`nelmio/api-doc` con `path_patterns: ^/api/v1`: solo la API versionada. `/api/doc.json` (JSON
OpenAPI 3) y `/api/doc` (Swagger UI). La versión del contrato vive en la URL (`/api/v1`) y en
el campo `version` de la definición OpenAPI.

## 18. Webhooks de salida (Fase 20)

El módulo `Webhooks` invierte la dirección de la API: en lugar de que un integrador pregunte,
Suscripta le avisa. No comparte nada con `Api` salvo el idioma del contrato (importes en unidades
menores, UUIDv7, RFC 3339) y no tiene credenciales propias: el secreto de cada suscriptor es su
único secreto.

### 18.1 Capas

| Capa                        | Contenido                                                                                                                                                               |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Domain/Entity`             | `Webhook` (suscriptor) y `WebhookDelivery` (una fila por suscriptor y evento).                                                                                          |
| `Domain/Service`            | `WebhookSigner` (HMAC-SHA-256) y `RetryPolicy` (ocho envíos y abandono).                                                                                                |
| `Domain/Repository`         | `WebhookRepositoryInterface`, `WebhookDeliveryRepositoryInterface`.                                                                                                     |
| `Application`               | `WebhookNotifier` (crea y encola), `DeliverWebhook` (un intento), `AbandonStaleDeliveries`, `WebhookSecretGenerator`, `WebhookUrlGuard` (anti-SSRF), mensaje y handler. |
| `Application/Port`          | `HostResolverInterface`: el DNS detrás de una interfaz, para que la guarda se pueda probar sin red.                                                                     |
| `Infrastructure/Http`       | `DnsHostResolver`: A con `gethostbynamel`, AAAA con `dns_get_record`.                                                                                                   |
| `Infrastructure/Repository` | Implementaciones Doctrine.                                                                                                                                              |
| `UI`                        | `WebhookController` (`/webhooks`), `WebhookFormType`, `MaintainWebhookDeliveriesCommand`.                                                                               |

### 18.2 El flujo de una entrega

```text
caso de uso (CreateService, ConfirmDiscovery, ProcessEmailMessage, AiExtractionService)
        │  notify(organizationId, event, data)   ← nunca lanza
        ▼
WebhookNotifier ──► 1 fila WebhookDelivery por suscriptor activo (cuerpo ya serializado)
        │              + DeliverWebhookMessage en el transporte `webhooks`
        ▼
worker: DeliverWebhookHandler ──► DeliverWebhook (un intento)
        │  runAs(organización) · firma · POST · 2xx o fallo
        ▼
   DELIVERED   |   PENDING con nextAttemptAt   |   ABANDONED
```

Cuatro propiedades sostienen el diseño:

1. **El cuerpo se serializa una vez y se guarda.** El reintento manda los mismos bytes, así que la
   firma cubre lo que el receptor recibió la primera vez y `Idempotency-Key` no cambia (D-73).
2. **La política de reintentos es única y vive en `RetryPolicy`.** El transporte de Messenger va
   con `max_retries: 0`: dos políticas a la vez producirían ráfagas que nadie ha diseñado.
3. **`notify()` no lanza.** Un webhook es una notificación, no una transacción: un suscriptor mal
   configurado no puede hacer fallar el alta de un servicio.
4. **El worker entra en la organización con `runAs()`.** El `TenantFilter` esconde las filas de
   otras organizaciones y sin él la entrega no se encontraría (mismo patrón que el resto de
   workers, §8).
5. **La conexión no la elige el formulario.** `WebhookUrlGuard::pin()` comprueba el esquema y
   **todas** las direcciones del host, y devuelve la que se usó para validar; `DeliverWebhook` se
   la pasa al cliente HTTP en `resolve` y va con `max_redirects: 0`. Sin eso, el campo «URL» sería
   un cliente HTTP arbitrario dentro de la red del servidor (D-77, §18.5).

### 18.3 Por qué se llama a mano y no hay bus de eventos

Suscripta no tiene bus de eventos de dominio. Introducirlo para cuatro eventos obligaba a
reescribir los casos de uso existentes; en su lugar, cada caso de uso llama a `notify()` al final,
con los datos que ya tiene en la mano. Es explícito y se ve en el diff. El precio es que hay que
acordarse, y por eso cada llamada tiene su test. El parámetro es opcional
(`?WebhookNotifier $webhooks = null`) para no romper la construcción manual en los tests.

### 18.4 Entrega «al menos una vez»

No existe «exactamente una vez» sin coordinación con el receptor: un `200` perdido en la red es
indistinguible de un fallo, así que el mismo evento puede llegar dos veces. La mitigación es
`Idempotency-Key` (el identificador de la entrega, estable en todos los reintentos) y su
documentación en `docs/api/webhooks.md`, con la recomendación de deduplicar con una ventana de al
menos 48 h.

### 18.5 Lo que no hace

- **No valida que la URL sea alcanzable.** Sí comprueba que no apunte a una red interna, en el
  alta y en cada entrega, con la IP fijada (D-77). Un host que aún no existe se acepta: dar de
  alta el receptor antes de desplegarlo es lo normal.
- **No hay webhooks de entrada.** Stripe (fase 24) tendrá su propia ruta, con su propia firma.
- **No hay challenge de verificación de endpoint**: el botón «Enviar prueba» del panel cumple esa
  función.

## 19. Web corporativa (Fase 23)

La web que vende el producto vive **en la misma aplicación** que el panel. No es un segundo
proyecto ni un generador de sitios estáticos: es un módulo más, `src/Website/`, con sus cuatro
capas, y comparte contenedor, despliegue y catálogo con el resto.

### 19.1 Por qué en la misma aplicación

Dos despliegues separados habrían duplicado el diseño, el dominio y los datos: la página de
precios necesita leer `PlanPrice` en cada petición, y el alta necesita la sesión que abre el
panel. Compartir proceso convierte «pulsa contratar» en un enlace interno en vez de un salto entre
sistemas con su propia copia del catálogo (D-83).

El precio de esa decisión es que la portada y el panel conviven bajo el mismo `access_control`, y
por eso la fase dedica un test a recorrer **todas** las rutas públicas sin sesión (D-84).

### 19.2 Capas

| Capa                     | Contenido                                                                                                                                                                                                     |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Application`            | `PlanCard` (DTO de una tarjeta de precio), `BuildPricingPage` (catálogo + límites → tarjetas), `Faq` (seis preguntas, fuente única de la página y del JSON-LD), `SendContactMessage` (correo con `Reply-To`). |
| `UI/Form`                | `ContactMessageData` (DTO validado) y `ContactFormType`.                                                                                                                                                      |
| `UI/Controller`          | `HomepageController` (`/`), `PricingController` (`/precios`), `SiteController` (siete páginas), `ContactController` (`/contacto`, GET y POST) y `SeoController` (`/sitemap.xml`, `/robots.txt`).              |
| `templates/website`      | `base`, `_header`, `_footer`, `_plan_card` y una plantilla por página, más `seo/sitemap.xml.twig`.                                                                                                            |
| `assets/styles/site.css` | Hoja de estilos propia, escrita a mano, sin SCSS ni build (D-85).                                                                                                                                             |

### 19.3 La portada bifurca, no adivina

`GET /` responde **redirección** al panel si hay sesión y portada si no. Se eligió redirección y
no renderizar el panel en `/` para que cada pantalla tenga una sola URL: `/dashboard` es el panel
para todo el mundo, los enlaces `path('app_dashboard')` siguen siendo válidos y el botón «atrás»
no devuelve a una portada que ya no toca.

### 19.4 Los precios no se escriben en la plantilla

`BuildPricingPage` lee `PlanRepositoryInterface::findAll(onlyActive: true)`, los precios vigentes
de cada plan y sus características. **Un cambio de precio en el backoffice aparece en la web sin
desplegar**, que es exactamente lo que la fase 22 vino a permitir.

Los **límites** se leen de `entitlements.yaml` a través de `EntitlementSettings` (D-64), no de la
base de datos: si la web prometiera unos límites distintos de los que el guard de cuotas aplica,
la primera queja de un cliente tendría razón. `ai_budget_minor` se oculta porque es un tope
interno de coste, no algo que se venda.

La rebaja anual (`annualSavingPercent`) la calcula el servidor comparando el precio mensual por
doce con el anual. Si no hay con qué compararla, llega como `null` y la plantilla **no inventa
nada**: el hueco queda vacío.

### 19.5 Sin JavaScript

La web no carga Bootstrap, ni importmap, ni una sola línea de JavaScript. El menú móvil y las
preguntas frecuentes usan `<details>`, que es HTML. Las consecuencias:

- La CSP no necesita `unsafe-inline` en `script-src`; los únicos `<script>` de la página son los
  bloques `application/ld+json`, que llevan el `nonce` de la respuesta.
- No hay estado de cliente que pueda desincronizarse con el servidor.
- La página funciona con el JS desactivado y en un lector de pantalla sin configuración extra.

El formulario de contacto se renderiza con un tema propio, `templates/form/site_theme.html.twig`,
porque el tema global del proyecto es el de Bootstrap y arrastraría sus clases.

### 19.6 SEO: lo que se afirma y lo que no

Cada página define `title`, `description` y `canonical` como bloques de Twig, y el layout los
reutiliza para Open Graph y Twitter. El `canonical` se construye con `%app.site.url%` y no con el
host de la petición: detrás de un proxy, el host que ve la aplicación es el interno, y un sitemap
con `http://localhost/…` no lo indexa nadie.

`sitemap.xml` y `robots.txt` se generan desde la tabla de rutas (`SeoController::PAGES`), no se
escriben a mano: un sitemap escrito a mano envejece a la primera página nueva, y un `robots.txt` a
mano se olvida de la ruta privada que se añadió ayer.

Hay JSON-LD de `Organization` en todas las páginas, `SoftwareApplication` en la portada y
`FAQPage` en las preguntas frecuentes, generado desde el mismo objeto `Faq` que pinta el HTML: si
las dos listas se separaran, Google acabaría indexando preguntas que ya no están en la página.

**Lo que no se afirma.** La web no dice «100 % privado» ni «datos alojados en la UE», porque el
proyecto no puede sostenerlo (D-25): la propia página de seguridad explica qué se hace y qué no se
puede prometer. Tampoco hay `lastmod` en el sitemap, porque estas páginas cambian con un
despliegue y no con una fecha que se pueda afirmar sin mentir.

### 19.7 El formulario de contacto

`POST /contacto` valida con el componente Validator, limita por IP con `limiter.contact_form`
(cinco mensajes por hora) y manda un `TemplatedEmail` al buzón de `SITE_CONTACT_EMAIL` con
`Reply-To` de quien escribe. **No se guarda nada en la base de datos**: el buzón es el archivo, y
así no hay datos personales que retener, exportar ni borrar.

Dos detalles que costaron un error cada uno:

1. **`email` es una variable reservada** en `TemplatedEmail`: la ocupa el propio mensaje. El
   contexto de la plantilla usa `sender`, y con `email` el envío falla en tiempo de ejecución, no
   al compilar.
2. **El limitador vive en caché y la caché sobrevive a la suite.** Los tests fijan una IP distinta
   por prueba (`REMOTE_ADDR` aleatorio en `198.51.100.0/24`) en lugar de bajar el límite en el
   entorno de test: así el test del límite real —cinco pasan, el sexto recibe el aviso— convive
   con los demás sin tocar la configuración.

### 19.8 Lo que queda pendiente antes de publicar

Las tres páginas legales llevan **marcadores** (`[RAZÓN SOCIAL]`, `[NIF]`, `[DOMICILIO]`,
`[REGISTRO Y TOMO]`) y un aviso rojo que solo se pinta fuera de `prod`. Sustituirlos es un
requisito de lanzamiento, no una tarea de estilo: publicar con marcadores es publicar un aviso
legal falso.

## 20. Cobros con Stripe (Fase 24)

El módulo `src/Billing/` convierte el catálogo de la fase 22 en dinero cobrado: abre un checkout
desde la web, aplica lo que la pasarela confirma y deja el recibo a la vista de la organización.

### 20.1 Capas

| Capa                        | Contenido                                                                                                                                                                            |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Domain/Port`               | `PaymentGatewayInterface` — el puerto que aísla a la aplicación de Stripe.                                                                                                           |
| `Domain/ValueObject`        | `CheckoutRequest`, `CheckoutSession`, `GatewayCheckoutSession`, `GatewayEvent`, `GatewayInvoice`, `GatewaySubscription`. Todos `readonly`.                                           |
| `Domain/Entity`             | `PaymentEvent` (idempotencia) y `SalesInvoice` (recibo del cobro).                                                                                                                   |
| `Domain/Exception`          | `GatewayNotConfiguredException` (503), `InvalidSignatureException` (400), `GatewayRequestException` (502 con el estado de la pasarela).                                              |
| `Application`               | `StartCheckout`, `CompleteCheckout`, `HandlePaymentEvent`, `ApplyGatewaySubscription`, `RecordSalesInvoice`, `ReconcileSubscriptions`.                                               |
| `Infrastructure/Stripe`     | `StripeGateway` (HTTP + form-encoding + parseo) y `StripeSignatureVerifier` (HMAC).                                                                                                  |
| `Infrastructure/Repository` | `PaymentEventRepository`, `SalesInvoiceRepository`.                                                                                                                                  |
| `UI/Controller`             | `CheckoutController` (`/checkout/{priceId}`), `BillingReturnController` (`/checkout/gracias`), `BillingController` (`/facturacion`), `StripeWebhookController` (`/webhooks/stripe`). |
| `UI/Command`                | `app:billing:reconcile`.                                                                                                                                                             |

### 20.2 Rutas

| Método | Ruta                  | Quién   | Qué hace                                                           |
| ------ | --------------------- | ------- | ------------------------------------------------------------------ |
| `GET`  | `/checkout/{priceId}` | sesión  | Abre la sesión de pago y redirige a Stripe.                        |
| `GET`  | `/checkout/gracias`   | sesión  | Consulta la sesión y aplica la suscripción sin esperar al webhook. |
| `GET`  | `/facturacion`        | sesión  | Plan actual y recibos de la organización.                          |
| `POST` | `/webhooks/stripe`    | pública | Recibe los eventos de Stripe, firmados.                            |

`/webhooks/stripe` está en `PUBLIC_ACCESS` porque quien llama es Stripe, no un usuario: la
autenticación es la firma HMAC, no una sesión.

### 20.3 El checkout no crea la suscripción

`StartCheckout` comprueba cinco cosas antes de mandar a nadie a pagar —pasarela configurada, precio
vigente, plan activo, importe mayor que cero y que la organización no tenga ya ese mismo plan
vivo— y devuelve la URL de la sesión alojada. **No crea `Subscription`.** La crea el pago, no la
intención de pagar: si se creara al abrir el checkout, cada cliente que abandona dejaría una
suscripción fantasma ocupando plaza del plan.

La organización y el plan viajan en los metadatos de la sesión (`organization_id`, `plan_id`,
`billing_period`). Es lo único que permite que un evento que llega suelto —sin sesión, sin
usuario— sepa a quién pertenece.

### 20.4 Idempotencia: reservar antes de aplicar

`HandlePaymentEvent` reserva el `event.id` en `payment_event` **antes** de aplicar nada:

1. `claim()` inserta la fila. Si choca con el índice único, el evento ya estaba y se responde
   «duplicado» sin tocar nada.
2. Se aplica el efecto.
3. Se marca `applied` o `ignored` con el motivo.

Si el paso 2 falla, la reserva se **suelta** y el error se propaga para responder 500: la pasarela
reintenta y, como la reserva ya no está, el reintento se procesa de verdad. Sin soltarla, un fallo
de red de dos segundos se convertiría en un evento perdido para siempre.

Dos detalles de implementación que costaron un bug cada uno y están fijados por tests:

- **El `INSERT` va con `Types::BOOLEAN` explícito.** DBAL enlaza por defecto como cadena y
  PostgreSQL rechaza `''` en una columna booleana (`SQLSTATE[22P02]`).
- **El `INSERT` va envuelto en su propia transacción.** Un choque contra el índice único deja la
  transacción de PostgreSQL en aborto y cualquier consulta posterior falla con `25P02`. Al capturar
  `UniqueConstraintViolationException` se hace `rollBack()` y se sigue. En DBAL 4 el anidamiento
  usa savepoints.

`PaymentEventOutcome` tiene tres valores y **ninguno es un error**: `applied`, `duplicate` e
`ignored`. Los tres responden 200. La diferencia está en el log y en la tabla, que es donde se mira
cuando un cobro «no se ha reflejado».

### 20.5 Qué eventos se aplican y qué se ignora

| Evento                                                    | Efecto                                            |
| --------------------------------------------------------- | ------------------------------------------------- |
| `checkout.session.completed`                              | Crea o sincroniza la suscripción.                 |
| `customer.subscription.created` / `.updated` / `.deleted` | Sincroniza estado, periodo y cancelación.         |
| `invoice.paid`                                            | Registra el recibo y resincroniza la suscripción. |
| `invoice.payment_failed`                                  | Marca el recibo como fallido.                     |
| Cualquier otro                                            | Se marca `ignored` y se responde 200.             |

Un evento que no está en `GatewayEvent::HANDLED_TYPES` se apunta y se olvida. Devolver un error
haría que Stripe lo reintentara durante días por un tipo que no vamos a entender nunca.

### 20.6 El periodo lo dicta la pasarela

`ApplyGatewaySubscription` copia `currentPeriodStart`/`currentPeriodEnd` tal y como los manda
Stripe y no los calcula a partir del estado local. Si se calcularan aquí, un prorrateo o un
aplazamiento hecho en el panel de Stripe dejaría las dos versiones discrepando, y la que manda es
la del cobro.

Crear y actualizar son el mismo camino: se busca por `externalReference` y, si existe, se llama a
`Subscription::syncWithGateway()`; si no, se crea con el periodo de la pasarela.

### 20.7 La vuelta del pago no espera al webhook

`CompleteCheckout` pregunta a la pasarela por la sesión al volver del checkout. Si está `complete`,
aplica la suscripción en el acto, porque el cliente está mirando la pantalla y tiene que ver su
plan activo ya. El webhook llegará después e intentará lo mismo con los mismos datos: es
idempotente, así que da igual quién llegue primero.

Si la sesión no lleva el identificador de la organización activa **no se aplica nada**: es una
comprobación de propiedad, no de existencia, para que pegar el `session_id` de otra cuenta en la
URL no active un plan ajeno.

Cuando no se puede confirmar, la pantalla dice que el pago se está confirmando en lugar de afirmar
que se ha cobrado. Enseñar «todo listo» sobre un pago que no consta es la peor versión de este
error.

### 20.8 Conciliación por cron

`app:billing:reconcile` recorre las suscripciones con referencia externa, pregunta a la pasarela
por cada una y aplica lo que conteste, con **el mismo código que el webhook**. Un webhook se pierde
—servidor reiniciando, puerto cerrado, firma rotada sin actualizar—, Stripe reintenta tres días y
después se rinde; a partir de ahí la suscripción local se queda con el estado de hace una semana y
el cliente paga sin que su plan lo refleje. Esta pasada lo corrige.

**No cancela lo que no encuentra.** Una suscripción que la pasarela ya no conoce se deja como está
y se apunta en el log: puede ser un borrado a mano en Stripe o un fallo puntual de la consulta, y
cancelar por eso dejaría a un cliente de baja sin motivo.

### 20.9 Factura de venta ≠ factura descubierta

`SalesInvoice` es el recibo de **lo que nos cobran a nosotros** por usar Suscripta. `Documents\Invoice`
es la factura que **el cliente recibe de sus proveedores** y que el producto descubre en el correo.
Son dos entidades distintas, en dos módulos distintos, y no se mezclan (D-86).

### 20.10 Sin SDK de Stripe

La integración son dos ficheros: `StripeGateway` (peticiones `application/x-www-form-urlencoded` y
parseo de la respuesta) y `StripeSignatureVerifier` (HMAC-SHA256 con `t` + `.` + cuerpo y ventana de
tolerancia). No se instaló `stripe/stripe-php`:

- El SDK son ~1 MB de clases para cinco llamadas.
- Ata la versión de la API a la del SDK, y aquí interesa fijarla a mano.
- La verificación de la firma es la única defensa de un endpoint público y conviene tenerla escrita
  donde se pueda leer y probar, no dentro de una dependencia.

La versión de la API se fija explícitamente en la cabecera `Stripe-Version` (`2024-06-20`): sin
fijarla, Stripe aplica la de la cuenta y un cambio de su lado puede cambiar la forma de la
respuesta sin que nadie haya desplegado nada.

### 20.11 Cumplimiento

El cobro lo hace Stripe en su dominio. Suscripta no ve, no recibe y no almacena datos de tarjeta:
solo guarda el identificador de la suscripción y el del recibo, más las URLs alojadas por Stripe.
No hay nada que PCI-DSS tenga que auditar en este servidor.

## 21. CSRF: un solo mecanismo (Fase 25)

### 21.1 Qué había

Symfony 8 trae CSRF **sin estado** por defecto y el proyecto arrastraba además el gestor de sesión
en los formularios que lo necesitaban de verdad. Dos mecanismos convivían: cada `POST` nuevo tenía
que elegir, y elegir mal no rompe nada visible hasta que un usuario pierde un formulario.

### 21.2 Qué hay

`config/packages/csrf.yaml` deja el gestor de sesión como único:

```yaml
framework:
  form:
    csrf_protection:
      token_id: submit

  csrf_protection: ~
```

Sin `stateless_token_ids` no se registran `security.csrf.same_origin_token_manager` ni su listener,
y `csrf_token()`, el token de formulario (`submit`), el de login, el de logout y todos los
`isCsrfTokenValid()` de los controladores pasan por `security.csrf.token_manager`, que guarda el
valor en la sesión bajo `_csrf/<propósito>`.

Se eliminan `config/packages/ux_turbo.yaml` y `assets/controllers/csrf_protection_controller.js`:
los dos existían solo para la receta sin estado (cabecera `X-CSRF-Token` en peticiones Turbo y
doble-submit cookie).

### 21.3 Cómo se comprueba

`tests/Functional/Identity/CsrfUnificationTest.php` fija las cuatro garantías que importan:

| Garantía                                       | Cómo                                                                    |
| ---------------------------------------------- | ----------------------------------------------------------------------- |
| El token vive en la sesión                     | La clave `_csrf/resend_verification` existe tras renderizar             |
| No hay token sin estado                        | No se emite la cookie `csrf-token`                                      |
| Un formulario abierto hace rato sigue valiendo | Token leído, una petición de por medio, `POST` → 302                    |
| Los tokens son por propósito                   | El token de logout en el endpoint de reenvío → 403; uno inventado → 403 |

**El token no se compara entre páginas.** `CsrfTokenManager` enmascara el valor con una clave
aleatoria en cada render (`clave.valor`), de modo que dos renders seguidos dan cadenas distintas
aunque el token sea el mismo. Comparar HTML no demuestra nada; mirar la sesión, sí.
