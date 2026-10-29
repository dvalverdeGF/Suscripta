# Decisiones arquitectónicas (ADR)

Registro de decisiones relevantes, con su contexto, alternativas y consecuencias. Formato
ligero inspirado en ADR. Cada decisión tiene un identificador estable (`D-NN`).

---

## D-01 — PHP 8.5 y Symfony 8.x

**Estado:** aceptada.

**Contexto.** El encargo pedía PHP 8.4 y Symfony 8, pero "las versiones estables actuales
disponibles en el proyecto". El repositorio ya fija `dunglas/frankenphp:1-php8.5` como imagen
base.

**Decisión.** Usar **PHP 8.5** (el que trae la imagen) y **Symfony 8.x** (última estable).

**Alternativas.** Bajar la imagen a PHP 8.4 para cumplir literalmente el encargo.

**Consecuencias.** Se aprovechan las mejoras de 8.5 sin coste. Hay que verificar la
compatibilidad de cada dependencia de terceros con PHP 8.5 antes de adoptarla. Si alguna
librería crítica no soporta 8.5, se revisará esta decisión.

---

## D-02 — `Organization` existe desde la v1 (1:1 con el usuario)

**Estado:** aceptada, revisable.

**Contexto.** El producto es SaaS y debe aislar tenants desde el principio. El encargo pide
analizar si `Organization` es necesaria en la primera versión o si basta con `User`.

**Decisión.** Introducir `Organization` y `Membership` desde la v1. Al registrar un usuario se
crea automáticamente una organización personal con una `Membership` de rol `owner`. No hay UI
de invitaciones ni de roles en v1.

**Alternativas.**

- _Solo `User` como tenant._ Más simple hoy, pero añadir `Organization` después obliga a
  migrar la clave de aislamiento de **todas** las tablas y a reescribir el filtro de tenancy.
- _`Organization` con equipos completos desde el inicio._ Complejidad empresarial innecesaria.

**Consecuencias.** Coste actual: una tabla y una fila extra por usuario. Beneficio: el límite
de tenant es estable desde el primer commit y el producto puede crecer a equipos sin migración
dolorosa. El usuario objetivo incluye "pequeñas empresas", así que el multi-usuario es un
escenario real, no hipotético.

---

## D-03 — `Renewal` no es una entidad en v1

**Estado:** aceptada.

**Contexto.** El encargo lista `Renewal` como concepto candidato.

**Decisión.** Modelar la renovación como **propiedades de `Service`** (`renewalAt`,
`noticePeriodDays`, `autoRenews`, `commitmentEndAt`) más un `ServiceEvent` cuando ocurre. No
se crea entidad `Renewal`.

**Alternativas.** Entidad `Renewal` con historial de renovaciones pasadas.

**Consecuencias.** Menos tablas y menos joins. Se pierde el historial detallado de renovaciones
pasadas, que hoy no aporta valor al usuario. Si en el futuro se necesita (p. ej. contratos con
renegociación), se añade sin romper el modelo.

---

## D-04 — `Payment` no es una entidad en v1

**Estado:** aceptada, revisable.

**Contexto.** El encargo lista `Payment` como concepto candidato. Un cobro observado en el
correo es, en la práctica, una factura pagada.

**Decisión.** Representar un cobro como `Invoice` con `paidAt` y `status = paid`. No se crea
entidad `Payment`.

**Alternativas.** Entidad `Payment` separada desde el inicio.

**Consecuencias.** Se evita duplicar el mismo hecho en dos tablas. Se introduce `Payment`
cuando exista una necesidad real: pagos parciales, varios métodos de pago por factura o
conciliación bancaria. El "historial de pagos" del producto es el historial de facturas.

---

## D-05 — Cliente IMAP en PHP puro

**Estado:** aceptada.

**Contexto.** `ext-imap` dejó de formar parte del núcleo de PHP en 8.4 y pasa a PECL. La
imagen base no la incluye.

**Decisión.** Usar un cliente IMAP en **PHP puro** (p. ej. `webklex/php-imap`) detrás de
`MailboxProviderInterface`, en lugar de depender de `ext-imap`.

**Alternativas.** Instalar `ext-imap` desde PECL en la imagen.

**Consecuencias.** Menos dependencia de extensiones nativas y más portabilidad. A cambio, hay
que verificar el rendimiento y la compatibilidad del cliente elegido con buzones grandes y con
las particularidades de cada proveedor. La interfaz permite cambiar de cliente sin tocar el
dominio.

---

## D-06 — Monolito modular, no microservicios

**Estado:** aceptada.

**Contexto.** El producto es un Micro-SaaS con un equipo pequeño.

**Decisión.** Una sola aplicación Symfony, dividida en módulos de negocio con límites
explícitos (`Identity`, `Catalog`, `Services`, `Documents`, `Mailbox`, `Discovery`,
`Notifications`, `Dashboard`, `Shared`). Comunicación entre módulos a través de la capa de
aplicación, no accediendo a entidades ajenas.

**Alternativas.** Microservicios; monolito sin estructura.

**Consecuencias.** Despliegue y operación simples. Los límites permiten extraer un módulo a un
servicio independiente si algún día hace falta (el candidato natural sería `Mailbox` por su
carga). Sin la estructura modular, el monolito se convertiría en un _big ball of mud_.

---

## D-07 — UUIDv7 como identificador

**Estado:** aceptada.

**Contexto.** El encargo lo pide explícitamente.

**Decisión.** UUIDv7 generado en la aplicación (`symfony/uid`), mapeado con un tipo Doctrine
propio.

**Alternativas.** Auto-incrementales (más rápidos, pero enumerables y problemáticos en
sistemas distribuidos); UUIDv4 (aleatorios, peor localidad de índice).

**Consecuencias.** Identificadores no enumerables y ordenables temporalmente, con buena
localidad de índice. Ocupan más que un entero, lo cual es irrelevante a esta escala. El
aislamiento entre tenants **no** depende de la opacidad del identificador.

---

## D-08 — La IA es opcional y requiere consentimiento explícito

**Estado:** aceptada.

**Contexto.** El producto accede a facturas y datos financieros. Enviarlos a un proveedor
externo de IA es una decisión con implicaciones legales y de privacidad.

**Decisión.** v1 funciona **sin proveedor externo de IA**, con reglas deterministas. La IA se
activa por configuración y requiere consentimiento a nivel de organización, DPA firmado y
registro en la política de privacidad. Todo pasa por `AiProviderInterface`.

La IA es **la última capa** de un pipeline de extracción por capas (determinista → reglas →
OCR → IA) que se detiene en cuanto alcanza el umbral de confianza. Ver D-24.

**Alternativas.** Usar IA desde el primer día para maximizar la precisión de extracción.

**Consecuencias.** El producto es funcional y privado por defecto. La precisión de extracción
será menor al principio, lo que se compensa con revisión humana (que ya es obligatoria). Se
evita enviar datos financieros a terceros sin control. El proveedor es intercambiable, lo que
permite migrar a un modelo autoalojado o europeo sin tocar el dominio.

---

## D-09 — Dinero como entero en unidades menores

**Estado:** aceptada.

**Contexto.** Los importes son el dato central del producto.

**Decisión.** Almacenar `amountMinor` (entero, céntimos) + código ISO 4217. Value Object
`Money` en el dominio. Nunca `float`.

**Alternativas.** `DECIMAL` en base de datos; `float`.

**Consecuencias.** Sin errores de redondeo en sumas y comparaciones. La conversión de moneda
queda fuera de v1: se almacena la moneda y el dashboard agrupa por moneda.

---

## D-10 — No se almacena el cuerpo completo de los correos

**Estado:** aceptada.

**Contexto.** El encargo pide minimización y no almacenar mensajes completos si no es
necesario.

**Decisión.** `EmailMessage` guarda metadatos, un hash del cuerpo y, como máximo, un extracto
corto (`bodyExcerpt`) mientras exista un descubrimiento pendiente. El cuerpo se procesa en
memoria y se descarta.

**Alternativas.** Guardar el cuerpo completo para reprocesar sin volver a conectar al IMAP.

**Consecuencias.** Menor superficie de datos sensibles y menor coste de almacenamiento. Si hay
que reprocesar, se vuelve a leer del buzón (el cursor permite acotar). El extracto se borra al
resolver el descubrimiento.

---

## D-11 — Aislamiento por `organization_id` + Doctrine Filter

**Estado:** aceptada.

**Contexto.** El encargo prohíbe schema por tenant y exige aislamiento desde la capa de
aplicación.

**Decisión.** Base de datos y esquema únicos, columna `organization_id` en toda entidad de
negocio, `TenantContext` + Doctrine Filter automático, más tests de aislamiento en CI.

**Alternativas.** Schema por tenant; base de datos por tenant; Row Level Security como
mecanismo principal.

**Consecuencias.** Operación simple (una migración, un backup). El riesgo de fuga entre
tenants se mitiga con defensa en profundidad y tests que fallan si el filtro se desactiva. RLS
puede añadirse después como capa extra sin cambiar el modelo.

---

## D-12 — Pipeline asíncrono con Messenger y transporte `failed`

**Estado:** aceptada.

**Contexto.** El análisis de correo no debe bloquear la interfaz y debe ser reintentable,
idempotente y observable.

**Decisión.** Pipeline de mensajes encadenados (`SyncEmailAccount` → `ProcessEmailMessage` →
`Classify` → `ExtractAttachment` → `ExtractInvoiceData` → `MatchService` →
`DetectRecurrence` → `CreateDiscovery` → `SendNotification`), con transports separados
(`mail_sync`, `mail_processing`, `ai`, `notifications`, `failed`), deduplicación por clave
natural y handlers idempotentes.

**Alternativas.** Procesar de forma síncrona al conectar la cuenta; un único job monolítico.

**Consecuencias.** Progreso visible, reintentos por etapa, aislamiento de fallos y control de
concurrencia por tipo de trabajo. A cambio, más piezas que operar (workers) y necesidad de
propagar el tenant en el envelope.

---

## D-13 — El precio vigente se deriva, no se denormaliza

**Estado:** aceptada, revisable.

**Contexto.** El dashboard necesita el precio actual de cada servicio con frecuencia.

**Decisión.** El precio vigente es la fila de `ServicePrice` con `validTo IS NULL`. No se
duplica en `Service`.

**Alternativas.** Columna denormalizada `Service.currentAmountMinor` mantenida por el dominio.

**Consecuencias.** Una única fuente de verdad, imposible de desincronizar. A la escala
prevista (decenas de servicios por organización) el join es irrelevante. Si el dashboard
mostrara problemas de rendimiento medidos, se añadirá denormalización con un test que garantice
la coherencia.

---

## D-14 — `Discovery.proposedData` como JSON

**Estado:** aceptada.

**Contexto.** Un descubrimiento es una **propuesta en revisión**, no una entidad de negocio
consolidada. Su forma evolucionará con el extractor.

**Decisión.** Guardar la propuesta normalizada en una columna JSON, con los campos esperados
documentados y validados en la capa de aplicación.

**Alternativas.** Entidad `DiscoveryProposal` con columnas tipadas.

**Consecuencias.** El extractor puede evolucionar sin migraciones constantes. Se pierde
tipado fuerte en base de datos; se compensa validando al leer y escribiendo tests sobre la
forma del JSON. Al confirmar un descubrimiento, los datos se copian a entidades tipadas
(`Service`, `ServicePrice`).

---

## D-15 — Límites de plan fuera del dominio

**Estado:** aceptada.

**Contexto.** El encargo pide no implementar planes ni pagos, pero diseñar para que existan.

**Decisión.** `EntitlementCheckerInterface` en la capa de aplicación, con implementación
`UnlimitedEntitlements` en v1.

**Alternativas.** Campos de plan y límites en `Organization` desde el inicio.

**Consecuencias.** El dominio no se contamina con lógica comercial. Cuando existan planes, se
sustituye la implementación sin tocar entidades ni casos de uso.

---

## D-16 — Sin facturación del SaaS en v1

**Estado:** aceptada.

**Contexto.** El encargo lo pide explícitamente.

**Decisión.** No se implementan pagos, planes ni facturación del propio SaaS. Solo la
abstracción de límites (D-15).

**Consecuencias.** Se evita construir dos productos a la vez. El modelo de negocio se validará
con usuarios reales antes de invertir en la infraestructura de cobro.

---

## D-17 — Interfaz web con Twig renderizado en servidor

**Estado:** aceptada.

**Contexto.** El encargo pide Twig para la primera interfaz.

**Decisión.** Twig con renderizado en servidor. La capa de interacción se construye con
**Turbo** (navegación y formularios sin recarga completa) y **Stimulus** (controladores
pequeños y locales), con **AssetMapper** para los assets (sin Node ni `npm`) y **Mercure** para
el progreso de las operaciones asíncronas. **Sin SPA y sin API pública en v1.** Detalle en
`ARCHITECTURE.md` §12.

**Alternativas.**

1. SPA con API REST/GraphQL. Más código, más superficie de ataque, dos despliegues y un
   toolchain Node en la imagen Docker, a cambio de una reactividad que este producto no
   necesita.
2. Twig puro sin Turbo ni Stimulus. Viable, pero obliga a recargar la página en cada acción y
   complica mostrar el progreso de la sincronización.

**Consecuencias.** Menos código, menos superficie de ataque, más rápido de construir y de
mantener. Las operaciones lentas ya son asíncronas, así que la UI no necesita ser reactiva para
funcionar. Todo flujo crítico funciona **sin JavaScript** (mejora progresiva). Si más adelante
se necesita una app móvil, se añadirá una API sobre los mismos casos de uso, que ya están
separados de los controladores.

---

## D-18 — PostgreSQL 16+ (subir desde el 15 por defecto)

**Estado:** aceptada.

**Contexto.** `compose.yaml` fija `POSTGRES_VERSION=15` por defecto.

**Decisión.** Fijar PostgreSQL 16 (o superior) en el entorno de desarrollo y producción.

**Alternativas.** Mantener 15.

**Consecuencias.** Se dispone de mejoras de rendimiento y de `MERGE`, además de un ciclo de
soporte más largo. Requiere actualizar la variable en `compose.yaml` y en el entorno de
despliegue; es un cambio de configuración, no de código.

---

## D-19 — Documentos: `Document` e `Invoice` separados

**Estado:** aceptada.

**Contexto.** El encargo pide poder asociar documentos a servicios y a facturas, y conservar
fecha, nombre, tipo, tamaño, origen, servicio y factura.

**Decisión.** Dos entidades: `Document` (el fichero y sus metadatos) e `Invoice` (el hecho
económico). Un `Document` puede existir sin `Invoice` (un contrato, un recibo sin datos
fiscales) y una `Invoice` puede existir sin `Document` (creada a mano).

**Alternativas.** Una sola entidad `Document` con campos de factura embebidos.

**Consecuencias.** Modelo correcto para los casos reales (un contrato no es una factura) y
permite que el historial de precios se apoye en facturas aunque no se conserve el fichero. A
cambio, una tabla más y una relación que hay que mantener coherente.

---

## D-20 — Los adjuntos conservados se convierten en `Document`

**Estado:** aceptada.

**Contexto.** El encargo menciona adjuntos y documentos.

**Decisión.** No existe entidad `EmailAttachment`. Un adjunto que decidimos conservar se
convierte en `Document` con `source = email_attachment` y `emailMessageId` de origen. Los
adjuntos descartados no se persisten.

**Alternativas.** Entidad `EmailAttachment` intermedia.

**Consecuencias.** Un único concepto de documento en todo el sistema, con un origen trazable.
Se pierde el registro de los adjuntos descartados, que no aporta valor al usuario.

---

## D-21 — Reenvío de correo como alternativa al acceso al buzón

**Estado:** aceptada.

**Contexto.** La investigación de mercado (`COMPETITORS.md` §11) muestra que el acceso de
lectura a Gmail es un **restricted scope** de Google: exige verificación de la app y, si se
procesa en servidor, una **evaluación anual de seguridad CASA** por un tercero aprobado. Es una
barrera de entrada real (semanas de proceso, coste y auditoría recurrente). Varios competidores
pequeños la evitan ofreciendo **reenvío de correo** a una dirección dedicada en lugar de acceso
al buzón (Spendbox, LemSubs, SpendKeeper, Subscription Freedom). Además, el RGPD y la Directiva
ePrivacy art. 5 exigen consentimiento explícito e informado para leer el buzón.

**Decisión.** Soportar **dos vías de ingesta** desde el diseño, detrás de la misma abstracción:

1. **Acceso al buzón** (IMAP genérico en v1; OAuth de Gmail/Microsoft después), solo lectura.
2. **Reenvío de correo** a una dirección única por organización/cuenta, que no requiere acceso
   al buzón ni OAuth.

Ambas producen el mismo tipo de entrada en el pipeline (`EmailMessage` con su origen
registrado), de modo que el resto del sistema no distingue la vía.

**Alternativas.** Solo acceso al buzón (más simple, pero excluye a usuarios que no quieren dar
acceso a su correo y obliga a asumir la carga de CASA para Gmail).

**Consecuencias.** Más superficie que construir (una dirección de ingesta y su verificación de
remitente), pero elimina la dependencia de la verificación de Google para el caso de uso
principal, reduce el riesgo de privacidad percibido y amplía el mercado. El reenvío es también
la vía natural para usuarios con proveedores de correo sin IMAP accesible o con 2FA
problemático. Requiere autenticar el origen del reenvío (dirección dedicada + verificación de
remitente) para evitar inyección de contenido no deseado.

---

## D-22 — `Service` es el término de dominio; `Subscription` no es una entidad separada

**Estado:** aceptada, revisable.

**Contexto.** El brief de producto nombra `Service` y `Subscription` como conceptos distintos.
En el segmento objetivo (autónomos y pymes) el mismo objeto cubre suscripciones SaaS, hosting,
dominios, seguros, suministros, telefonía y cuotas profesionales. "Suscripción" describe solo
una parte del conjunto y arrastra connotación de consumo.

**Decisión.** El término de dominio es **`Service`**. `Subscription` **no** es una entidad
separada: se modela como un `Service` con `status = active` y periodicidad definida. En la
interfaz se habla de "servicios" y "gastos recurrentes", no de "suscripciones".

**Alternativas.**

1. `Subscription` como entidad principal y `Service` como su generalización (jerarquía de
   herencia). Añade complejidad de mapeo sin aportar nada en v1.
2. Ambas entidades separadas. Duplica el modelo y obliga a decidir en cada caso a cuál
   pertenece un gasto, sin criterio claro.

**Consecuencias.** Un único agregado central, más simple de consultar y de agregar para el
dashboard. El vocabulario del brief queda mapeado explícitamente en `ARCHITECTURE.md` §4.12.

**Disparador de revisión.** Si aparece la necesidad real de modelar contratos con múltiples
líneas, componentes o precios por usuario (p. ej. licencias por asiento), se introduce
`Subscription` como entidad hija de `Service`. No antes.

---

## D-23 — IMAP genérico como capacidad estratégica; la arquitectura no se diseña alrededor de Gmail

**Estado:** aceptada.

**Contexto.** La investigación de mercado (`COMPETITORS.md`) indica que las herramientas
nativas de correo verificadas dependen de Gmail o de integraciones concretas, y que el mercado
español/europeo es mayoritariamente manual. El brief pide explícitamente no diseñar la
arquitectura alrededor de Gmail.

**Decisión.** El **IMAP estándar es el camino principal** de ingesta, presente en la
arquitectura desde el principio (fase 3 del roadmap, no fase final). Debe funcionar con
cualquier servidor IMAP estándar siempre que sea técnicamente posible: Gmail, Microsoft 365,
servidores IMAP propios, correo de hosting, proveedores de correo corporativo y proveedores de
dominios/hosting.

Gmail y Microsoft 365 son **proveedores entre otros**, no el camino principal. Sus APIs
propietarias (Gmail API, Microsoft Graph) se añaden **después**, como adaptadores de
`MailboxProviderInterface`, y solo si aportan algo que IMAP no da.

**Alternativas.** Integrar primero Gmail API (mejor experiencia de autorización, notificaciones
push) y añadir IMAP después. Se descarta: concentra el producto en un proveedor, arrastra la
carga de verificación y evaluación CASA, y deja fuera a la mayoría del mercado objetivo.

**Consecuencias.** El producto no depende de la aprobación de un proveedor concreto. Hay que
asumir la variabilidad de IMAP (cuotas, `uidValidity`, carpetas, límites de conexión) y
probarlo con varios proveedores reales. El correo es una **fuente de información**, no el
producto: la aplicación no es un cliente de correo (`ARCHITECTURE.md` §4.10).

---

## D-24 — Extracción por capas: determinista → reglas → OCR → IA

**Estado:** aceptada.

**Contexto.** El brief advierte de no asumir que todos los documentos deban enviarse a una API
externa de IA, y pide poder evolucionar hacia procesamiento más privado o europeo sin
reescribir el dominio.

**Decisión.** La extracción se aplica en **cascada** y se detiene en cuanto alcanza el umbral
de confianza:

1. **Determinista** — cabeceras, remitente, `message-id`, adjuntos, metadatos del PDF, texto
   embebido. No sale nada del sistema.
2. **Reglas** — patrones de proveedor, plantillas de factura, expresiones de importe y
   periodicidad, diccionario de proveedores conocidos. No sale nada del sistema.
3. **OCR** — solo para documentos sin capa de texto, ejecutado en nuestra infraestructura. No
   sale nada del sistema.
4. **IA** — solo si las capas anteriores no alcanzan el umbral. Es la única capa que puede
   implicar transferencia a un tercero, y requiere consentimiento explícito (D-08).

Los proveedores de IA son **intercambiables** tras `AiProviderInterface`, incluido un modelo
autoalojado. Cada extracción registra `extractionMethod` para medir el valor real de cada capa.

**Alternativas.** Enviar todo a IA (más simple, mejor precisión inicial) o no usar IA nunca
(peor cobertura en plantillas desconocidas).

**Consecuencias.** Más trabajo de ingeniería en las capas 1–3, pero menor coste por documento,
menos datos enviados a terceros y una ruta de migración a procesamiento privado/europeo sin
tocar el dominio. La precisión se mide por capa, lo que permite decidir con datos dónde
invertir.

---

## D-25 — Sin afirmaciones absolutas de privacidad ("100 % privado", "100 % UE")

**Estado:** aceptada.

**Contexto.** El brief pide no hacer afirmaciones de "100 % privado", "100 % UE" o similares
hasta que la implementación y los proveedores utilizados permitan sostenerlas. La
documentación de producto tiende a convertir hallazgos de investigación en afirmaciones
comerciales absolutas.

**Decisión.** La privacidad se describe **por lo que el sistema hace** (solo lectura,
minimización, cifrado de credenciales, borrado por cuenta, retención controlada, registro de
accesos sensibles), nunca por eslóganes. **No se publica** "100 % privado", "100 % UE" ni
equivalentes mientras no sea verificable de punta a punta, incluidos los proveedores externos
utilizados.

Del mismo modo, los hallazgos de investigación de mercado se expresan con su nivel de certeza
acotado ("la investigación realizada hasta ahora no ha identificado…"), no como verdades
absolutas ("no existe ningún competidor que…").

**Alternativas.** Usar el mensaje de privacidad como argumento de marketing desde el principio.

**Consecuencias.** Mensaje comercial menos rotundo, pero defendible y verificable. Obliga a
revisar las afirmaciones publicadas en la fase 13 (hardening) y a actualizar la documentación
si aparece un competidor que contradiga el hallazgo.

---

## D-26 — Roadmap vertical primero: la cadena end-to-end antes que la profundidad horizontal

**Estado:** aceptada.

**Contexto.** El brief pide que la primera vertical completa (cuenta → conectar IMAP →
encontrar factura → extraer proveedor + importe + periodicidad → proponer servicio → usuario
confirma → dashboard → próximos costes) sea más importante que construir decenas de
funcionalidades auxiliares, y que IMAP no quede como funcionalidad experimental de última fase.

**Decisión.** El roadmap (`ROADMAP.md`) sitúa la **vertical end-to-end en la fase 3**, con
alcance deliberadamente mínimo (una carpeta, ventana corta, extracción por reglas, sin OCR ni
IA, adjuntos procesados en memoria). Las fases 4–12 profundizan cada área horizontalmente. La
ingesta de correo se endurece en la fase 8.

**Alternativas.** Construir primero todas las funcionalidades horizontales (dashboard completo,
alertas, documentos, notificaciones) y añadir el correo al final. Se descarta: retrasa la
validación de la hipótesis de producto (`PRODUCT.md` §3.2) y arriesga construir un gestor
manual más.

**Consecuencias.** Se valida la propuesta de valor con usuarios reales antes de invertir en
profundidad. Algunas piezas se implementan dos veces (versión mínima en la fase 3, versión
completa después), lo que es aceptable a cambio de reducir el riesgo de producto. **Criterio de
continuidad:** si la vertical no funciona con buzones reales, no se avanza sin resolverlo.

---

## D-27 — Varias cuentas de correo por organización, con deduplicación entre cuentas

**Estado:** aceptada.

**Contexto.** Un autónomo o una pequeña empresa rara vez tiene un solo buzón de facturación: es
habitual tener la cuenta del titular, la del asesor o gestoría, una cuenta específica de
facturación y, si se usa el reenvío (D-21), una dirección de ingesta. Si el producto solo
permitiera una cuenta, obligaría al usuario a elegir y dejaría fuera facturas reales.

**Decisión.** `EmailAccount` pertenece a la **organización**, no al usuario, y la relación es
**1:N**: una organización puede conectar varias cuentas, de cualquier proveedor y por cualquier
vía (IMAP o reenvío). Cada cuenta tiene su propio cursor (`EmailSyncCursor`), su propio estado
de sincronización y sus propias credenciales cifradas. No hay límite estructural; el límite, si
lo hay, es de plan (`EntitlementCheckerInterface`, D-15).

**Consecuencia no obvia: la deduplicación debe ser en dos niveles.** El mismo mensaje puede
llegar por dos cuentas (una factura reenviada a la cuenta de facturación y también presente en
el buzón original). Por tanto:

- **Nivel de mensaje (por cuenta):** `UNIQUE (email_account_id, folder, uid)` y
  `UNIQUE (email_account_id, message_id)`. Evita reprocesar el mismo mensaje dentro de una
  cuenta.
- **Nivel de organización (entre cuentas):** documentos por `checksumSha256` y descubrimientos
  por proveedor + importe + fecha + periodicidad. `Discovery` es **tenant-scoped**, no
  cuenta-scoped, precisamente para que dos buzones no generen dos propuestas del mismo
  servicio.

**Alternativas.**

1. Una sola cuenta por organización. Más simple, pero excluye el caso real de varios buzones y
   obliga a reenviar todo a una única dirección.
2. Varias cuentas sin deduplicación entre ellas. Genera descubrimientos duplicados y ruido en
   la bandeja de revisión, que es justo lo que el producto debe evitar.

**Consecuencias.** El inventario de servicios es **de la organización**, no de la cuenta: los
servicios confirmados sobreviven a la desconexión de cualquier cuenta (ya documentado en
`SECURITY.md` §6). La UI de cuentas de correo debe mostrar el estado de cada una por separado.
La deduplicación entre cuentas añade una consulta por organización en el pipeline, con coste
bajo y acotado por índices. En la fase 3 del roadmap se implementa **una sola cuenta**; el
soporte multi-cuenta llega en la fase 8.

## D-28 — CSS propio con tokens de diseño; sin Tailwind en v1

**Contexto.** La interfaz es un panel de datos: tarjetas, tablas, formularios, badges y
estados. No es un sitio de marketing con diseños variados. El proyecto ya decidió no añadir
Node ni `npm` a la imagen Docker (D-17) y evitar dependencias y peticiones a terceros
(`SECURITY.md` §1).

**Decisión.** **CSS propio**, escrito a mano, organizado en capas y basado en **tokens de
diseño** como custom properties. Sin preprocesador, sin PostCSS, sin Tailwind en v1.

- Un único `assets/styles/app.css` con `@layer reset, tokens, base, components, utilities`.
- Tokens en `:root`: color, espaciado, radios, sombras, tipografía, y un tema oscuro vía
  `prefers-color-scheme` y `[data-theme]`.
- Un conjunto pequeño y cerrado de clases de componente: `.card`, `.btn`, `.badge`, `.table`,
  `.field`, `.alert`, `.empty-state`.
- **Anidamiento CSS nativo** (soportado por todos los navegadores modernos desde 2023): no hace
  falta Sass.
- AssetMapper sirve el fichero directamente, con _digest_ para el cache-busting.

**Alternativas.**

1. **Tailwind vía `symfonycasts/tailwind-bundle`.** Descarga el binario _standalone_ de
   Tailwind, así que **no requiere Node**. Es una opción legítima y la mejor forma de usar
   Tailwind en este proyecto. Se descarta en v1 porque añade un binario y un paso de compilación
   a la imagen, y porque las clases utilitarias en las plantillas Twig hacen el marcado más
   difícil de leer en un panel con muchas tablas y formularios.
2. **Tailwind por CDN.** **Descartado sin matices.** Es un script de terceros que genera el CSS
   en el navegador: contradice el principio de no enviar datos a terceros cuando no es
   necesario, añade una dependencia de red en tiempo de ejecución y degrada el rendimiento.
3. **Bootstrap.** Descartado: pesado, opinado y con una estética reconocible que no encaja.
4. **Sass/PostCSS.** Descartado: añade un paso de compilación que el CSS nativo ya no justifica.

**Consecuencias.** Cero dependencias de frontend y cero pasos de compilación. El coste es que
la consistencia visual depende de usar los tokens y las clases de componente en lugar de
inventar estilos por pantalla; se revisa en el _code review_.

**El responsive no es un motivo para cambiar de decisión.** El CSS moderno cubre lo que antes
justificaba un framework: Grid y Flexbox para el layout, `clamp()` para tipografía y espaciado
fluidos, `@media` con enfoque mobile-first y **container queries** para que cada componente se
adapte al ancho de su contenedor en lugar del viewport. El único caso genuinamente difícil —las
tablas anchas del dashboard y del historial— es un problema de diseño, no de framework, y se
resuelve con el patrón de tabla-a-tarjetas descrito en `ARCHITECTURE.md` §12. Criterio de
aceptación: **sin scroll horizontal a 320 px**.

**Disparador de reversión:** si el número de pantallas y componentes crece hasta que un único
fichero deja de ser coherente, o si más de una persona trabaja en la interfaz, se adopta
Tailwind mediante `symfonycasts/tailwind-bundle` (binario standalone, sigue sin Node). La
decisión no afecta al dominio ni a las plantillas Twig más allá de los nombres de clase.

---

## D-29 — Pipeline de enriquecimiento progresivo: la IA resuelve incertidumbre, no sustituye la lógica de negocio

**Estado:** aceptada.

**Contexto.** El producto recibe correo de facturación y debe identificar facturas, recibos,
renovaciones y comunicaciones de servicios recurrentes. La tentación evidente es enviar cada
correo a un modelo de lenguaje y dejar que devuelva los datos. Es la solución más rápida de
escribir y la más cara de operar, la menos explicable y la más difícil de auditar.

**Decisión.** El procesamiento es un **pipeline de enriquecimiento progresivo** de nueve
niveles (0 a 8), ordenados de más barato y determinista a más caro y difuso. Cada nivel se
ejecuta **solo si el anterior no alcanzó el umbral de confianza**. La IA es el nivel 5 (y el 6
como excepción) y **nunca es el camino por defecto**.

```text
Ingesta → Dedup → Metadatos → Filtros → Billing Score
   → Extracción determinista → Proveedor conocido / parser
   → IA económica → (IA avanzada) → Validación → Service Matching → Decisión
```

**Por qué no enviar todos los correos a una IA.**

1. **Coste.** Un buzón de 10.000 mensajes con IA por mensaje es económicamente inviable para
   un producto de 5–20 €/mes. Con el pipeline, la mayoría de mensajes se descartan en el nivel
   2 sin descargar siquiera el cuerpo.
2. **Privacidad.** Enviar el buzón completo a un tercero es incompatible con el principio de
   minimización y con las expectativas del usuario europeo (`SECURITY.md`).
3. **Explicabilidad.** "El modelo dijo 29,90 €" no es una respuesta aceptable para un gasto
   recurrente. "El remitente es OVH, el asunto contiene _facture_, hay un PDF y el importe
   aparece en el texto" sí lo es.
4. **Fiabilidad.** Un modelo alucina; una regex sobre una plantilla conocida, no. El dominio
   no puede depender de la estabilidad de un proveedor externo.
5. **Latencia y volumen.** Procesar 10.000 mensajes con IA tarda horas y consume cuota.

**Por qué la IA no contiene lógica de negocio.** El cálculo de periodicidad, próximo cobro,
comparación de precios, detección de duplicados y equivalencia mensual son **funciones puras
testeables** en `Shared\Domain` / `Services\Domain`. Si vivieran en un _prompt_, no se podrían
testear, versionar ni explicar, y cambiarían de resultado sin que nadie toque el código.

**Alternativas.**

1. **Todo a IA.** Descartada por coste, privacidad, explicabilidad y fiabilidad.
2. **Solo reglas, sin IA.** Descartada: hay facturas de proveedores desconocidos con
   maquetaciones libres que ninguna regla razonable cubre. Sin IA, el producto no arranca en
   un buzón real.
3. **Solo parsers por proveedor.** Descartada: exige escribir cientos de parsers antes de
   tener un solo usuario.

**Consecuencias.** Más código y más entidades que la alternativa "todo a IA" (estados,
conocimiento, presupuesto, trazabilidad). A cambio, el coste marginal por buzón **decrece**
con el tiempo, el sistema es auditable y el dominio es independiente del proveedor de IA.
Ver `ARCHITECTURE.md` §13.

---

## D-30 — Dos niveles de IA (económica y avanzada) con umbral configurable

**Estado:** aceptada.

**Contexto.** No todos los documentos son igual de difíciles. La mayoría de facturas son
plantillas repetidas; unas pocas son PDF escaneados, multipágina o con maquetación irregular.

**Decisión.** Dos niveles de modelo, con umbral de escalado **configurable**:

| Nivel         | Uso                 | Cuándo                                                                                                                              |
| ------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 5 · económica | Extracción estándar | Por defecto, cuando reglas y parsers no bastan                                                                                      |
| 6 · avanzada  | Extracción difícil  | Confianza insuficiente, campos contradictorios, proveedor desconocido con documento complejo, PDF difícil, información insuficiente |

**Por qué no usar siempre el modelo avanzado.** Cuesta entre 10 y 50 veces más por documento y
no aporta nada en el 90 % de los casos. **Por qué no usar siempre el económico.** Falla en los
documentos difíciles, y un fallo silencioso es peor que un coste mayor: genera un `Discovery`
incorrecto que erosiona la confianza del usuario.

**Alternativas.** Un único modelo intermedio. Descartada: obliga a elegir entre pagar de más en
el caso fácil o fallar en el difícil.

**Consecuencias.** Cada escalado se registra en `AiUsage` con `tier = advanced`, lo que permite
medir si el escalado aporta valor y ajustar el umbral con datos en lugar de intuición.

---

## D-31 — Clasificador de facturación explicable (`BillingClassifier`)

**Estado:** aceptada.

**Contexto.** El primer filtro decide qué mensajes merecen procesamiento. Si es opaco, no se
puede depurar por qué falta una factura ni por qué se procesan boletines.

**Decisión.** Un componente independiente, `BillingClassifierInterface`, que devuelve
`score` (0–100), `classification` y **`reasons`** (lista de motivos con su peso). Los pesos
viven en configuración versionada, son ajustables por organización y están documentados
(`ARCHITECTURE.md` §13.4).

**Por qué no solo palabras clave.** Un boletín con "invoice" en el asunto no es una factura;
una factura de un proveedor conocido con asunto en otro idioma sí lo es. El score combina
señales independientes (remitente, dominio, adjunto, importe detectable, lenguaje de
renovación, `local-part` de facturación) y admite pesos negativos (listas de ignorados,
boletines, hilos propios).

**Por qué explicable.** El usuario está conectando su buzón de facturación. Tiene derecho a
saber por qué un correo se procesó o se ignoró. `billingReasons` se persiste en
`EmailMessage` y se muestra en la interfaz.

**Alternativas.** Clasificador opaco (ML o IA) para el primer filtro. Descartada: cara, no
explicable y no ajustable por el usuario.

**Consecuencias.** Los pesos hay que mantenerlos y revisarlos. A cambio, el filtro es
gratuito, instantáneo, determinista y auditable.

---

## D-32 — Máquina de estados explícita y log de transiciones

**Estado:** aceptada.

**Contexto.** Un pipeline con reintentos, escalado a IA, procesamiento diferido y descarte
temprano es imposible de depurar sin saber en qué punto está cada mensaje.

**Decisión.** Diez estados explícitos (`RECEIVED`, `IGNORED`, `CANDIDATE`, `EXTRACTED`,
`CLASSIFIED`, `MATCHED`, `DISCOVERY`, `REQUIRES_REVIEW`, `DEFERRED`, `FAILED`) y una entidad
**append-only** `MessageProcessingEvent` que registra cada transición con: estado origen,
estado destino, motivo, extractor, nivel de extracción, si intervino IA (`aiUsageId`),
duración y datos adicionales.

**Alternativas.** Un campo `status` con cuatro valores (`pending`, `processing`, `processed`,
`failed`). Descartada: no distingue "ignorado por score" de "falló" ni de "esperando
presupuesto", que son tres situaciones con acciones completamente distintas.

**Consecuencias.** Una tabla más y más escritura. A cambio, cada mensaje tiene una respuesta
consultable a _qué ocurrió, cuándo, con qué extractor, si intervino IA, con qué resultado y por
qué_, que es exactamente lo que exige el requisito de observabilidad.

---

## D-33 — Conocimiento de proveedores (global) separado del conocimiento del buzón (por cuenta)

**Estado:** aceptada.

**Contexto.** El sistema debe reconocer proveedores y aprender de cada buzón. Mezclar ambos
conocimientos produce contaminación cruzada: un patrón aprendido en el buzón de un usuario
afectaría al de otro.

**Decisión.** Tres entidades con ámbitos distintos:

| Entidad                 | Pregunta                                           | Ámbito                   |
| ----------------------- | -------------------------------------------------- | ------------------------ |
| `Provider`              | ¿Quién es?                                         | Global o del tenant      |
| `ProviderIdentity`      | ¿Cómo lo reconocemos? (dominio, remitente, patrón) | **Global**               |
| `ProviderParser`        | ¿Cómo extraemos sus datos?                         | **Global**               |
| `MailboxKnowledgeEntry` | ¿Qué hemos aprendido de _este_ buzón?              | **Por cuenta de correo** |

**Por qué separar `Provider` de `ProviderIdentity`.** Un proveedor factura desde varios
dominios y direcciones (`ovh.com`, `ovh.es`, `invoice@ovh.com`, `billing@ovh.com`). Modelarlo
como campos del proveedor obliga a duplicar el proveedor o a perder información.

**Por qué el conocimiento del buzón es por cuenta.** Dos buzones del mismo tenant pueden tener
patrones distintos (uno recibe facturas de OVH en francés, otro en español). Y un patrón
aprendido de un usuario no debe aplicarse a otro sin revisión.

**Por qué no es machine learning.** Es conocimiento estructurado, inspeccionable y editable por
el usuario. No hay necesidad demostrada de ML (ver D-39).

**Alternativas.** Un único almacén de patrones global. Descartada por contaminación cruzada y
por privacidad: revelaría patrones de un tenant a otro.

**Consecuencias.** La promoción de un patrón aprendido a conocimiento global es una **decisión
explícita**, no automática. Es más trabajo, pero evita que un error de un buzón se propague.

---

## D-34 — Validación obligatoria de la salida de IA

**Estado:** aceptada.

**Contexto.** Un modelo de lenguaje puede devolver un importe negativo, una fecha imposible o
una periodicidad inventada con una confianza declarada alta.

**Decisión.** **La IA no es una fuente de verdad.** Su salida es una propuesta que atraviesa
una cadena obligatoria antes de convertirse en dato:

```text
salida de IA → DTO tipado → Symfony Validator → reglas de negocio → resultado válido
```

Se valida: tipos (importe numérico, moneda ISO 4217, fechas reales), rangos (importe positivo y
por debajo de un máximo razonable; se rechaza `amount = -8738291`), coherencia interna
(`renewalDate` no anterior a `invoiceDate`; periodicidad compatible con la diferencia de
fechas), pertenencia al conjunto de periodicidades soportadas, campos obligatorios y coherencia
con lo ya extraído determinísticamente.

**Alternativas.** Confiar en el modelo y validar "cuando haga falta". Descartada: el fallo se
descubre en producción, en forma de gasto incorrecto en el dashboard del usuario.

**Consecuencias.** Un modelo que alucina no corrompe el dominio: como mucho genera un
`Discovery` que el usuario rechaza. El coste es que hay que mantener las reglas de validación
en paralelo a los extractores.

---

## D-35 — Service matching ponderado con umbrales; nunca creación automática

**Estado:** aceptada.

**Contexto.** Un documento extraído debe asociarse a un servicio existente o proponer uno
nuevo. Crear servicios automáticamente llena el dashboard de duplicados; no crear ninguno
obliga al usuario a introducir todo a mano, que es justo lo que el producto quiere evitar.

**Decisión.** **Nunca se crea un servicio automáticamente.** Primero se intenta asociar a un
`Service` existente con una puntuación ponderada (proveedor +40, dominio +20, nombre del
servicio +20, moneda +5, periodicidad +5, similitud de importe ±10 % +5, remitente exacto +5):

| Puntuación | Resultado | Acción                                      |
| ---------- | --------- | ------------------------------------------- |
| ≥ 70       | HIGH      | Asociación automática → `MATCHED`           |
| 40–69      | MEDIUM    | `Discovery` para revisión → `DISCOVERY`     |
| < 40       | LOW       | `Discovery` de servicio nuevo → `DISCOVERY` |

Umbrales configurables. El desglose se persiste en `Discovery.matchScore` y
`Discovery.matchReasons`.

**Por qué ponderado y no reglas duras.** Un mismo servicio puede facturarse desde dominios
distintos, con importes que cambian o con nombres ligeramente diferentes. Una regla dura
("mismo dominio y mismo importe") falla en cuanto cambia cualquiera de los dos. Una puntuación
tolera la variación y **expresa la incertidumbre en lugar de ocultarla**.

**Alternativas.** Coincidencia exacta por dominio. Descartada por frágil. Asociación siempre
automática al mejor candidato. Descartada: un falso positivo silencioso es peor que una
pregunta.

**Consecuencias.** El usuario revisa descubrimientos al principio. A medida que el sistema
aprende (D-33), la proporción de asociaciones HIGH sube y las revisiones bajan.

---

## D-36 — Control de coste de IA con presupuesto y parada dura

**Estado:** aceptada.

**Contexto.** Sin control de coste, un buzón grande, un bucle de reintentos o un umbral mal
ajustado generan una factura ilimitada. Es el riesgo económico más serio del producto.

**Decisión.** Registro **obligatorio** de cada llamada en `AiUsage` (incluso si falla) y
presupuesto por organización y periodo en `AiBudget`, con `hardStop` configurable. Al alcanzar
el techo: **no se hacen más llamadas de IA**, el pipeline continúa por reglas, los mensajes
afectados quedan en `DEFERRED` con el motivo, y se reanudan en el siguiente periodo o cuando el
usuario amplía el presupuesto.

El guard se comprueba **antes** de cada llamada, no después. El transporte `ai` tiene
concurrencia limitada y rate limit propio. **Nunca hay llamadas ilimitadas durante una
sincronización.**

**Por qué `AiUsage` es obligatorio y no opcional.** Hay dos preguntas de negocio que no se
pueden contestar a posteriori si no se registran desde el primer día: _¿cuánto nos cuesta
procesar el buzón de este usuario?_ y _¿cuánto cuesta de IA un usuario medio al mes?_ Sin esos
datos no se puede fijar el precio del producto.

**Alternativas.** Sin límite, confiando en que el volumen sea bajo. Descartada: el modo de
fallo es una factura de cuatro cifras. Límite solo por número de llamadas. Descartada: no
refleja el coste real, que depende del modelo y del tamaño del documento.

**Consecuencias.** Hay que mantener el catálogo de precios de los modelos y estimar tokens
antes de la llamada. A cambio, el coste es acotado y medible, y se puede decidir el precio del
producto con datos.

---

## D-37 — Idempotencia: nunca pagar dos veces por el mismo mensaje

**Estado:** aceptada.

**Contexto.** Un mensaje puede aparecer varias veces: en dos carpetas, en dos cuentas del
mismo tenant, tras un reenvío, o al repetir una sincronización. Con Symfony Messenger, un
mensaje puede reentregarse tras un fallo del worker. Reprocesar no es solo desperdicio de CPU:
**puede costar dinero** si vuelve a pasar por IA.

**Decisión.** Identidad estable por combinación de señales, porque ninguna basta sola:

| Señal            | Problema                                                                    |
| ---------------- | --------------------------------------------------------------------------- |
| `folder` + `uid` | Los UID se repiten entre buzones; se invalidan si cambia `uidValidity`      |
| `Message-ID`     | Puede faltar, repetirse o falsificarse                                      |
| `contentHash`    | Dos correos idénticos legítimos colisionan; requiere descargar el contenido |

1. **Clave primaria de deduplicación:** `emailAccountId + folder + uid` (única, estable y
   gratuita), guardada junto con `uidValidity`.
2. **Clave secundaria:** `emailAccountId + messageId` (detecta el mensaje movido de carpeta).
3. **`contentHash`:** deduplica documentos, detecta el mismo mensaje en dos cuentas y sirve de
   **clave de caché de extracción**.
4. **Deduplicación entre cuentas a nivel de organización:** `Discovery` es tenant-scoped, así
   que el mismo servicio detectado por dos buzones produce un descubrimiento, no dos.

**Antes de cualquier llamada de IA** se comprueba que no exista ya un `ExtractedDocument`
válido para ese `contentHash`. Si existe, se reutiliza: coste cero.

**Regla explícita:** _si el mismo correo vuelve a aparecer durante una sincronización, no debe
volver a consumir IA._

**Implementación (fase 9).** La comprobación se materializa en la tabla `extraction_cache`,
con clave única `(organization_id, content_hash)`. Guarda **solo el resultado estructurado**
(`ExtractedDocument` serializado), nunca el cuerpo del mensaje (D-10). En un acierto se
incrementa `hitCount`, se actualiza `lastUsedAt` y se refrescan `sender`, `senderDomain` y
`subject` con los del mensaje actual (`withSenderContext()`), porque el mismo cuerpo puede
llegar desde otra cuenta o con otro asunto. La transición a `EXTRACTED` se registra con el
extractor `cache`, para que la observabilidad distinga «lo resolvió la caché» de «lo resolvió
el extractor».

**La caché está acotada por organización, a propósito.** Compartirla entre tenants sería más
barato, pero convertiría el acierto de caché en un canal lateral: un cliente podría deducir
que otro ha recibido exactamente el mismo correo. El ahorro no compensa la fuga.

**Un cuerpo vacío nunca se cachea.** `contentHash('')` es un hash válido, así que la guarda no
puede ser «el hash está vacío»: sería código muerto y haría que todos los mensajes sin cuerpo
compartieran una única entrada. La condición es que el cuerpo, ya recortado, no esté vacío.

**Alternativas.** Reutilizar el log `MessageProcessingEvent` como caché. Descartada: es un log
append-only pensado para auditoría, no para consulta por clave; usarlo obligaría a recorrer
todas las transiciones de un mensaje para encontrar la extracción. Caché global (sin
`organization_id`). Descartada por el canal lateral descrito arriba.

**Consecuencias.** Una tabla más y una política de purga pendiente (fase 13). A cambio, un
reintento, una resincronización o un duplicado no cuestan dinero, y el ahorro es medible
(`hitCount`, `app:mail:stats`).

**Alternativas.** Confiar solo en el UID de IMAP. Descartada: se rompe al cambiar `uidValidity`
o al mover el mensaje de carpeta. Confiar solo en el `Message-ID`. Descartada: falta en correos
reales.

**Consecuencias.** Tres claves que mantener y un índice adicional. A cambio, un reintento, una
resincronización o un duplicado no cuestan dinero.

---

## D-38 — Procesamiento progresivo y descarga diferida

**Estado:** aceptada.

**Contexto.** Descargar el cuerpo y los adjuntos de todos los mensajes de un buzón de 10.000
correos consume tráfico, almacenamiento y CPU, y hace inviable el procesamiento.

**Decisión.** Cada nivel del pipeline se ejecuta **solo cuando el anterior lo justifica**:

```text
cabeceras → metadatos → candidato → cuerpo → adjunto → OCR → IA
```

Un mensaje que se descarta en el nivel 2 (billing score) **nunca descarga su cuerpo ni sus
adjuntos**. Las cabeceras se piden con `BODY.PEEK[HEADER]`, que no marca el mensaje como leído.

**Alternativas.** Descargar todo y filtrar en local. Descartada: es exactamente el
comportamiento que hace inviable el producto en buzones reales.

**Consecuencias.** El pipeline tiene más pasos y el estado intermedio hay que persistirlo
(por eso existe la máquina de estados, D-32). A cambio, el coste por mensaje descartado es
prácticamente nulo.

---

## D-39 — Sin machine learning en v1

**Estado:** aceptada.

**Contexto.** El sistema "aprende" del buzón. La palabra _aprender_ sugiere ML: clasificadores
entrenados, embeddings, modelos propios.

**Decisión.** **No se introduce machine learning en v1.** El aprendizaje se implementa como
**conocimiento estructurado y persistente** (`MailboxKnowledgeEntry`, `ProviderIdentity`,
`ProviderParser`), inspeccionable y editable por el usuario, alimentado por confirmaciones,
correcciones y patrones observados.

**Por qué.** No hay necesidad demostrada: el problema se resuelve con reglas, parsers y
conocimiento explícito. El ML añadiría opacidad (contradice D-31 y D-34), coste de
entrenamiento e inferencia, necesidad de datos etiquetados que todavía no existen, y riesgo de
degradación silenciosa. Además, un usuario que corrige al sistema espera que la corrección se
aplique de forma predecible, no que se diluya en un modelo.

**Disparador de revisión.** Si con datos reales se comprueba que el conocimiento estructurado
no cubre una proporción significativa de los casos y que existe volumen etiquetado suficiente,
se revisa esta decisión. La arquitectura no lo impide: `BillingClassifierInterface` y
`ProviderResolverInterface` permiten sustituir la implementación determinista por una
estadística sin tocar el dominio.

**Consecuencias.** Menos sofisticación aparente y más previsibilidad. El "aprendizaje" es
auditable: el usuario puede ver y editar lo que el sistema ha aprendido.

---

## D-40 — Verificación de correo blanda, no bloqueante

**Estado:** aceptada.

**Contexto.** Al registrarse hay que decidir qué se hace con un correo sin verificar. Las dos
opciones habituales son bloquear el acceso hasta que se confirme el enlace, o no verificar
nada. La primera es la más segura, pero castiga al usuario en el peor momento: justo cuando
acaba de llegar y todavía no ha visto ningún valor. La segunda deja la puerta abierta a
registros con correos ajenos.

**Decisión.** La verificación es **blanda**:

- El usuario entra y usa la aplicación sin verificar el correo.
- Mientras no esté verificado, ve un aviso persistente en el armazón con un botón para
  reenviar el enlace.
- **Conectar un buzón de correo exige tener el correo verificado.** Es el único punto donde la
  verificación es un requisito duro, porque a partir de ahí el sistema accede a datos de
  terceros en nombre del usuario.
- Cambiar la dirección de correo **invalida** la verificación anterior (`User::unverify()`), y
  se envía un enlace nuevo a la dirección nueva.
- El enlace va firmado con `UriSigner` y caduca a las 24 horas. No se guarda ningún token en
  base de datos: la firma es la prueba.

**Por qué.** El coste de un correo sin verificar en esta fase es bajo (no hay envíos salientes
ni acceso a datos ajenos), y el coste de bloquear a un usuario recién registrado es alto. En
cambio, conectar un buzón sí es una operación sensible: ahí la verificación deja de ser una
formalidad y pasa a ser un control real.

**Alternativas.**

- _Bloqueo total hasta verificar._ Descartada por fricción en el momento de menor valor
  percibido.
- _Sin verificación._ Descartada: permitiría registrar el correo de otra persona y, más
  adelante, conectar buzones en su nombre.
- _Token en base de datos._ Descartada: `UriSigner` ya resuelve caducidad e integridad sin
  estado adicional que purgar.

**Consecuencias.** Hay que recordar que la verificación es un requisito de la conexión de
buzón, no del acceso: la comprobación vive en el caso de uso de conexión, no en el firewall.
El aviso del armazón es informativo y no debe convertirse en un muro.

---

## D-41 — El contexto de tenant se publica en el filtro, no se asume

**Contexto.** El aislamiento multi-tenant se apoya en un Doctrine Filter que añade
`organization_id = :organizationId` a toda consulta de entidades tenant-scoped (D-11). El filtro
lee su parámetro de `TenantContext`. En las peticiones HTTP funcionaba porque
`ActiveOrganizationListener` sincronizaba explícitamente después de resolver la organización.
En los comandos de consola y en los workers, en cambio, nadie lo hacía: `TenantContext::runAs()`
cambiaba la organización activa pero el filtro seguía con el valor anterior. Como sin organización
el filtro no restringe, el resultado era que **un comando que recorre organizaciones leía los
datos de todas**.

**Decisión.** `TenantContext` publica cada cambio de organización a través de
`TenantFilterSynchronizerInterface`, implementado en infraestructura por
`DoctrineTenantFilterSynchronizer`. La capa de aplicación declara la intención; la infraestructura
la aplica. `setOrganizationId()` y `runAs()` sincronizan siempre, y `runAs()` lo hace al entrar y
al salir, restaurando el contexto anterior incluso si el bloque lanza.

**Por qué.** El aislamiento no puede depender de que cada punto de entrada se acuerde de
sincronizar: es exactamente el tipo de olvido que no se detecta hasta que un cliente ve datos de
otro. Poniendo la publicación dentro del propio cambio de contexto, cualquier camino nuevo
—comando, worker, proceso programado— queda cubierto por construcción.

**Alternativas.**

- _Sincronizar en cada punto de entrada._ Descartada: es la situación que produjo el fallo, y
  añade un punto de fallo por cada camino nuevo.
- _Middleware de Messenger + sincronización en los comandos._ Descartada por lo mismo: sigue
  habiendo varios sitios donde acordarse.
- _Pasar el `organizationId` a mano en cada consulta._ Descartada: contradice D-11 y multiplica
  las oportunidades de error.
- _Row Level Security de PostgreSQL._ Sigue siendo la evolución deseable como capa adicional,
  pero no sustituye a esta corrección.

**Consecuencias.** `TenantContext` deja de ser un contenedor pasivo y pasa a tener un efecto:
construirlo sin sincronizador (pruebas unitarias con repositorios en memoria) es un no-op
deliberado. Sin organización activa el filtro no restringe, y eso es una decisión explícita para
procesos de sistema (migraciones, purgas), no un descuido.

**Aviso de implementación.** `SQLFilter::getParameter()` devuelve el valor **ya entrecomillado**
para SQL, así que la ausencia de organización llega como `''` (dos comillas) y no como cadena
vacía. La comprobación original contra `''` nunca se cumplía y la consulta acababa con
`organization_id = ''`, que PostgreSQL rechaza por no ser un UUID válido. Ver
`TenantFilter::NO_ORGANIZATION`.

---

## D-42 — La lista de remitentes ignorados compara por etiquetas completas

**Estado:** aceptada.

**Contexto.** El nivel 2 del pipeline (§13.4) descarta mensajes de remitentes que nunca son
facturas: redes sociales, boletines, notificaciones de marketing. La lista vive en
configuración (`ignored_senders`) y en la práctica necesita tres formas de entrada: una
dirección concreta (`facturas@proveedor.com`), un dominio entero (`@proveedor.com`) y el
dominio del remitente sin arroba (`proveedor.com`).

La primera implementación comparaba la dirección completa del remitente contra la lista con
`in_array()`. Con eso, las entradas de dominio **nunca coincidían**: `linkedin.com` no es
igual a `messages-noreply@linkedin.com`, así que la penalización de −100 era código muerto y
los mensajes de esas plataformas seguían llegando al pipeline.

**Decisión.** La coincidencia se resuelve en tres pasos —dirección exacta, `@dominio`,
dominio del remitente— y la comparación de dominios es **por etiquetas completas**:

```php
$domain === $candidate || str_ends_with($domain, '.'.$candidate)
```

Así `linkedin.com` coincide con `mail.linkedin.com`, pero **no** con `falsolinkedin.com` ni con
`linkedin.com.evil.io`. La entrada que produjo la coincidencia se registra en
`billingReasons`, para que la decisión siga siendo explicable.

**Por qué no un `str_ends_with()` a secas.** Sería más corto y estaría mal: bastaría con
registrar un dominio que termine en el nombre del proveedor legítimo (`falsolinkedin.com`) para
colarse en la bandeja de entrada de todos los clientes. La lista de ignorados es una decisión
de producto, pero su comparación es una decisión de seguridad.

**Alternativas.**

- _Comparar solo direcciones exactas._ Descartada: obliga a enumerar cada remitente de cada
  plataforma y se queda obsoleta en cuanto cambian.
- _Comparar por sufijo de cadena._ Descartada por el vector de suplantación descrito arriba.
- _Expresiones regulares en configuración._ Descartada: traslada a quien configura el riesgo de
  escribir un patrón demasiado laxo, y no es inspeccionable de un vistazo.

**Consecuencias.** La lista de ignorados funciona por fin como está documentada, y las entradas
`newsletter` y `marketing` —que no eran ni direcciones ni dominios— se retiraron de la
configuración: ya estaban cubiertas por `newsletter_local_parts`. La semántica queda cubierta
por pruebas que incluyen los dos casos de suplantación.

---

## D-43 — El proveedor de IA se elige por necesidad y por organización

**Estado:** aceptada.

**Contexto.** La primera versión del diseño hablaba de «el proveedor de IA», en singular. Pero
un modelo que extrae importes de una factura y otro que redacta un resumen para el usuario no
son intercambiables: uno se ejecuta en cada documento que las reglas no leen (volumen alto, hace
falta que sea barato) y el otro es esporádico (puede permitirse uno caro). Un único ajuste
obligaría a elegir por el caso más común y a pagar de más en el resto.

**Decisión.** Se introduce `AiPurpose` con cuatro necesidades —`extraction`,
`advanced_extraction`, `summarization`, `classification`— y el proveedor se elige **por
necesidad**. La elección se guarda en `Organization.settings`, con lo que **es por cliente**:

```json
{ "ai.enabled": true, "ai.providers": { "extraction": "groq", "advanced_extraction": "openai" } }
```

**Por qué por organización y no global.** En un SaaS, el cliente que paga la factura de IA es
quien decide con qué proveedor se le cobra. Una configuración global obligaría a todos a usar el
mismo, que es exactamente lo contrario de lo que un multi-tenant vende.

**Por qué el interruptor es separado de la elección.** «Tener una clave puesta» y «querer que se
use» no son lo mismo: un operador puede dejar la clave configurada para probar y apagarla sin
borrarla. Por eso hay un `ai.enabled` aparte, y por eso la IA está **desactivada por defecto**
(D-08): sin el interruptor, ninguna llamada se hace, aunque haya claves.

**El catálogo es el código.** Un proveedor de IA se declara en `IntegrationRegistry` con su
nivel (`AiTier::CHEAP` o `AiTier::ADVANCED`) y sus necesidades, y el selector usa esos mismos
datos. El panel de integraciones y el de IA no pueden desincronizarse porque no hay dos listas
que mantener.

**Consecuencias.** Cambiar de proveedor para una necesidad no toca el dominio ni el resto de las
necesidades. Un `purpose` o un `provider` inexistente en el POST se ignora: la lista válida la
pone el código.

---

## D-44 — El nivel 6 comprueba el nivel del proveedor, no solo que haya uno

**Estado:** aceptada.

**Contexto.** El escalado a IA avanzada (nivel 6) se activa cuando la confianza del nivel 5 es
insuficiente. La primera implementación comprobaba únicamente que hubiera **algún** proveedor
configurado para `advanced_extraction`, leyendo la elección guardada. Eso no basta: la elección
guardada puede apuntar a un proveedor **económico**, y entonces una llamada etiquetada como
avanzada la paga un modelo `CHEAP`, el registro de coste miente y la premisa del nivel 6 —«esta
necesidad es más difícil y necesita un modelo más capaz»— es falsa.

**Decisión.** `AdvancedAiDocumentExtractor::supports()` resuelve el proveedor **real** con
`forPurpose()` y exige que su `AiTier` sea `ADVANCED`. Si la resolución falla
(`AiProviderException`), no hay escalado.

**Por qué no basta con mirar el nombre.** El nivel de un proveedor es un dato del catálogo, no
una convención sobre el texto de la clave: `ollama` es `CHEAP` y `openai` es `ADVANCED`, pero
eso está declarado en `IntegrationRegistry::aiTierOf()` y va a poder cambiar.

**Consecuencias.** Un proveedor económico elegido para `advanced_extraction` no se usa, y el
panel de coste muestra lo que de verdad se ha gastado.

---

## D-45 — El secreto guardado no vuelve al navegador, y en blanco significa «no lo he tocado»

**Estado:** aceptada.

**Contexto.** Un formulario de credenciales tiene dos problemas que aparecen cuando hay más de
una integración. El primero es de seguridad: si el token guardado se devuelve al HTML, aparece
en el historial del navegador, en la caché, en cualquier captura de pantalla y en el «copiar y
pegar» del operador. El segundo es de comportamiento: con el campo del secreto pintado vacío
—porque no debe volver—, «vacío» es ambiguo. Puede ser «no lo he tocado» o «quiero quitarlo».

**Decisión.** Dos reglas.

1. El secreto guardado **no se devuelve**. El campo va vacío y su estado se dice aparte («ya
   configurada»). El operador no necesita leer su token: necesita saber que está puesto.
2. Dejar el campo en blanco **conserva** la credencial. Se interpreta como «no lo he tocado».

**Por qué no borrar con el blanco.** Si guardar en blanco borrara, cualquier guardado por otro
motivo —cambiar el modelo, activar la integración, corregir otro campo— dejaría la integración
muerta sin que nadie lo hubiera pedido, y el síntoma («no funciona») no apuntaría a la causa.
Quitar una credencial es una **acción explícita** con su propio botón y su propio mensaje.

**Alternativas.**

- _Devolver el secreto enmascarado (`sk-••••abcd`)._ Descartado: sigue siendo parte del
  secreto en el DOM, y no aporta nada: nadie necesita el final de su token.
- _Borrar cuando el campo viene vacío._ Descartado por el motivo anterior.
- _Un botón de «borrar credenciales» en cada ficha._ Elegido: es explícito, reversible (se puede
  volver a escribir) y no se confunde con «eliminar la integración».

**Consecuencias.** El único sitio donde una credencial está en claro y se persiste es
`IntegrationConfigWriter`; el único donde una guardada se descifra es `IntegrationConfigReader`.
Los logs registran si hay credenciales, nunca sus valores.

---

## D-46 — `Assert\Range` con `min` y `max` no admite mensajes por extremo

**Estado:** aceptada.

**Contexto.** `AiExtractionResult` valida la confianza con
`#[Assert\Range(min: 0, max: 1, minMessage: ..., maxMessage: ...)]`. En Symfony 8 esa
combinación está **prohibida**: `Range` lanza `ConstraintDefinitionException` al construirse
(«The Range constraint can not use minMessage and maxMessage when the min and max options are
both set. Use notInRangeMessage instead.»).

El detalle que la hace especialmente dangerous es **cuándo** ocurre: al construir la
restricción, no al validar. La restricción se construye al arrancar el contenedor, así que el
sistema entero no arrancaba con la IA activada.

**Decisión.** Con `min` y `max` se usa **`notInRangeMessage`**, un único mensaje para el
intervalo. Las alternativas válidas son dos restricciones separadas (`GreaterThanOrEqual` +
`LessThanOrEqual`) o `Range` sin mensajes.

**Por qué se documenta.** Es un fallo **silencioso en la revisión**: el código parece correcto y
lee bien, y solo falla al construir la restricción. Una validación que no se puede construir no
produce «un campo con error»: rompe la aplicación entera.

**Consecuencias.** Las pruebas que construyen el Validator a mano deben habilitar el mapeo por
atributos (`Validation::createValidatorBuilder()->enableAttributeMapping()`): `Validation::createValidator()`
no lo hace, y entonces las restricciones `Assert\*` no se evalúan y la prueba pasa sin
comprobar nada.

---

## D-47 — Ignorar una propuesta la suprime una ventana, no para siempre

**Estado:** aceptada.

**Contexto.** Descartar una propuesta de descubrimiento significa «esto no es mío, no me lo
vuelvas a preguntar». La tentación es convertir ese gesto en un veto permanente: si el usuario
descartó la suscripción de ejemplo, nunca más se propone nada de ese proveedor.

El problema es que la señal **envejece**. Una suscripción que el usuario descartó hace seis
meses puede ser hoy algo que sí quiere: un plan nuevo, un segundo asiento, una factura de un
servicio que ya se ha dado de baja. Con un veto eterno, el sistema aprende una falsehood y la
sostiene para siempre; además, el usuario no tiene forma de deshacerlo salvo entrando en la
base de datos.

**Decisión.** La supresión es una **ventana** de `ignore_suppression_days` (90 días por defecto,
configurable en `config/packages/discovery.yaml`). Mientras la ventana está abierta, no se
proponen nuevas propuestas con la misma clave; al vencerla, la propuesta vuelve a merecer una
pregunta.

La **caducidad** de la bandeja (`expiry_days`, 90 días) es la otra cara de lo mismo: una propuesta
que nadie ha decidido deja de pedir atención, pero **no se borra** — pasa a `expired` y conserva
su evidencia. Caducar no es borrar: es dejar de molestar.

**Alternativas.**

- _Veto permanente por clave de propuesta._ Descartado: envejece mal y no se puede deshacer.
- _No suprimir nada, o suprimir solo mientras la propuesta siga viva._ Descartado: el usuario
  seguiría recibiendo la misma pregunta cada vez que llega una factura, que es exactamente lo
  que hace inservible la bandeja.
- _Borrar la propuesta descartada._ Descartado: se pierde la evidencia y la explicación de por
  qué el sistema dejó de proponerlo.

**Consecuencias.** `DiscoverySettings` es política de producto, no constantes: vive en un
parámetro de contenedor. La clave de supresión es la misma que identifica una propuesta
(proveedor, importe, moneda y periodicidad), de modo que suprimir es por _servicio_, no por
_remitente_.

---

## D-48 — El fallo de una propuesta no aborta el lote

**Estado:** aceptada.

**Contexto.** La bandeja admite revisar varias propuestas de una vez. El lote confirma o
descarta cada una con las mismas reglas que la revisión individual, y cada propuesta puede
fallar: ya estaba confirmada, el servicio ya existe, el importe no cuadra, la propuesta cambió
entre medias.

La implementación natural —recorrer las propuestas y dejar que la primera excepción se salga—
convierte un error puntual en la pérdida de **todas** las propuestas del lote. Veinte propuestas
confirmadas se convierten en veinte propuestas que hay que confirmar una a una, que es
precisamente la razón de ser del lote.

**Decisión.** Cada propuesta se aísla: `BatchReviewDiscoveries` captura el error de cada una,
sigue con la siguiente y devuelve un recuento (`BatchReviewResult`) con lo confirmado, lo
descartado y lo fallido. El lote **nunca** se aborta a medias, y el resultado se le dice al
usuario: «3 confirmada(s). 1 no se ha podido confirmar».

**Alternativas.**

- _Propagar la primera excepción._ Descartado: un error puntual destruye el trabajo de veinte.
- _Validar todo el lote antes de tocar nada (todo o nada)._ Descartado: es peor; el usuario
  pierde también las propuestas válidas y tiene que empezar de cero.
- _Confirmar en silencio solo lo que funciona, sin avisar de los fallos._ Descartado: el usuario
  no puede saber qué se perdió, y una propuesta que «desaparece» de la bandeja parece un error del
  sistema.

**Consecuencias.** La operación de lote es **parcialmente idempotente**: repetirla sobre
propuestas ya resueltas no falla, simplemente no las cuenta. El aviso al usuario es explícito
sobre los fallos, porque un lote que falla en silencio es indistinguible de un lote que no ha
hecho nada.

---

## D-49 — La periodicidad detectada nunca degrada la declarada por el usuario

**Estado:** aceptada.

**Contexto.** El detector de periodicidad (`IntervalRecurrenceDetector`) calcula la regularidad
del cobro a partir del historial de facturas: si tres facturas caen el día 15 de tres meses
seguidos, la periodicidad es mensual y el próximo cobro es predecible. La detección puede
equivocarse —un cobro puntual, un aplazamiento, una factura prorrateada— y su margen de error es
real.

El usuario, en cambio, **sabe** lo que tiene contratado. Si ha declarado «trimestral» y el
historial dice «mensual», hay dos posibilidades: el historial es un subconjunto (una sola factura
de un plan trimestral), o el usuario se equivocó al declararlo. En la primera —que es la más
frecuente— la detección es la que está equivocada.

**Decisión.** La detección **solo adopta** la periodicidad calculada cuando la declarada es
`UNKNOWN`; si el usuario ya declaró una, esta se respeta siempre. Lo que sí se actualiza, en
ambos casos, es la **fecha** del próximo cobro, que es un dato derivado del historial y no una
declaración del usuario.

Cuando el historial es irregular, el detector no propone fecha: una cobrança irregular **no**
tiene próximo cobro predecible, y un dashboard que promete un cobro que no llegará enseña al
usuario a no fiarse del producto.

**Alternativas.**

- _La detección siempre pisa la declarada._ Descartado: un único cobro aislado puede convertir un
  plan trimestral en mensual y desordenar todas las alertas de cobro.
- _No detectar nada nunca._ Descartado: la detección es el mecanismo que permite dar de alta un
  servicio sin que el usuario declare su periodicidad a mano.
- _Detectar siempre y guardar ambas._ Descartado: obliga al usuario a decidir entre dos
  _verdades_ que el sistema no sabe distinguir.

**Consecuencias.** `DetectRecurrence` solo toca el servicio si la detección es **confiable**
(confianza ≥ 0,65) y registra el resultado en la línea de tiempo del servicio
(`RECURRENCE_DETECTED`, con `adopted` a `true` o `false`), de modo que siempre se puede
explicar por qué la fecha se movió.

---

## D-50 — Las rutas de lectura leen del modelo de lectura, no de la entidad de escritura

**Estado:** aceptada.

**Contexto.** `Service` es la entidad central del producto: precio actual, historial de precios,
eventos, periodicidad, próximo cobro. La usan tanto las **escrituras** (crear, cambiar precio,
cambiar estado) como las **lecturas** (panel, detalle, alertas, emparejamiento, informes).

En la Fase 12 se movieron todas las lecturas al read model `ServiceView` y sus DTOs
(`ServicePriceView`, `ServiceEventView`). El motivo no es purismo de capas: una entidad que se
serializa en caché, se indexa o se exporta deja de ser «la entidad» y pasa a ser _una_ forma de
la entidad. Cada consumidor atado a su forma interna se rompe en cuanto esa forma cambia, y el
cambio se propaga a pantallas, cálculos y al pipeline.

**Decisión.** El módulo `Services` publica un **read model** en `src/Services/Domain/Dto/` y una
interfaz de lectura `ServiceViewRepositoryInterface`. Las lecturas —panel, detalle de servicio,
alertas, documentos, facturas, descubrimientos, emparejamiento, histórico de precios, evolución
del gasto— consumen DTOs. La entidad `Service` queda para el módulo que la escribe.

**Alternativas.**

- _Un único modelo (entidad) para todo._ Descartado: es la causa del problema, no su solución.
- _CQRS completo, con modelos separados de lectura y de escritura por módulo._ Descartado por
  coste: no aporta nada aquí donde la operación de lectura es simple, y multiplica el trabajo sin
  un beneficio proporcional. El read model se aplica donde aporta: el servicio, que es la entidad
  más consultada y más inestable.
- _Repositories de Doctrine filtrados sin read model._ Descartado: reproduce el problema con
  menos código, no con más.

**Consecuencias.** `ServiceViewRepositoryInterface` es la frontera: los controladores que solo
leen no dependen de Doctrine. `ServiceView` es inmutable y se construye desde la entidad, de modo
que el read model nunca escribe.

## D-51 — El conocimiento del buzón manda sobre el catálogo global

**Estado:** aceptada.

**Contexto.** Hay dos sitios donde puede estar la información de qué proveedor es un remitente: el
**catálogo global** (`ProviderIdentity`, compartido por todos los buzones) y el **conocimiento del
buzón** (`MailboxKnowledgeEntry`, privado de una cuenta de correo). Los dos existen porque
responden a preguntas distintas: el catálogo dice «este remitente es este proveedor para todo el
mundo», y el conocimiento del buzón dice «en _mi_ correo, este remitente es _este_ proveedor».

El conflicto es real: un usuario puede tener un correo de `facturacion@miempresa.com` que para el
catálogo no es nadie y que en su buzón es su proveedor deIsso. O al revés: el catálogo dice que
`newsletter@proveedor.com` es el proveedor X, y el usuario sabe que en su buzón son newsletters
que no quiere pagar por entender.

**Decisión.** Cuando ambos dicen algo y no coinciden, **manda el conocimiento del buzón**. Es el
conocimiento más específico (está a un solo paso del correo concreto), el más reciente y el que el
usuario puede ver y corregir en la pantalla de conocimiento. El catálogo global sigue siendo el
punto de partida para buzones que no han aprendido nada, y para los buzones de otros usuarios.

**Alternativas.**

- _El catálogo global siempre gana._ Descartado: obligaría al usuario a corregir un catálogo que
  es de todos para resolver un problema que es suyo, y no offería ninguna forma de salir de
  ahí.
- _Preguntar al usuario cuando chocan._ Descartado por coste: convertir cada correo ambiguo en
  una pregunta es exactamente lo que el producto evita. El desacuerdo se resuelve solo, a favor
  de lo específico, y el usuario puede corregirlo cuando quiera.
- _Fusionar los dos._ Descartado: son datos de alcances distintos. Un patrón de asunto útil en un
  buzón no es automáticamente útil en otro, y compartirlo automáticamente filtraría información
  de un usuario a los demás.

**Consecuencias.** `ResolveMailboxKnowledge` se consulta **antes** que el catálogo para el
proveedor, el tipo de documento y la periodicidad. La promoción al catálogo global es la única
vía para que lo aprendido de un buzón afecte a otro, y es siempre explícita (D-54).

## D-52 — La palanca que más IA ahorra es la periodicidad aprendida

**Estado:** aceptada.

**Contexto.** El pipeline escala a IA cuando el documento no es accionable. Un documento es
accionable cuando tiene importe y periodicidad: sin periodicidad no se puede calcular el próximo
cobro, y sin próximo cobro el producto no tiene nada que ofrecer. Un correo de factura sin
palabras como «mensual» o «trimestral» en el texto es, por tanto, el caso que más IA consume.

**Decisión.** La periodicidad aprendida del buzón es el dato que más IA ahorra, y por eso tiene
prioridad en la cadena de extracción:

1. el **texto del correo** manda: si la DeterministicExtractor encuentra la periodicidad, esa es;
2. si no la encuentra, se usa la **periodicidad aprendida** del buzón (`MailboxKnowledge.billingPeriod`),
   y solo si es recurrente;
3. solo si tampoco hay periodicidad se escala a IA.

**Alternativas.**

- _Usar la IA siempre que el documento no sea accionable._ Es lo que hacía el sistema antes de
  esta fase: correcto, pero caro. Con la periodicidad aprendida, la segunda factura del mismo
  proveedor se resuelve sin gastar un token.
- _Aprender la periodicidad y aplicarla siempre, aunque el texto diga otra cosa._ Descartado:
  el texto es la fuente directa y un correo puede traer un plan distinto al habitual. Lo aprendido
  rellena huecos, no pisa lo que el correo dice.
- _Guardar la periodicidad como un dato del servicio y no del buzón._ Descartado: el servicio es
  la conclusión, y la conclusión puede ser incorrecta. El buzón es donde vive la observación.

**Consecuencias.** La memoria de extracción (`rawSignals`) registra `billingPeriodSource:
mailbox_knowledge` cuando el dato viene de lo aprendido, de modo que se puede medir cuánto se
ahorra. La confianza del documento sube cuando hay periodicidad aunque el correo no se haya
podido clasificar.

## D-53 — Descartar un remitente lo excluye al segundo descarte, no al primero

**Estado:** aceptada.

**Contexto.** Cuando el usuario descarta una propuesta, el sistema anota que ese remitente no es
lo que cree. Pero un solo descarte puede ser un error —una factura que se parecía a otra cosa—
y excluir el remitente en ese momento perdería facturas reales.

**Decisión.** Cada descarte **suma 30 puntos de confianza** a la regla de remitente ignorado. El
umbral de exclusión es **60**: el primer descarte deja el remitente en Observation (60 % de
confianza, se sigue mirando pero con|score bajo), el segundo lo excluye. El usuario puede
excluirlo de inmediato desde la pantalla de conocimiento, donde la confianza se fija en 100.

**Alternativas.**

- _Excluir al primer descarte._ Descartado: castiga el error. Un solo descarte no es evidencia
  suficiente para dejar de leer el correo de un proveedor.
- _Excluir siempre al primer descarte pero con la regla de los dos descartes._ Lo mismo que lo
  anterior con más pasos.
- _No contar los descartes._ Descartado: el usuario repetiría el gesto en cada correo del mismo
  remitente sin que el sistema aprenda nada.

**Consecuencias.** `RecordSenderRejection` es idempotente en la confianza (nunca baja sola) y
`MailboxKnowledgeEntry::excludes()` centraliza el umbral. El descarte sigue siendo reversible:
borrar la regla de ignorado devuelve el remitente al circuito.

## D-54 — Promover al catálogo global es explícito, nunca automático

**Estado:** aceptada.

**Contexto.** El conocimiento del buzón es privado: describes _tus_ correos. El catálogo global es
de todos. Saltar de uno a otro automáticamente filtraría información de un usuario a los demás:
un remitente raro, una plantilla de adjunto, un dominio de una empresa concreta.

**Decisión.** La promoción ocurre **solo cuando el usuario la pide**, con un botón propio en la
pantalla de conocimiento, y con dos restricciones:

- solo hacia un proveedor **global** (nunca hacia un proveedor de la organización, que es
  privado por definición);
- solo desde tipos que identifican a un proveedor (`sender_mapping`, `provider_hint`).

Al promover se crea una `ProviderIdentity` global de tipo `SENDER` (si el valor es una dirección)
o `DOMAIN` (si es un dominio), con origen `USER`.

**Alternativas.**

- _Promover automáticamente lo que se repite._ Descartado: «se repite» no significa «vale para
  los demás», y el coste de filtrar datos entre usuarios no se puede recuperar.
- _Un interruptor «compartir lo que aprendo»._ Descartado: es la misma promoción automática con
  un interruptor, solo que más difícil de entender.
- _Promover hacia el catálogo de la organización._ Descartado: no es el catálogo global, y
  mantener dos niveles de promoción multiplicaría el modelo sin necesidad.

**Consecuencias.** `PromoteMailboxKnowledge` es la única puerta y rechaza con un error explícito
lo que no cumple las reglas. Lo promovido queda marcado (`isPromoted`) y se muestra como tal en la
pantalla, para que el usuario vea qué de lo suyo ya afecta a otros.

## D-55 — El presupuesto guarda la política, no el gasto

**Estado:** aceptada.

**Contexto.** La primera versión de `AiBudget` llevaba dos cosas: el tope y un contador de lo
gastado. El contador se incrementaba al registrar cada llamada, y el tope se comparaba con él
antes de permitir la siguiente.

Eso son **dos verdades** sobre la misma cifra. Si una escritura fallara —una caída entre el
incremento y el guardado, una transacción deshecha, un reintento del worker— el contador y la
suma real de las filas de `ai_usage` dejarían de coincidir. Y la que manda, la suma real, solo
se podía calcular agregando miles de filas.

**Decisión.** `AiBudget` persiste **solo la política**: el tope mensual y el modo de agotamiento.
El gasto sale siempre del **libro mayor** (`ai_usage`), que es la fuente de verdad: cada llamada
deja una fila con su coste real, y el gasto del mes es la suma de esas filas.

La fila de presupuesto es una por organización y periodo (`YYYY-MM`), con índice único. El
presupuesto de marzo y el de abril son dos filas, no una fila con dos columnas.

**Alternativas.**

- _Guardar el gasto en la fila de presupuesto._ Descartado: es una segunda verdad, y además
  obliga a actualizar una fila por cada llamada, que es justo lo que hace que se desincronice.
- _Calcular el gasto contando filas sin agregar._ Descartado: es O(n) por llamada de IA, y el
  número de llamadas crece con el uso.
- _No guardar nada y calcular el presupuesto al vuelo._ Descartado: el tope es una **decisión**,
  no un dato deducible. Sin fila, no hay política que guardar.

**Consecuencias.** El guard de presupuesto lee dos cosas: la política (una fila) y el gasto (una
agregación). `AiUsageRepository::totalCostMinor()` es la única fuente del gasto. Si algún día
querría un histórico de tops de presupuesto, la serie se reconstruye desde el libro mayor, que ya
guarda cada llamada.

## D-56 — Agotar el presupuesto degrada o para, y el modo lo decide el usuario

**Estado:** aceptada.

**Contexto.** «Se ha acabado el presupuesto de IA» admite dos respuestas razonables, y ninguna
es universalmente correcta:

- **Degradar**: seguir usando el modelo barato, apagar el caro, y que el pipeline continúe por
  reglas. Se procesa más correo y se gasta algo.
- **Parar**: no llamar a ningún modelo. Se gasta cero, y el correo que necesita IA espera.

La primera es mejor para el usuario que quiere que sus facturas se detecten; la segunda es mejor
para quien tiene el coste bajo control absoluto.

**Decisión.** El modo lo elige la organización, por mes, en la pantalla de ajustes de IA:

- **`SOFT`** (por defecto): el pipeline sigue funcionando. Se permite el nivel 5 (IA económica)
  y **se apaga el nivel 6** (IA avanzada), que es el caro. El correo que se puede resolver con
  reglas o con el modelo barato se resuelve; el que no, va a revisión manual.
- **`HARD`**: no se llama a ningún modelo. El correo que **necesitaba** IA no va a revisión
  manual —que sería mentir, porque no está mal, está esperando— sino que queda en `DEFERRED`, con
  el motivo `budget_exhausted`, y se reintenta cuando hay presupuesto.

El guard se comprueba **antes** de cada llamada, nunca después: un `SOFT` agotado no puede
«colarse» una llamada cara.

**Alternativas.**

- _Un solo comportamiento (parar siempre)._ Descartado: una organización con un tope bajo
  dejaría de detectar facturas, que es justo lo que ha pagado.
- _Degradar siempre._ Descartado: quien fija un tope de 0 € quiere que no se gaste, y el
  sistema no puede inferir esa intención.
- _Un tope único con el modo implícito en el importe._ Descartado: el importe no dice qué
  hacer; un tope de 5 € y uno de 500 € pueden significar cosas distintas según la organización.

**Consecuencias.** `AiBudgetMode` es parte de la política guardada. `AiBudgetGuard::allowsCall()`
responde a «¿puedo llamar?» y `allowsTier()` a «¿puedo llamar a _este_ nivel?», que es la
pregunta que hace el nivel 6. El tope por defecto es 500 céntimos (5,00 €) en modo `SOFT`, y
`0` significa «sin tope».

## D-57 — La escalera de IA devuelve un motivo, no un `null`

**Estado:** aceptada.

**Contexto.** La puerta de IA devolvía `?ExtractedDocument`: un documento o nada. El `null`
significaba cuatro cosas distintas —la IA está apagada, no hay proveedor elegido, se agotó el
presupuesto, el proveedor falló— y el pipeline las mandaba **todas** a revisión manual.

Eso está bien para tres de las cuatro y está **muy mal** para la cuarta. Un correo aplazado por
presupuesto no está mal: está esperando. Mandarlo a revisión manual gasta el trabajo de una
persona en algo que se va a resolver solo en cuanto se reponga el presupuesto, y lo esconde en
la misma pila que los casos que sí necesitan criterio humano.

**Decisión.** La escalera de IA devuelve un `AiExtractionOutcome` con el documento (o no) y un
`AiSkipReason` explícito:

| Motivo             | Significado                           | Qué hace el pipeline             |
| ------------------ | ------------------------------------- | -------------------------------- |
| `NONE`             | Hay documento                         | Se usa                           |
| `NOT_CONFIGURED`   | No hay IA activada o no hay proveedor | Revisión manual                  |
| `NO_TEXT`          | El correo no tiene texto que analizar | Revisión manual                  |
| `NOT_NEEDED`       | La escalera no tenía nada que hacer   | Revisión manual                  |
| `FAILED`           | Se llamó al proveedor y falló         | Revisión manual                  |
| `RATE_LIMITED`     | Se pasó el límite de peticiones       | Revisión manual (se reintentará) |
| `BUDGET_EXHAUSTED` | No hay presupuesto                    | **`DEFERRED`**                   |

Solo `BUDGET_EXHAUSTED` aplaza el correo. Los demás van a revisión manual, que es lo que
corresponde: son casos que necesitan una decisión.

**Alternativas.**

- _Devolver `null` y que el pipeline pregunte por separado si el presupuesto está agotado._
  Descartado: el pipeline tendría que consultar el guard, es decir, el módulo de IA, para
  distinguir dos `null` que significan cosas distintas. La razón tiene que viajar con el
  resultado.
- _Añadir un estado `REQUIRES_REVIEW` con submotivo._ Descartado: el estado de la máquina dice
  **dónde** está el correo, no **por qué**. Mezclarlos obliga a duplicar estados.
- _Un estado nuevo `AWAITING_BUDGET`._ Descartado: `DEFERRED` ya dice exactamente eso, y el
  motivo va en el evento de transición, que es donde se consulta cualquier historial.

**Consecuencias.** `AiExtractionInterface::enrich()` devuelve `AiExtractionOutcome`, y
`AiExtractionOutcome::defersMessage()` es la única puerta al estado `DEFERRED`. El motivo
viaja en el evento de transición (`skip_reason`), de modo que se puede responder «¿por qué este
correo está esperando?» sin volver a mirar el presupuesto.

## D-58 — El módulo de IA no sabe qué quedó a medias en el pipeline

**Estado:** aceptada.

**Contexto.** Al subir el tope del presupuesto hay que **reactivar** los correos que quedaron
aplazados: en el mes nuevo, con presupuesto, tienen que volver a la cola.

El guard de presupuesto vive en el módulo `Ai`, y los correos aplazados viven en `Mailbox` y en
`Processing`. La Tentación es que `Ai` busque los correos `DEFERRED` y los devuelva al pipeline.
Eso rompe la regla de dependencias (§3 de `ARCHITECTURE.md`): un módulo no toca las entidades
de otro.

El otro extremo tampoco vale: que `Processing` esté pendiente del guard de presupuesto para
preguntar cada vez si el presupuesto ha cambiado mezcla dos cosas que no tienen relación —la
política de IA y el estado del pipeline— y obliga a que la comprobación sea un sondeo.

**Decisión.** El módulo `Ai` **declara un puerto** y no lo implementa:

```php
interface AiBudgetChangeHandlerInterface
{
    public function onBudgetRaised(): int;
}
```

`Processing` lo implementa (`ReleaseDeferredMessages`) y sabe qué hacer con un correo
aplazado: pasarlo de `DEFERRED` a `CANDIDATE` y reencolarlo. `Ai` solo llama al puerto.

La dependencia va **en un solo sentido**: `Processing → Ai`. `Ai` no depende de `Processing`, y
el ciclo que aparezca en el mapa de módulos se rompe con esta interfaz.

**Alternativas.**

- _Que `Ai` consulte el repositorio de mensajes._ Descartado: viola la regla de dependencias y
  además hace que el módulo de IA sepa qué es un estado de procesamiento.
- _Que un comando de la aplicación sea el que avise a los dos._ Descartado: el aviso dejaría de
  ser fiable (depende de que alguien ejecute el comando) y además seguiría haciendo falta una
  dependencia en algún sitio para preguntar «¿ha cambiado el presupuesto?».
- _Un evento de Messenger (`BudgetRaised`)._ Descartado por ahora: el evento es asíncrono y la
  liberación manual del panel necesita una respuesta inmediata («cuántos correos he puesto en
  cola»). Una llamada directa con puerto es más simple y predecible. Si más adelante hace falta
  desacoplar, el puerto ya es el sitio natural donde cambiar.

**Consecuencias.** `Ai` declara lo que espera del exterior; `Processing` decide qué hacer con
ello. La pantalla de coste puede ofrecer un botón de «poner en cola los correos aplazados»
porque la operación existe y es idempotente.

## D-59 — Una inferencia se marca como tal, y su confianza no puede subir

**Estado:** aceptada.

**Contexto.** Hasta la fase 14, todo lo que había en la bandeja de descubrimientos venía de un
documento: el sistema lo había **leído**. La fase 15 introduce otra fuente —el inventario de
servicios— y con ella propuestas que el sistema **deduce**: un posible duplicado, un cobro que no
ha llegado, un servicio que lleva meses sin dar señales.

El riesgo no es que la deducción sea mala; es que se **presente como una verdad**. Si la bandeja
mezcla «la factura de Netflix dice 12,99 €» con «creo que te has dado de alta dos veces en
Netflix», el usuario no puede saber cuál de las dos merece su atención, y acaba Estate
-ignorando las dos. Peor: un detector se equivoca con frecuencia, y si sus errores se presentan
como hechos, el usuario pierde la confianza en el sistema entero.

**Decisión.** Hay dos clases de propuesta y se distinguen **de forma explícita**:

1. **La marca `is_inference`.** Vive en una **columna** (`discovery.is_inference`), no en el JSON de
   `proposed_data`, porque la interfaz la necesita para dos cosas a la vez: **filtrar** la bandeja
   (`?scope=facts` / `?scope=suggestions`) y **presentar** la propuesta como sugerencia. Un filtro
   sobre un JSON sin índice es un filtro en memoria sobre toda la tabla.
2. **El recorte de la confianza.** `markAsInference()` baja la confianza a la banda `LOW`
   (`DiscoveryConfidence::LOW->maxScore() = 54`) si hace falta. Una inferencia **nunca** llega a
   confianza alta, por muchas señales que la respalden: el detector puede estar seguro de tener
   una sospecha, pero una sospecha sigue siendo una sospecha.
3. **La presentación.** La ficha de una inferencia dice «Esto es una sugerencia, no un hecho»,
   explica **de dónde sale** («no viene de un correo») y enseña la **fuerza de la señal** en vez de
   la puntuación de emparejamiento. Un hecho, en cambio, sigue mostrando el formulario de alta
   del servicio, porque un hecho se actúa; una sospecha se confirma o se descarta.

**Alternativas.**

- _Confiar en el texto de la propuesta._ Descartado: el usuario lee el texto, pero el sistema tiene
  que poder **filtrar** y **priorizar**. La distinción tiene que ser consultable.
- _Bajar la confianza a un número fijo (por ejemplo, 50)._ Descartado: el recorte tiene que ser
  una **banda** con nombre, porque la banda es lo que la interfaz sabe pintar y lo que los filtros
  saben interpretar. Un número suelto se volvería a subir por accidente en la siguiente fase.
- _No distinguir, y que el usuario lo adivine por el texto._ Descartado: es exactamente el
  problema. El sistema tiene que saber qué es deduced y qué es leído, y decirlo.

**Consecuencias.** La bandeja tiene dos filtros («Familia»: todas / hechos / sugerencias) y las
propuestas inferidas llevan la insignia «Sugerencia». El pipeline sigue siendo el único que crea
**hechos**; el módulo `Discovery` es el único que crea **inferencias**, y solo mediante el barrido.
Los avisos que cuelgan de una propuesta pendiente se mantienen abiertos mientras el usuario no
decida: `resolveStaleAlerts()` trata «la propuesta sigue pendiente» igual que «el problema sigue
sin resolverse».

## D-60 — Cancelar no es borrar: confirmar una inferencia siempre deja rastro

**Estado:** aceptada.

**Contexto.** Confirmar una inferencia significa **mutar el inventario**: cancelar un servicio
duplicado, adelantar el próximo cobro de un servicio al que no le ha llegado la factura, cancelar
un servicio que lleva meses muerto. Es la primera vez que el sistema **borra** algo del punto de
vista del usuario, aunque internamente no borre: cancela.

La tentación es implementarlo como un borrado: quitar el servicio, quitar su historial, quitar sus
documentos. Sería más rápido de escribir y más fácil de explicar. Pero el usuario no ve «un
registro obsoleto que sobraba»: ve **su** historial. Y el historial es la única explicación que
tiene de por qué tiene lo que tiene. «¿Por qué me aparece esto?» se responde con los eventos.

**Decisión.** `ConfirmInference` es el **único** punto del sistema que aplica una inferencia, y
aplica una regla sin excepciones: **la historia no se borra**.

- **Duplicado** → se cancela el servicio **más reciente** (el más antiguo es el original) y se
  deja una nota (`NOTE_ADDED`) **en los dos**. Los dos servicios quedan con su historial.
- **Cobro que no ha llegado** → se adelanta `nextChargeAt` al siguiente periodo (con tope de 200
  periodos) y se deja nota. El servicio **no** se cancela: puede que la factura llegue mañana.
- **Servicio inactivo** → se cancela y se deja nota.

Cancelar es un **estado** del servicio (`ServiceStatus::CANCELLED`), no su ausencia. El evento
`CANCELLED` dice qué pasó y cuándo, y la nota dice por qué. Toda la operación queda registrada en
la auditoría (`AuditAction::DISCOVERY_CONFIRMED`, con `metadata['inference'] = true`).

**Alternativas.**

- _Borrar el servicio duplicado y sus facturas._ Descartado: el usuario pierde el histórico de lo
  que pagó y de lo que le cobraron. Es la clase de pérdida que hace que alguien deje de confiar en
  la herramienta.
- _Cancelar el servicio más antiguo en vez del más reciente._ Descartado: el original es el que
  tiene los eventos, las facturas y el `nextChargeAt` acumulado. Cancelar el más antiguo tiraría
  justo la información que el usuario necesita.
- _Un «deshacer» para el duplicado._ Descartado por ahora: no se ha pedido, y el historial ya
  permite reconstruir qué pasó. Añadir un deshacer es una promesa de más complejidad; si se echa de menos,
  el evento `CANCELLED` es el punto de apoyo natural.

**Consecuencias.** El detector es **idempotente** y el barrido se puede lanzar cada noche: la
clave de deduplicación es estable (`serviceId|expectedAt`, `serviceId|inactiveSince`, pareja
ordenada de ids) y `findByDedupKey()` busca en **cualquier** estado, así que una sospecha que el
usuario descartó no vuelve a aparecer. Los umbrales que decide qué es una sospecha viven en la
configuración (`config/packages/discovery.yaml` → `ChangeSettings`) y no en el código, porque
cambiarlos cambia lo que el usuario ve, y eso se decide con el producto al lado.

## D-61 — Borrar la organización se lleva la organización

**Contexto.** El derecho de supresión del RGPD dice que los datos personales se borran cuando
el usuario lo pide. En Suscripta hay una decisión debajo de esa: **¿se borran los datos de la
organización o se borra la organización?**

La tentación es borrar solo los datos y dejar la organización vacía. Conserva la cuenta del
usuario, el plan, los ajustes y la facturación, y parece menos agresivo.

El problema es que «los datos de la organización» incluyen cosas que **no se pueden dejar
colgadas de un `organization_id` huérfano**: las credenciales cifradas de las cuentas de
correo, los binarios de los documentos, los extractos de cuerpo, el conocimiento aprendido del
buzón y los registros de uso de IA. Si la organización sobrevive al borrado, todo eso sigue ahí,
se puede recuperar por consulta directa y el derecho de supresión no se ha cumplido: solo se
ha hecho invisible en la interfaz.

**Decisión.** `DeleteOrganizationData` borra los datos **y la organización**. El usuario se
queda sin organización, y el panel lo lleva a crear una nueva (`/organization/new`,
`CreateOrganization`). `ActiveOrganizationListener` cae al primer `memberships[0]` cuando la
organización de la sesión ya no le pertenece, de modo que la aplicación nunca queda en un
estado imposible («tengo sesión pero no tengo organización»).

El borrado es **completo y en cascada**: servicios, documentos y sus binarios, correos y
eventos de procesamiento, conocimiento del buzón, propuestas, avisos, integraciones
(credenciales), uso de IA y datos de identidad. La exportación de datos (JSON/CSV) está
disponible **antes** del borrado, con limitador de frecuencia por organización.

**Alternativas.**

- _Borrar solo los datos y dejar la organización._ Descartado: deja credenciales y binarios
  recuperables bajo un identificador que ya no apunta a nada, y el «borrado» solo sería cierto
  en la interfaz.
- _Conservar los datos agregados (importes, conteos) para métricas._ Descartado: se conservan
  los **importes agregados de uso de IA**, que no son personales y sí sirven para las métricas
  del panel. Las filas de detalle sí se borran.
- _Reclamar la organización si el usuario tiene otras._ Descartado: complica el modelo de
  pertenencias sin ganar nada. Crear una organización nueva es una acción de un clic.

**Consecuencias.** El borrado es irreversible y no admite «deshacer»: es lo que corresponde a
una supresión, y el usuario tiene la exportación por delante. La creación de una organización
pasa a ser una pantalla de primer nivel (no un caso raro), con su propio caso de uso, su
formulario y su entrada en la navegación. La auditoría registra la supresión con su detalle
por módulo (`RemovalReport`).

## D-62 — La política de retención se configura por categoría de dato

**Contexto.** Cada módulo tiene datos con sensibilidades distintas: el correo es evidencia
temporal, los servicios son el producto, el libro de auditoría es trazabilidad, la caché de
extracción es un simple ahorro de trabajo. Retenerlos todos igual, o borrar todos por el mismo
plazo, es incorrecto o inútil.

La otra tentación es dejar los plazos como constantes en el código de cada módulo. Entonces
cambiar un plazo es un despliegue, y el plazo es una decisión de **producto y de cumplimiento**,
no de implementación: hay que poder cambiarlo sin tocar código y sin auditar un diff de PHP.

**Decisión.** Los plazos viven en `config/packages/privacy.yaml` y se interpretan en
`PrivacySettings`. Se declaran **por categoría de dato**, no por módulo, porque lo que
expira es la naturaleza del dato (evidencia temporal, producto, trazabilidad, caché), no el
módulo donde vive.

| Categoría                                    | Plazo    |
| -------------------------------------------- | -------- |
| Correo (mensajes y eventos de procesamiento) | 365 días |
| Documentos (binario tras el borrado lógico)  | 30 días  |
| Propuestas ignoradas                         | 90 días  |
| Avisos resueltos                             | 365 días |
| Libro de auditoría                           | 730 días |
| Uso de IA                                    | 730 días |
| Conocimiento del buzón                       | 365 días |
| Caché de extracción                          | 30 días  |

**Alternativas.**

- _Constantes en el código._ Descartado: cambia la política de retención exige un despliegue.
- _Un plazo único para todo._ Descartado: o se purga antes de tiempo (se pierde trazabilidad) o
  se conserva demasiado (no se cumple la política).
- _Plazos por módulo._ Descartado: el mismo tipo de dato (los correos) vive en dos módulos y
  tendría dos plazos, que divergirían.

**Consecuencias.** La purga se ejecuta con `app:privacy:purge`, es idempotente y va en lotes
(PostgreSQL no admite `LIMIT` en un `DELETE`). Cada categoría tiene su propio purger
(`RetentionPurgerInterface`), así que añadir una categoría nueva es añadir un purger, no tocar
la orquestación.

## D-63 — Los módulos publican puertos de privacidad; el agregador no los conoce

**Contexto.** Borrar una organización o exportar sus datos afecta a siete módulos. La
implementación ingenua sería que el agregador (`src/Privacy/Application/`) importara los
repositorios de cada módulo: sería el módulo más dependiente del sistema, y cada módulo
nuevo obligaría a tocar el agregador.

**Decisión.** Se invierte el sentido de la dependencia: **el consumidor declara el puerto y el
proveedor lo implementa**. Los tres puertos viven en `src/Shared/Application/Privacy/`:

- `OrganizationDataProviderInterface` — qué exporta el módulo y cuántas filas tiene.
- `OrganizationDataRemoverInterface` — cómo borra el módulo sus datos.
- `RetentionPurgerInterface` — cómo purga el módulo por antigüedad.

Cada módulo implementa los que le tocan en `src/<Módulo>/Application/Privacy/`, y los
agregadores (`ExportOrganizationData`, `DeleteOrganizationData`, `PurgeExpiredData`) solo
conocen los puertos.

**Alternativas.**

- _El agregador importa los repositorios._ Descartado: crea un nodo que depende de todos los
  módulos y que hay que tocar cada vez que se añade uno.
- _Un evento de dominio «borrar organización» al que cada módulo se suscriba._ Descartado:
  introduce orden de ejecución implícito y difficulta de garantizar que todos los
  suscriptores se han ejecutado (y que han terminado) antes de responder.
- _Un servicio de dominio gigante con un método por módulo._ Descartado: es el mismo problema
  con otro nombre.

**Consecuencias.** Añadir un módulo al sistema es añadir sus implementaciones de los puertos,
sin tocar la orquestación. El coste es que los puertos están en `Shared`, que es el módulo
transversal: es exactamente su papel (el kernel que todos comparten), y no introduce
dependencias entre módulos de negocio.

---

## D-64 — El plan es un conjunto de límites en configuración, no una etiqueta comercial

**Contexto.** La Fase 17 introduce los planes (gratis / profesional). La tentación es
modelar el plan como un enum de PHP con los números metidos dentro (`case FREE => 3
servicios`), porque es lo habitual. Pero un número de producto cambia: subir el precio de un
plan o dar margen a un cliente no debería exigir un despliegue.

**Decisión.** El plan es un **enum de nombres** (`OrganizationPlan::FREE`, `::PRO`) que dice
_qué planes existen_, y los **límites viven en configuración**
(`config/packages/entitlements.yaml`). Cada capacidad se declara con su tipo:

- `limit`: un número. `null` = sin techo; `0` = la capacidad no está disponible.
- `flag`: un interruptor (hoy, la disponibilidad de la inteligencia artificial).

La comprobación de que **todos los planes declaran todas las capacidades** la hace
`EntitlementSettings` al construirse: si alguien añade una capacidad al código y olvida
meterla en la configuración, el contenedor no arranca. Es un error de despliegue, visible el
primer día, y no un límite que desaparece en silencio.

**Alternativas.**

- _Los números en el enum de PHP._ Descartado: cambiar un precio exigiría un despliegue, y
  el enum quedaría mezclando dos cosas que cambian por motivos distintos (el catálogo de
  planes y sus precios).
- _Los números en la base de datos._ Descartado por ahora: haría falta una tabla de planes
  editable en caliente, y no hay quien la edite. Cuando los planes se vendan en autoservicio,
  esta decisión se revisará (ver D-65).
- _Sin planes, solo cuotas por organización._ Descartado: no permite distinguir «gratis» de
  «pago» y obliga a codificar la lógica comercial en cada módulo.

**Consecuencias.** Los límites se cambian sin desplegar. La configuración es la única fuente
de la verdad, y `PlanEntitlementChecker` no guarda estado: dos peticiones concurrentes nunca
ven valores distintos. El enum sigue siendo la garantía de que un plan desconocido es un
error al leer la fila y no un comportamiento silencioso.

---

## D-65 — Cambiar de plan es un caso de uso auditado, y no borra nada

**Contexto.** Alguien contrata un plan: hay que cambiar el `plan` de la organización. La
forma rápida es un `UPDATE organization SET plan = 'pro'`. Pero el plan decide si hay
inteligencia artificial, cuántos servicios caben y cuántos buzones, y eso es una decisión de
negocio con consecuencias.

**Decisión.** El cambio pasa por el caso de uso `ChangeOrganizationPlan`, que:

1. Comprueba que el plan de destino **existe** en la configuración de límites. Un plan que no
   está en `entitlements.yaml` no es un plan: es una errata, y aceptarla dejaría a la
   organización sin límites definidos.
2. Deja constancia en el libro de auditoría (`ORGANIZATION_PLAN_CHANGED`) con el plan
   anterior y el nuevo.

Y, sobre todo, **no borra nada**: bajar de plan deja intactos los servicios, los documentos y
los avisos; la organización simplemente no puede añadir más hasta que vuelva a caber.

**Alternativas.**

- _Borrar lo que no cabe al bajar de plan._ Descartado: es una sorpresa muy desagradable
  (el cliente pierde datos por un cambio comercial que él no pidió) y sería irreversible sin
  un botón de «deshacer» que no existe.
- _Dejar el plan editable desde el panel._ Descartado **por ahora**: en esta versión no hay
  cobro, así que un botón «cambiar a profesional» sería regalar el plan de pago. El cambio lo
  hace el operador de la instalación con `app:plan:set`, y la pantalla `/plan` solo informa.
  Cuando exista el cobro, esta decisión se revisa.
- _Guardar el historial de planes en una tabla aparte._ Descartado: el libro de auditoría ya
  guarda el antes y el después, con actor y fecha.

**Consecuencias.** El cambio de plan es auditable y no destructivo. En la interfaz web se ve
que el límite se ha alcanzado, con un aviso que explica qué hacer; el operador lo resuelve
por consola. La Fase 18 (API pública) usará el mismo caso de uso, de modo que un cliente
integrado tampoco puede saltarse el plan.

---

## D-66 — La guía de bienvenida se calcula en vivo; solo se guarda el final

**Contexto.** Una organización nueva entra en el producto y no sabe qué hacer. La guía de
bienvenida puede guardar «paso 2 de 3» en la base de datos, o puede deducirlo del estado real
del producto.

**Decisión.** Los pasos se **calculan en vivo** a partir del estado del producto (¿hay buzón?
¿hay servicios? ¿el plan ofrece IA?). Lo único que se guarda es «la guía se ha terminado»,
que es un hecho del pasado y no un estado del presente.

La guía **no bloquea** la aplicación: `OnboardingRedirectListener` solo redirige a la guía
desde el panel (`app_dashboard`). El resto de pantallas siguen accesibles.

**Alternativas.**

- _Guardar el paso actual en la base de datos._ Descartado: mentiría en cuanto el usuario
  conecta el buzón desde otra pantalla (o desde la API en el futuro). Un «paso 2 de 3» guardado
  es un dato que se queda viejo solo.
- _Bloquear la aplicación hasta terminar la guía._ Descartado: un cliente que ya tiene sus
  datos cargados y solo quiere consultar una factura no podría ni mirar su factura. El
  objetivo de la guía es que el primer impacto sea una explicación, no un muro.
- _Guardar los pasos en configuración._ Descartado: el contenido de la guía cambia con el
  producto (por ejemplo, el paso de la IA desaparece si el plan no la ofrece), y una lista
  configurable se desincroniza del código en cuanto se añade una capacidad.

**Consecuencias.** La guía nunca miente. El paso de la inteligencia artificial **no aparece**
en los planes que no la ofrecen: no hay nada que hacer y ofrecerlo sería una promesa que el
producto no puede cumplir.

---

## D-67 — La demostración se siembra con el módulo `Demo`, y solo en desarrollo

**Contexto.** Para enseñar el producto hace falta una cuenta con datos: servicios, precios,
propuestas, avisos, correo, propuestas de cambio. Escribirlos a mano cada vez es una pérdida
de tiempo, y dejarlos en la base de datos de desarrollo es una forma de tener datos falsos
sin explicación.

**Decisión.** El módulo `src/Demo/` siembra la cuenta de demostración. Sus credenciales
vienen de la configuración (`DEMO_ACCOUNT_EMAIL`, `DEMO_ACCOUNT_PASSWORD`), no de constantes
en el código. El comando `app:demo:seed` está **prohibido fuera de desarrollo** salvo
`--force`, y la credencial se muestra en la pantalla de login **solo en `dev`**: la
comprobación es del entorno, no de un flag, para que no quepa el error de dejar el flag
puesto en producción.

La siembra del buzón se hace **con las entidades**, no con los casos de uso de correo: los
casos de uso hablarían con un servidor IMAP de verdad, y la demostración no puede depender de
que haya un servidor de correo de mentira.

**Alternativas.**

- _Un fixture de Doctrine._ Descartado: los fixtures no son idempotentes de forma útil (crean
  duplicados si se repiten) y no participan del aislamiento por organización, que en este
  producto no es opcional.
- _Un script SQL._ Descartado: se desincroniza del esquema en cuanto cambia una entidad.
- _Dejar la cuenta de demo en la base de datos de desarrollo._ Descartado: nadie sabe qué
  datos son reales y cuáles son de mentira.

**Consecuencias.** `bin/console app:demo:seed` deja la aplicación en un estado que se puede
enseñar en un minuto. Es idempotente: repetirla no duplica nada. La excepción a la regla de
módulos (`src/Demo` es un módulo compositor que escribe entidades de otros módulos) queda
documentada en `ARCHITECTURE.md` §16.6.

---

## D-68 — El coste de IA se guarda en microcéntimos

**Contexto.** El coste de una llamada de IA es de milésimas de céntimo. Guardarlo en céntimos
—como el resto de los importes del producto— obliga a redondear a 0 y a perder el dato: el
panel de coste enseñaría «0,00 €» para un gasto real de 0,03 €, que es exactamente el gasto
que un usuario de un plan gratis quiere ver.

**Decisión.** El coste de IA se persiste en **microcéntimos** (1 € = 1 000 000 microcéntimos)
en un entero de 64 bits: `AiUsage.cost_micro` (BIGINT) y el value object `AiCost`, que sabe
convertirse a céntimos, a euros y a texto. El filtro de Twig `micro_money` presenta el
resultado con la coma decimal española.

**Alternativas.**

- _Céntimos con decimales (NUMERIC)._ Descartado: el producto guarda los importes en enteros
  de céntimos por decisión anterior; tener dos representaciones del dinero en la misma base de
  datos es una fuente de errores de redondeo.
- _Guardar el coste en la moneda del proveedor y convertir al presentar._ Descartado: los
  proveedores cobran en varias monedas y el tipo de cambio cambia; el gasto registrado debe
  ser inmutable.
- _Redondear a céntimos._ Descartado: pierde el dato justo en el rango que importa.

**Consecuencias.** El panel de coste de IA y el de observabilidad enseñan el gasto real. La
migración `Version20261023090000` convierte la columna y reescribe los importes existentes.

---

## D-69 — Un enum mapeado por Doctrine no puede ser clave de array

**Contexto.** Al construir el panel de observabilidad, las agregaciones DQL devolvieron los
enums de `ExtractionTier` como **objetos**, no como cadenas. Al usarlos como claves de array
(`$counts[$tier->value]`), PHP lanza un `TypeError`: «Illegal offset type».

**Decisión.** Cuando un enum viene de una agregación DQL, se convierte explícitamente antes de
usarlo como clave: `$tier instanceof ExtractionTier ? $tier->value : (string) $tier`. El
patrón se repite en `EmailMessageRepository` y en `BuildObservabilityReport`.

**Alternativas.**

- _Marcar la columna como `enumType` y esperar que DQL devuelva cadenas._ Descartado: es
  exactamente lo que no hace; Doctrine devuelve la instancia del enum.
- _Usar `->value` a ciegas._ Descartado: falla con el mismo `TypeError` cuando el valor llega
  como string, que es lo que ocurre en algunos caminos.
- _Forzar el tipo en la consulta con un `CASE`._ Descartado: ensucia el DQL y esconde el
  problema en todas las consultas, no en una.

**Consecuencias.** Es una trampa de Doctrine ORM 3, no un error del modelo de dominio, pero
es de las que salen en un agregador y rompen la pantalla entera. Queda anotado aquí porque
volverá a aparecer en la Fase 18, donde la API devolverá enums en JSON.

---

## D-70 — Un localizador etiquetado se indexa por atributo, no por el valor de un método

**Contexto.** El guard de cuotas (Fase 17) recibe sus contadores de uso con
`!tagged_locator { tag: 'app.entitlement_usage', index_by: 'capability' }`, esperando encontrar
cada proveedor **por el nombre de su capacidad** (`services`, `documents`…). La interfaz
`EntitlementUsageProviderInterface` declara un método `capability()` que devuelve ese nombre, y
el guard hacía `usageProviders->get('services')`.

El localizador, sin embargo, se construía indexado por el **nombre de la clase** del proveedor
(`App\Services\Application\Entitlements\ServiceUsageProvider`). En Symfony 8.1, `index_by` es
el nombre de un **atributo de la etiqueta** —o, con el atributo `#[AsTaggedItem]`, el índice que
el propio servicio declara—, y **no** el nombre de un método que haya que invocar. Invocar un
método para obtener el índice está **deprecado desde Symfony 8.1** y, cuando el método no existe
(tu caso: `capability()` es de instancia, no estático), el contenedor recurría al identificador
del servicio sin avisar.

**Consecuencia.** `has('services')` devolvía `false` para todas las capacidades. `status()`
trataba el uso como `0` cuando no encontraba proveedor, y `assertWithinQuota()` **no lanzaba
nada**: ningún límite del plan se aplicaba. El plan gratuito habría permitido servicios,
buzones y documentos ilimitados, y la Fase 17 habría sido una fachada. El defecto era
**silencioso**: la pantalla de consumo enseñaba «0 de 3» cuando en realidad no se estaba
contando nada, y ningún test lo cubría porque el caso de uso consultaba el límite a través de
otro camino.

**Decisión.** Cada proveedor declara su propia clave con el atributo
`#[AsTaggedItem(Capability::SERVICES)]`, y el `index_by: 'capability'` del YAML se queda como el
nombre del atributo de la etiqueta donde se guarda ese índice. La clave queda **junto a la
clase que la mide**, no en un YAML que habría que recordar actualizar.

```php
#[AsTaggedItem(Capability::SERVICES)]
final readonly class ServiceUsageProvider implements EntitlementUsageProviderInterface
```

**Alternativas.**

- _Buscar el proveedor iterando el localizador y comparando `capability()`._ Descartado:
  convierte una búsqueda O(1) en un recorrido y, sobre todo, deja el contrato débil: el
  localizador tendría que contener algo que no cumple lo que promete.
- _Declarar el localizador con claves fijas en el YAML_ (`services: '@…ServiceUsageProvider'`).
  Descartado: obliga a tocar el núcleo cada vez que un módulo publica un contador, que es
  justo lo que la etiqueta evita.
- _Un método estático `getDefaultCapabilityName()` por proveedor._ Descartado: es la vía
  **deprecada** en Symfony 8.1 y obliga a duplicar el nombre de la capacidad en dos sitios
  (el método estático y `capability()`).

**Consecuencias.** Los límites del plan se aplican de verdad, y el test funcional
`tests/Functional/Identity/PlanTest.php` lo fija de punta a punta (crear el cuarto servicio
falla con `QuotaExceededException`). El test unitario `QuotaGuardTest` fija la tabla de
decisiones con dobles, así que un fallo de cableado y uno de lógica se distinguen.

La lección general: **una etiqueta que el contenedor indexa necesita su clave declarada**, y
declararla en la clase es la única forma de que no se pueda olvidar. El mismo riesgo aplica a
los localizadores etiquetados que se añadan en fases posteriores (webhooks, canales de
notificación, reglas de IA): la clave va con `#[AsTaggedItem]`, no con un método.

## D-71 — La API se autentica con una clave propia, con ámbitos y con HMAC

**Contexto.** La fase 18 hace que Suscripta sea integrable desde otros programas. La primera
decisión no es «qué rutas exponemos», sino **«qué credencial aceptamos»**. Las opciones reales
eran: (a) reutilizar el login del backoffice con sesión, (b) OAuth2 de terceros (Google,
Microsoft), (c) una clave propia emitida por la organización.

(a) está descartada de entrada: la API debe ser _stateless_. Una sesión por petición significaría
cookies, estado en el servidor, y un integrador que no es un navegador (un cron, un servicio
en Go, un script de shell) tendría que gestionar cookies, y además arrastraría la superficie de
ataque del login con contraseñas. (b) es una integración enorme —proveedores, consentimientos,
scopes de terceros, tokens OAuth— para resolver un problema que no tenemos: el integrador es
_nuestro_ cliente, con una relación comercial directa, no un usuario final arbitrario.

**Decisión.** Una **clave de API propia**, por organización, con **ámbitos** (`read`, `write`),
caducidad opcional y revocación. Se emite desde el panel de claves de API del backoffice (`/api-keys`), se
muestra **una sola vez** y se entrega en `Authorization: Bearer …` o `X-Api-Key: …`.

Sobre la **huella**: se usa `hash_hmac('sha256', $secreto, $pepper)` y **no** `password_hash`.
Las dos familias resuelven problemas distintos:

- `password_hash` (bcrypt/argon) está pensado para **contraseñas**: son secretos que una
  persona teclea, que se pueden adivinar y que hay que proteger de la fuerza bruta con un
  coste **alto y deliberado**. No se indexan: para comprobar uno hay que recorrerlos todos.
- Una API key es un **token de.portador** que se presenta en cada petición, se busca en la base
  de datos para autenticarla y se compara con `hash_equals`. Con 300 peticiones por minuto y
  clave, un bcrypt en cada una sería un coste real; y sin índice, autenticar sería O(n) sobre
  las claves de la organización.

El HMAC resuelve las dos cosas: **coste constante** (autenticar es una consulta indexada) y
**determinismo** (la huella es un valor que se puede indexar con un `UNIQUE`). A cambio, el
**pepper** se convierte en un secreto crítico: vive fuera de la base de datos
(`API_KEY_PEPPER`), y sin él un volcado de la tabla `api_key` no permite autenticarse. Es el
mismo modelo que el pepper de contraseñas (D-32), aplicado a credenciales de uso masivo.

**Consecuencias.**

1. **El pepper no se puede rotar sin invalidar las claves.** Es un coste explícito del diseño y
   tiene un procedimiento: emitir las claves nuevas, esperar a que los integradores migren,
   revocar las viejas y, solo entonces, cambiar el pepper. Está documentado en `docs/api/` y en
   `docs/operations.md`, y es la pregunta abierta nº 7 del roadmap.
2. **El límite de frecuencia se conta por clave**, no por IP ni por organización: la clave es la
   unidad de consumo real y la que se puede explicar («su clave `sk_a1B2…` superó 300
   peticiones por minuto»).
3. **Los ámbitos se comprueban antes que la propiedad del recurso**, y los errores de «no
   existe» y «no es tuyo» son indistinguibles: un `403` por ámbito no puede usarse para
   enumerar recursos ajenos, y un `404` no revela que el recurso existe en otra organización.
4. **La clave caduca y se revoca sin tocar nada más**: `expires_at` y `revoked_at` bastan para
   cortar el acceso, y la revocación es un caso de uso auditado.

**Alternativas descartadas.**

- _Reutilizar la contraseña del usuario con HTTP Basic._ Descartada: mezcla dos referencias
  (usuario y organización), no se puede revocar sin cambiar la contraseña, y no soporta ámbitos.
- _Bearer tokens firmados (JWT) sin estado._ Descartada: el beneficiario es «revocar una
  credencial concreta», y un JWT revocado exige una lista de revocación, que es una base de
  datos de lista negra — es decir, el estado que se quería evitar.
- _Guardar la clave en claro y compararla._ Descartada: un volcado de la base de datos da
  acceso a la API de todos los clientes.
- _OAuth2 como proveedor._ Descartada por coste y por alcance (ver arriba). Si algún día hace
  falta, la API **no** habría que rehacerla: la clave propia puede convivir con un flujo OAuth
  como una segunda forma de autenticar.

**Consecuencias para el código.** `ApiKeyFactory` (generación y huella), `ApiKeyAuthenticator`
(autenticación stateless y fijación del tenant en el `TenantContext`), `ApiScopeVoter`
(comprobación de ámbitos) y el panel `/api-keys`. La API reutiliza el `TenantContext` del
backoffice: **el aislamiento entre organizaciones está implementado una sola vez** en el
producto, y la API lo hereda.

## D-72 — La API devuelve read models, no entidades

**Contexto.** El backoffice lee y escribe con las entidades Doctrine, que son richly objects con
relaciones y metadatos internos. La API, en cambio, necesita un **contrato estable**: un
integrador escribe código contra esos campos, y ese código no se puede romper cada vez que el
dominio cambie. Serializar entidades Doctrine directamente ataría el contrato público a la
forma interna del dominio.

La tentación rápida es usar el Serializer de Symfony sobre las entidades. Funciona… hasta la
primera vez que se refactoriza un campo interno. Entonces el integrador recibe un `500` o, peor,
un `200` con un campo que ya no está.

**Decisión.** La API **no** toca entidades Doctrine en las lecturas. Habla con **interfaces de
read model** que declara cada módulo (`ServiceViewRepositoryInterface`,
`AlertViewRepositoryInterface`, `DocumentViewRepositoryInterface`,
`DiscoveryViewRepositoryInterface`, `EmailAccountViewRepositoryInterface`), y cada módulo
registra su implementación en `config/services.yaml`.

Las **escrituras** sí van a los casos de uso del módulo correspondiente (alta de servicio,
confirmación de propuesta, carga de documento, sincronización de buzón), que usan las entidades
y aplican las reglas del dominio. Es deliberado: leer y escribir no tienen por qué compartir la
misma fuente, y las escrituras necesitan las reglas, no un DTO.

**Consecuencias.**

1. **El contrato de la API es explícito.** El read model declara exactamente los campos que
   vuelve, en el orden en que se serializan. Cambiar el dominio no cambia el contrato.
2. **Los tests no necesitan base de datos** para la parte de lectura: basta un doble de la
   interfaz.
3. **Cada módulo es dueño de su forma de leer.** Si `Mailbox` necesita una consulta optimizada
   para las cuentas, la implementa en su repositorio; la API no se entera.
4. **Coste: una capa más.** Interfaces + implementaciones + bindings. Es la contrapartida de
   que el contrato sea público, y es barata comparada con romper integraciones.
5. **El `TenantContext` sigue siendo el mecanismo de aislamiento**, también en los read models:
   filtrar por organización no es opcional en ninguna de las dos implementaciones.

**Alternativas descartadas.**

- _Serializar las entidades directamente._ Descartada: ata el contrato público al modelo interno.
- _Un repositorio «de la API» central que consulta todas las tablas._ Descartada: convierte la
  API en un segundo modelo de dominio, duplicaría las reglas de agregación y dejaría a los
  módulos con dos fuentes de verdad.
- _Reutilizar los repositorios del backoffice tal cual._ Descartada: sirven para la interfaz
  (con relaciones eager y formatos pensados para plantillas), no para un contrato de integración.

**Consecuencias para el código.** Las interfaces viven en el módulo que las publica
(`src/<Módulo>/Domain/Repository/`), las implementaciones en su capa de infraestructura, y los
bindings en `config/services.yaml`. La API (`src/Api/`) **no declara repositorios propios**: solo
consume interfaces de otros módulos, que es lo que mantiene la frontera del módulo (§17.2 de
`ARCHITECTURE.md`).

## D-73 — Una entrega de webhook se serializa una vez y se reintenta byte a byte

**Contexto.** Una entrega falla y hay que reintentarla. La tentación es volver a construir el
cuerpo en cada intento: total, los datos están en base de datos. Pero el receptor verifica una
firma HMAC sobre el cuerpo, y dos serializaciones del mismo array no tienen por qué dar la misma
cadena (orden de claves, escapado de acentos, formato de números). Con dos cadenas distintas hay
dos firmas distintas, y la `Idempotency-Key` deja de servir para lo único que sirve: que el
receptor reconozca el duplicado y lo descarte.

**Decisión.** El cuerpo se serializa **una vez**, al crear la fila de entrega, y se guarda en
`WebhookDelivery.payload`. El reintento reutiliza esa cadena tal cual. La firma se calcula sobre
ella en cada intento —el timestamp sí es nuevo, para que la ventana de 300 s no expire durante la
política de reintentos—, pero el cuerpo no cambia nunca.

**Consecuencias.**

1. **Idempotencia real.** Mismo `Idempotency-Key`, mismos bytes: el receptor puede deduplicar sin
   comparar el contenido.
2. **La fila crece.** Se guarda el JSON completo por entrega y por suscriptor. Es el precio de
   poder reintentar sin reconstruir; se compensa con el borrado de las entregas al borrar el
   suscriptor y con el abandono a los 7 días.
3. **Un cambio en el sobre no afecta a lo ya encolado.** Un evento creado antes de añadir un campo
   viaja sin él, aunque el código ya lo genere. Es correcto: lo que se firma es lo que se guardó.

**Alternativas descartadas.**

- _Reconstruir el cuerpo en cada intento._ Descartada por lo dicho: rompe la firma y la
  idempotencia.
- _Firmar solo un `id` y que el receptor consulte la API._ Descartada: obliga al receptor a tener
  credenciales de la API y convierte cada webhook en dos peticiones.

## D-74 — Los reintentos los decide `RetryPolicy`, no Messenger

**Contexto.** Messenger reintenta los mensajes que lanzan. Un webhook fallido es un caso normal,
no una excepción: el receptor puede estar caído veinte minutos. Si el handler lanzara, Messenger
reintentaría con su propia política (3 intentos con espera corta por defecto) y el resultado sería
una ráfaga de peticiones al receptor, sin relación con la política pensada para webhooks —que
empieza a 30 segundos y termina en 24 horas.

**Decisión.** El transporte `webhooks` va con `max_retries: 0`. `DeliverWebhook` **nunca lanza**:
guarda el fallo en la fila y programa el siguiente intento según `RetryPolicy`. El comando
`app:webhooks:maintain` reencola lo que venció mientras el worker estaba parado.

**Consecuencias.**

1. **Una sola política.** Ocho envíos: 30 s, 2 min, 10 min, 1 h, 6 h, 24 h, 24 h, y abandono.
2. **El fallo es observable.** La fila dice cuántos intentos lleva, qué código devolvió el
   receptor y cuál fue el último error. Un reintento de Messenger solo diría «falló».
3. **Coste: hace falta un cron.** Si el worker está parado, las entregas vencidas esperan al
   mantenimiento. Está documentado en `docs/operations.md`.

**Alternativas descartadas.**

- _Dejar que Messenger reintente y usar el `RetryStrategy` de Messenger._ Descartada: la política
  quedaría en configuración de infraestructura, no en el dominio, y el estado de la entrega
  (intentos, próximo intento, último error) no se podría enseñar en el panel.

## D-75 — El secreto del webhook se guarda cifrado y se puede volver a ver; la clave de API no

**Contexto.** Las claves de API se guardan como HMAC y no se pueden recuperar nunca (D-71): si se
pierden, se revoca y se emite otra. Un secreto de webhook no puede funcionar así, porque Suscripta
lo necesita **en claro en cada entrega** para calcular la firma. Guardarlo en claro sería el mismo
error que guardar contraseñas.

**Decisión.** El secreto se guarda **cifrado** con `CredentialCipherInterface` —el mismo cifrador
de las credenciales de integraciones— y por tanto se puede descifrar. La pantalla de creación es la
única que lo enseña por defecto; verlo después exige un `POST` explícito («Ver secreto») para que
no quede en el historial del navegador ni en un registro de peticiones.

**Consecuencias.**

1. **Dos credenciales, dos modelos.** La clave de API es irreversible; el secreto de webhook es
   recuperable. La diferencia se explica en el panel y en `docs/api/webhooks.md`.
2. **La rotación es posible sin romper nada**: se puede añadir un secreto nuevo y aceptar los dos
   durante una ventana. No está implementado; hoy se cambia el suscriptor.
3. **El cifrado depende de `APP_SECRET`.** Perderlo no filtra los secretos, pero inutiliza las
   entregas: habría que volver a darlos de alta.

**Alternativas descartadas.**

- _Guardar solo el HMAC y pedir el secreto al crear._ Descartada: no se puede firmar lo que no se
  puede leer.
- _Guardar el secreto en claro y confiar en el cifrado del disco._ Descartada: un `pg_dump` o una
  copia de seguridad lo expondrían.

## D-76 — El aviso de presupuesto de IA se manda una vez por periodo, no por correo

**Contexto.** `AiExtractionService` se ejecuta una vez por mensaje. El aviso de «presupuesto
agotado» es útil una vez y ruido a partir de la segunda: un buzón con cien correos pendientes
mandaría cien webhooks idénticos el mismo día, y el receptor acabaría filtrándolos, con lo que el
aviso dejaría de existir el día que importa.

**Decisión.** El aviso se emite desde `AiExtractionService` cuando `isExhausted()` es cierto —antes
de la puerta de llamada, porque en modo `SOFT` agotado se sigue llamando al modelo económico y el
aviso sigue siendo cierto—, y se marca en `cache.app` con una clave que incluye la organización y
el periodo (`currentPeriodKey()`). La marca caduca al terminar el periodo. Solo se marca si se ha
programado **alguna** entrega: si el aviso cae cuando no había suscriptores, el siguiente correo lo
vuelve a intentar y quien se suscriba a mitad de mes se entera.

**Consecuencias.**

1. **Como mucho un aviso al mes por organización**, con independencia del volumen de correo.
2. **La caché es una optimización, no una verdad.** Un `cache:clear` puede provocar un aviso
   repetido. Es aceptable: el evento es informativo y el receptor ya deduplica por
   `Idempotency-Key`… salvo que un aviso repetido es una entrega nueva con su propia clave. Se
   acepta el ruido residual a cambio de no añadir una columna y una migración al presupuesto.
3. **Si la organización no tiene suscriptores para ese evento, no se marca nada.** Es lo que hace
   que suscribirse a mitad de periodo funcione.

**Alternativas descartadas.**

- _Una columna `budget_notified_period` en `AiBudget`._ Descartada por ahora: una migración y un
  campo de estado para un aviso informativo. Si el ruido llega a molestar, es el sitio donde
  ponerlo.
- _No emitir el evento._ Descartada: es el aviso más útil para un integrador que controla su gasto.

## D-77 — La URL de un webhook se valida contra la red interna y la conexión se ata a la IP comprobada

**Contexto.** El webhook hace una petición `POST` a la URL que configure un administrador de la
organización. Esa petición sale **desde dentro de la red del servidor**, así que el campo de texto
del formulario es, en la práctica, un cliente HTTP arbitrario que ejecuta el atacante: `10.0.0.5`,
`169.254.169.254` (metadatos de la nube, donde están las credenciales del servidor), `localhost`,
`::1`… No hace falta ser nadie: basta con ser administrador de una organización o con robar una
cuenta que lo sea. Era la deuda técnica número uno del proyecto y el usuario pidió cerrarla antes
de las fases de cobro.

**Decisión.** Una guarda (`WebhookUrlGuard`) se interpone en los dos momentos en que importa:

1. **Al dar de alta o editar el suscriptor** (`WebhookController`), para que el error se explique
   en el formulario y no se descubra tres días después con un historial de entregas abandonadas.
2. **En cada entrega** (`DeliverWebhook`), porque el DNS de hoy no es el de mañana. Aquí no se
   reintenta: un destino interno no se arregla esperando, así que la entrega se abandona en el
   acto con el motivo escrito.

La guarda comprueba el esquema (`https`), **todas** las direcciones a las que resuelve el host —no
solo la primera, porque un host puede devolver una pública y una privada a la vez— y que ninguna
caiga en 14 rangos IPv4 y 8 IPv6: loopback, RFC 1918, link-local, CGNAT, rangos de documentación,
reservados, multicast, `fc00::/7`, y las **IPv4-mapeadas** (`::ffff:10.0.0.1`), que son la forma
habitual de esquivar una comprobación que solo mira IPv4. Los literales IP los comprueba la propia
guarda, sin pasar por el resolutor: si dependiera de que una implementación futura del resolutor
se acuerde de mirarlos, el agujero volvería solo.

**Por qué se «fija» la dirección.** Validar y luego conectar sin más deja una ventana de un
segundo en la que el DNS puede contestar una dirección pública y, acto seguido, una privada
(_DNS rebinding_). Por eso `pin()` devuelve la dirección validada y `DeliverWebhook` se la pasa al
cliente HTTP en la opción `resolve`: se conecta a esa IP concreta y no se vuelve a preguntar.
Además la entrega va con `max_redirects: 0`, porque un `302` desde un host público hacia
`http://169.254.169.254/` es el mismo ataque con un salto intermedio.

**Un host que no resuelve no se rechaza.** Es lo normal de quien da de alta el receptor antes de
desplegarlo, y en la entrega se comporta como cualquier fallo de red: se reintenta. La guarda no es
un comprobador de disponibilidad, es un cortafuegos.

**Consecuencias.**

1. **Cuatro mensajes de error distintos** (esquema, host ausente, destino interno, no resoluble),
   porque «URL no válida» no le dice a nadie qué arreglar.
2. **`WEBHOOK_ALLOWED_PRIVATE_HOSTS`** (lista separada por comas, vacía por defecto, con
   `host.docker.internal` en `.env.test`) permite nombrar hosts que se saltan la comprobación. Es
   una concesión al desarrollo y al autoalojado, no una opción de producción.
3. **El resolutor es un puerto** (`HostResolverInterface` → `DnsHostResolver`, A con
   `gethostbynamel` y AAAA con `dns_get_record`), para que los tests no dependan del DNS de la
   máquina: en test se inyecta `ControllableHostResolver`, que contesta lo que el test le dice.
4. **Coste: una resolución DNS por entrega.** Despreciable frente a la petición HTTP que viene
   detrás, y es lo que hace que el pinneo sea real.

**Alternativas descartadas.**

- _Validar solo en el alta._ Descartada: un dominio que hoy resuelve fuera puede resolver dentro
  mañana, y el ataque consiste precisamente en controlar el DNS.
- _Validar solo en la entrega._ Descartada por producto: el alta es donde el error se puede
  explicar.
- _Usar `FILTER_VALIDATE_URL` y fiarse._ Descartada: no mira direcciones, solo sintaxis.
- _Una lista negra de nombres (`localhost`, `metadata.google.internal`…)._ Descartada: los nombres
  son infinitos y las direcciones no.

---

## D-78 — `Subscription` es una entidad propia y no un `Service` con más campos

**Estado:** aceptada.

**Contexto.** Hasta la Fase 21, `Service` era el término único del dominio: lo que se paga y el
contrato que lo sostiene eran la misma fila. Eso funcionaba mientras el producto solo miraba hacia
atrás —descubrir en el correo lo que ya se paga—, pero el catálogo comercial (Fase 22) y el cobro
con pasarela (Fase 24) necesitan dos cosas que `Service` no puede dar:

- Un **plan** puede cambiar de precio sin tocar a quien ya lo contrató, y la suscripción de cada
  cliente apunta a un plan del catálogo, no a un texto libre.
- Una suscripción tiene **estado de cobro** (`past_due`), que un servicio detectado en una factura
  nunca tendrá, y un servicio descubierto tiene **estado de revisión** (`pending_review`), que una
  suscripción nunca tendrá.

**Decisión.** `Subscription` es una entidad del módulo `Subscriptions`, con su propio estado, su
periodo y sus fechas. Se enlaza con `Service` mediante `serviceId`, **opcional y explícito**: un
servicio detectado en el correo no se convierte en suscripción por el hecho de existir.

`planId` y `serviceId` son **UUID sueltos, sin clave ajena**. `Plan` vivirá en `Catalog` y
`Service` en `Services`, y los módulos no se referencian por FK en este proyecto (igual que
`Service::$providerId` y `$categoryId`). La consecuencia práctica es que el módulo `Subscriptions`
no arrastra el grafo de `Services` y que `planId` puede estar vacío mientras no exista catálogo.

**Consecuencias.**

1. **Dos listas en el backoffice.** Servicios y suscripciones se ven por separado; el enlace es un
   desplegable en el alta y un enlace en la ficha.
2. **`ServiceStatus` no se reutiliza.** Son dos ciclos de vida con estados que no se solapan; una
   unión habría obligado a que cada pantalla filtrase los estados del otro.
3. **La migración no rellena datos.** El plan preveía enlazar los servicios `MANUAL` con su plan
   del catálogo, pero el catálogo es la Fase 22 y todavía no existe: no hay nada con lo que
   emparejarlos. El relleno se hace allí.
4. **La API pública no cambia en esta fase.** El contrato se amplía en la Fase 24, cuando el cobro
   dé a la suscripción algo que ofrecer.

**Alternativas descartadas.**

- _Añadir `planId` y `status` de cobro a `Service`._ Descartada: mete dos ciclos de vida en una
  tabla y obliga a que todo lo que hoy lee servicios —dashboard, alertas, exportación, API— filtre
  las filas que no son servicios.
- _Hacer `Subscription` una tabla de unión `(organizationId, planId)`._ Descartada: pierde el
  periodo, el estado y las fechas, que es justo lo que hay que guardar.
- _FK real a `service`._ Descartada por coherencia con el resto del proyecto y porque obliga a
  decidir el orden de borrado entre dos módulos.

---

## D-79 — El nombre de la suscripción se congela; el precio, no se guarda

**Estado:** aceptada.

**Contexto.** Una suscripción apunta a un plan del catálogo, y ese plan puede renombrarse o
cambiar de precio. Hay que decidir qué ve el cliente que ya lo tenía contratado.

**Decisión.** La suscripción guarda una **copia del nombre** en el momento del alta
(`Subscription::$name`), y no guarda ningún importe.

**Por qué el nombre sí.** Es lo que el cliente reconoce en su panel y en sus facturas. Si se
leyera del plan, renombrar «Plan Pro» a «Plan Business» cambiaría el histórico de todos los
clientes a la vez, y una factura de enero dejaría de coincidir con lo que se ve hoy en pantalla.
El nombre es un dato del contrato, no del producto. `changePlan()` acepta un nombre nuevo porque
un cambio de plan sí es un cambio de contrato, pero **no toca el periodo ni el estado**: cambiar
de plan no es un cobro.

**Por qué el importe no.** El precio de una suscripción depende de tres cosas que todavía no
están decididas: el precio del plan en el momento del alta, la moneda, y qué pasa cuando el
catálogo sube el precio de un plan que ya tiene clientes (¿se respeta el precio antiguo?, ¿se
avisa?, ¿cuándo?). Guardar un importe ahora obliga a responder eso hoy, y la respuesta sensata
—prorratear, respetar hasta la renovación, avisar con antelación— se decide en la Fase 24 con la
pasarela delante, que es la que ya sabe hacerlo. Hasta entonces el importe vive donde ya vive: en
`ServicePrice` para lo detectado y en el catálogo para lo vendido.

**Consecuencias.**

1. Renombrar un plan del catálogo **no** reescribe las suscripciones vivas.
2. La ficha de la suscripción no enseña importe; enseña periodo, estado y periodicidad.
3. Cuando la Fase 24 traiga el cobro, el importe entra por la factura de la pasarela, no por un
   campo nuevo en `Subscription`.

---

## D-80 — El panel del catálogo exige `ROLE_OPERATOR`, un rol que no se hereda de ningún plan

**Estado:** aceptada.

**Contexto.** La Fase 22 trae la primera pantalla del backoffice que no gestiona datos del
tenant sino **del producto**: los planes que Suscripta vende, sus precios y sus características.
Hasta ahora todo el backoffice estaba detrás de `ROLE_USER` y cada consulta se filtraba por
`organizationId`, así que la pregunta «¿quién puede entrar aquí?» no se había planteado.

**Decisión.** El catálogo exige `ROLE_OPERATOR`. Es un rol **independiente de los planes**: no
lo concede el plan de nadie, no se compra y no se deduce de `OrganizationPlan`. Se concede a
mano con `app:user:grant-operator <email>` y se retira con `--revoke`; el comando es idempotente
y dice qué ha hecho.

**Por qué un rol y no una comprobación de «es el tenant propietario».** No hay tenant
propietario: el catálogo es global (D-81) y no pertenece a ninguna organización. Tampoco vale
`ROLE_ADMIN`, porque en este proyecto los roles de la organización describen lo que un miembro
puede hacer **dentro de su cuenta** —invitar, ver facturas, gestionar correo—, y ninguno de esos
permisos implica cambiar el precio que ven todos los clientes.

**Por qué la constante y no el literal.** `App\Identity\Domain\Security\Roles::OPERATOR` es el
único sitio donde aparece la cadena. Un `ROLE_OPERATOR` suelto en un `#[IsGranted]`, en un
comando y en una plantilla son tres oportunidades de escribir `ROLE_OPERATOR_` y descubrirlo en
producción.

**Consecuencias.**

1. **El enlace del menú va condicionado.** `templates/backoffice/base.html.twig` lo envuelve en
   `{% if is_granted('ROLE_OPERATOR') %}`: un enlace que lleva a un 403 es peor que no tenerlo,
   porque el usuario aprende a ignorar los errores.
2. **El primer operador se crea desde consola.** No hay pantalla de «hazme operador», a
   propósito: sería una escalada de privilegios a un clic. Es acceso al servidor o no es.
3. **Un operador ve el catálogo, no los datos de los tenants.** El rol abre el catálogo y nada
   más; no exime de `TenantFilter` en ninguna otra pantalla.

**Alternativas descartadas.**

- _`ROLE_ADMIN`._ Descartada: mezcla «administro mi organización» con «administro el producto».
- _Una organización marcada como propietaria._ Descartada: el catálogo no es de ningún tenant, y
  haría que el propietario pudiera perder el acceso al cambiar de plan.
- _Un parámetro `OPERATOR_EMAILS` en `.env`._ Descartada: los roles ya viven en la base de
  datos, y un `.env` obliga a reiniciar para dar acceso.

---

## D-81 — El catálogo comercial vive en `Subscriptions` y `Plan.code` es el puente con los límites

**Estado:** aceptada.

**Contexto.** Había dos sitios plausibles para `Plan`: el módulo `Catalog`, que ya tiene
`Provider` y `Category`, o el módulo `Subscriptions`, que es quien lo consume. La documentación
anterior decía «`Plan` vivirá en `Catalog`», y esa frase había que confirmarla o corregirla.

**Decisión.** `Plan`, `PlanPrice` y `PlanFeature` viven en `Subscriptions`. `Plan::$code` es un
`OrganizationPlan` (el mismo enum que usa `entitlements.yaml`), con índice único `uniq_plan_code`,
y **no** hay copia de los límites en la entidad.

**Por qué `Subscriptions` y no `Catalog`.** `Catalog` responde _qué proveedores conocemos y cómo
los reconocemos_: es conocimiento del mundo, no se factura, y sus entidades cuelgan de `Provider`.
Un `Plan` ahí sería un objeto de venta codeándose con proveedores de los que no cobramos nada, y
el panel de operador tendría que filtrar la mitad de las tablas del módulo. `Subscriptions`
responde _qué contrata un cliente_, y el plan es exactamente eso: el objeto del contrato. Además
`Subscription::$planId` apunta a `Plan` sin FK, así que la cercanía no crea acoplamiento.

**Por qué `code` es el enum y no una cadena libre.** Porque `entitlements.yaml` ya está indexado
por `OrganizationPlan` y es la única fuente de los límites (D-64). Con el enum como clave, el
catálogo y la configuración **no pueden** desincronizarse: un plan sin entrada en el YAML no
compila una ficha coherente, y una entrada del YAML sin plan se detecta en el listado, que avisa
de los planes sin ficha. Con una cadena libre habría que validar a mano que coincide.

**Por qué los límites no se copian en `Plan`.** Serían una segunda verdad. El día que alguien
suba `documents` de 500 a 1000 en el YAML, la ficha seguiría diciendo 500 y nadie sabría cuál es
la buena. La ficha los enseña en **solo lectura** y lo dice por escrito.

**Consecuencias.**

1. **Añadir un plan nuevo toca dos sitios**: una entrada en `entitlements.yaml` y una ficha en el
   panel (o `app:catalog:seed`). El listado del catálogo avisa de los planes del enum que no
   tienen ficha.
2. **El nombre y el gancho sí viven en la base de datos**, porque son texto comercial que cambia
   sin desplegar. Los límites no, porque los aplica el código.
3. **La ficha del plan muestra los límites reales**, no los prometidos: lee `EntitlementSettings`,
   que es lo que el producto va a hacer cumplir.

**Alternativas descartadas.**

- _`Plan` en `Catalog`._ Descartada por lo dicho arriba; obliga además a que `Subscriptions`
  dependa de `Catalog` para algo que no es conocimiento de proveedores.
- _`code` como cadena libre con validación._ Descartada: dos fuentes de verdad y un error que solo
  se ve en tiempo de ejecución.
- _Copiar los límites en `Plan` «para que la ficha sea rápida»._ Descartada: la ficha se abre una
  vez al mes; leer el YAML es gratis y siempre correcto.

---

## D-82 — El precio se cierra, no se sobrescribe; y publicar el mismo importe no crea historial

**Estado:** aceptada.

**Contexto.** Cambiar el precio de un plan es la operación más delicada del catálogo: afecta a lo
que se cobra a partir de ahora y a lo que se puede explicar de lo cobrado antes. Hay que decidir
si el precio es un campo del plan o una fila con fechas.

**Decisión.** El precio es una fila de `PlanPrice` con `validFrom` / `validTo`. **Vigente =
`validTo IS NULL`**. Publicar un precio nuevo cierra el vigente del mismo periodo y moneda
(`validTo = ahora`) y abre otro. Un precio **nunca** se modifica ni se borra. Además,
`PublishPlanPrice` **no crea fila si el importe, la moneda y el periodo no cambian**.

**Por qué con historial.** Es el mismo patrón que `ServicePrice` (D-13) y por la misma razón: una
factura de marzo tiene que poder explicarse en junio. Si el precio fuera un campo de `Plan`, subir
el precio borraría la prueba de lo que se cobró antes, y la primera reclamación de un cliente
sería irresoluble.

**Por qué cerrar y no sobrescribir.** `validTo` deja el historial consultable y hace que «el
precio de un día» sea una pregunta con respuesta. La ficha del plan enseña los vigentes arriba y
el historial debajo, sin repetir los de arriba: `findHistoryForPlan()` filtra
`validTo IS NOT NULL` a propósito.

**Por qué no crear fila cuando el importe no cambia.** Porque el formulario se reenvía: alguien
pulsa «Guardar» dos veces, o corrige la descripción del plan y vuelve a guardar el precio. Sin
esta comprobación, el historial se llena de filas de un minuto que no explican nada y que
convierten la pregunta «¿cuándo subió el precio?» en arqueología.

**Por qué el periodo forma parte de la identidad del precio.** Un plan puede costar 19 €/mes y
182,40 €/año. Son dos precios vigentes a la vez, no uno que sustituye a otro; por eso el cierre
solo afecta al vigente **del mismo periodo y moneda**.

**Consecuencias.**

1. **`PlanPrice::SELLABLE_PERIODS`** limita lo que se puede vender a mensual, trimestral y anual.
   Un periodo nuevo (bienal, semanal) es una línea en el enum, no una migración.
2. **`externalPriceId` está desde ya** para el `price_…` de Stripe: la Fase 24 necesita casar la
   fila local con el precio remoto, y añadir la columna entonces obligaría a rellenar el
   histórico a mano.
3. **La baja de un plan no borra sus precios.** El plan se retira con `isActive = false`; sus
   precios se quedan para poder explicar las facturas de quien lo contrató.
4. **Las características sí se borran.** Son texto de presentación: no sostienen ninguna factura
   y equivocarse con ellas no cuesta dinero.

---

## D-83 — La web corporativa vive en la misma aplicación y `/` bifurca por sesión

**Estado:** aceptada.

**Contexto.** La portada tenía que vender el producto y hoy `/` redirige al login. Había dos
caminos: un segundo proyecto (estático o su propio Symfony) o un módulo dentro de esta
aplicación.

**Decisión.** La web es el módulo `src/Website/` de esta misma aplicación. `GET /` sirve la
portada si no hay sesión y **redirige** a `/dashboard` si la hay.

**Por qué en la misma aplicación.** La página de precios lee `PlanPrice` en cada petición y el
alta necesita la sesión que abre el panel. Un proyecto aparte habría necesitado su propia copia
del catálogo —o una API interna para preguntarlo—, su propio despliegue y su propio diseño, y el
día que cambiara un precio habría dos sitios que actualizar.

**Por qué redirección y no renderizar el panel en `/`.** Para que cada pantalla tenga una sola
URL. Si `/` pintara el panel, habría dos direcciones para lo mismo, `path('app_dashboard')`
seguiría siendo la buena y el botón «atrás» devolvería a una portada que ya no toca. Redirigir
deja el panel donde estaba y no rompe ni un enlace.

**Consecuencias.**

1. **Las páginas públicas se listan una por una** en `access_control` (D-84). El defecto sigue
   siendo «lo no listado es privado».
2. **El layout del backoffice no se reutiliza.** La web tiene su `base.html.twig` y su hoja de
   estilos: cargar Bootstrap en la portada habría metido 200 KB de CSS para una página sin
   formularios complejos.
3. **Un solo despliegue y un solo `composer install`.** La web se publica cuando se publica el
   panel, sin coordinación entre dos pipelines.

**Alternativas descartadas.**

- _Un repositorio aparte con sitio estático._ Descartada: los precios dejarían de venir del
  catálogo o habría que regenerar el sitio en cada cambio de precio.
- _Un subdominio con la misma base de código y otro `APP_ENV`._ Descartada: dos despliegues del
  mismo código para servir once páginas.
- _Pintar el panel en `/` cuando hay sesión._ Descartada por lo dicho arriba: dos URLs para la
  misma pantalla.

---

## D-84 — Las páginas públicas se declaran una a una y un test las recorre todas

**Estado:** aceptada.

**Contexto.** `security.yaml` acaba con `- { path: ^/, roles: ROLE_USER }`: todo es privado salvo
lo que se exceptúa antes. Añadir once páginas públicas es añadir once excepciones, y olvidar una
no da un error de compilación: da un 403 que nadie ve hasta que un cliente llama.

**Decisión.** Las rutas públicas se listan **una por una** en `access_control`, con
`PUBLIC_ACCESS`, y `tests/Functional/Website/PublicPagesTest` recorre las once más `sitemap.xml` y
`robots.txt` **sin sesión**, comprobando un 200 y el `<h1>` esperado.

**Por qué una por una y no `^/(precios|producto|…)`.** Una expresión regular con alternativas es
más corta y también más fácil de romper: un paréntesis mal puesto abre una rama que nadie quería.
Once líneas se leen de un vistazo y el `git diff` de una página nueva enseña exactamente una línea
nueva.

**Por qué el test y no confiar en la lista.** La lista y las rutas son dos sitios distintos: se
puede añadir un controlador y olvidar la regla. El test cierra ese hueco en la única dirección que
importa —«esta ruta pública responde sin sesión»— y de paso comprueba que el panel sigue cerrado.

**Consecuencias.**

1. **Añadir una página pública toca dos ficheros**: el controlador y `security.yaml`. El test falla
   si solo se toca uno.
2. **El test también fija el `<h1>`.** Un cambio de titular que rompa la página se ve en la suite,
   no en producción.
3. **`robots.txt` y `sitemap.xml` se generan desde la tabla de rutas**, así que una página nueva
   aparece en el sitemap sin tocar `SeoController::PAGES`… salvo la prioridad, que es una decisión
   editorial y por eso sigue siendo una lista.

**Alternativas descartadas.**

- _Marcar las rutas públicas con un atributo propio y generar `access_control` desde ahí._
  Descartada: `access_control` se evalúa antes del routing y no puede leer atributos del
  controlador. Sería un `RequestListener` propio haciendo lo que el componente ya hace.
- _Un `PUBLIC_ACCESS` para todo lo que no empiece por `/dashboard`, `/api`…_ Descartada: invierte
  el defecto. Un módulo nuevo quedaría público por olvido en vez de privado.

---

## D-85 — La web usa CSS plano escrito a mano; el panel sigue con SCSS compilado

**Estado:** aceptada.

**Contexto.** El backoffice se estiliza con `assets/styles/backoffice.scss`, que AssetMapper
compila. La web podía seguir el mismo camino o llevar su propia hoja.

**Decisión.** `assets/styles/site.css`, CSS plano, escrito a mano, sin SCSS, sin `@use`, sin
variables de compilación. Las variables son propiedades CSS (`--ink`, `--paper`, `--teal`).

**Por qué.** La web tiene **una** hoja y **un** consumidor. El SCSS del panel se justifica porque
hay partials por pantalla y mixins compartidos; aquí no hay nada que anidar que no se lea mejor
plano. Las propiedades CSS dan lo que se buscaba de SCSS —paleta y medidas en un sitio— sin
depender de un paso de compilación que hay que recordar ejecutar.

**Por qué no se reutiliza la hoja del panel.** El panel carga Bootstrap y una retícula de
componentes de aplicación; la web es una pieza editorial con otra tipografía (serif en los
titulares) y otra densidad. Compartir hoja habría obligado a que cada regla del panel conviviera
con las de la web, que es como se acaba con `!important` en un sitio y en el otro.

**Consecuencias.**

1. **Dos sistemas visuales que mantener.** Se acepta: son dos productos distintos —una web que se
   lee y un panel en el que se trabaja— y el día que uno cambie no tiene por qué arrastrar al otro.
2. **La hoja se sirve con digest de AssetMapper** (`/assets/styles/site-<hash>.css`), así que el
   navegador no se queda con una versión vieja tras un despliegue.
3. **Sin build, el CSS se edita y se recarga.** No hay `npm run watch` que se olvide arrancar.

**Alternativas descartadas.**

- _SCSS como el panel._ Descartada: un paso de compilación para una hoja sin anidamiento real.
- _Tailwind._ Descartada: añade un build, un `content` que escanear y clases en el HTML que la
  plantilla no puede leer como un sistema.
- _Reutilizar `backoffice.scss`._ Descartada por lo dicho arriba.

---

## D-86 — La factura de venta es del cobro, no la que el cliente recibe de sus proveedores

**Estado:** aceptada.

**Contexto.** El módulo `Documents` ya tiene una entidad `Invoice`: la factura que el producto
**descubre en el correo del cliente**, la que le manda su proveedor de hosting o de software. Con
el cobro de la fase 24 aparece un segundo papel que también se llama «factura»: la que Suscripta
emite al cliente por el servicio.

**Decisión.** Dos entidades distintas, en dos módulos distintos, sin herencia ni tabla común:

- `Documents\Domain\Entity\Invoice` — factura **descubierta**, del cliente, con su proveedor, su
  CIF y sus líneas extraídas.
- `Billing\Domain\Entity\SalesInvoice` — recibo de **venta**, de Suscripta, con el importe cobrado,
  el estado del cobro y las URLs alojadas por la pasarela.

**Por qué.** Comparten nombre y nada más. La descubierta tiene proveedor, fecha de servicio y
líneas que se extraen de un PDF; la de venta tiene pasarela, estado del pago e identificador
externo. Meterlas en una tabla obligaría a que la mitad de las columnas fueran nulas en cada fila y
a que cada consulta empezara filtrando por un discriminador. Y un error en esa consulta haría que
el listado de facturas del cliente enseñara lo que le hemos cobrado a él, que es una fuga entre
organizaciones, no un fallo de estilo.

**Consecuencias.**

1. **Dos pantallas.** `/facturacion` enseña los recibos de venta; el listado de facturas
   descubiertas sigue donde estaba. Son dos preguntas distintas («¿qué me habéis cobrado?» y «¿qué
   me están cobrando mis proveedores?»).
2. **`SalesInvoice` no pasa por el pipeline de extracción.** No se parsea: la pasarela da importe,
   fecha y estado ya estructurados.
3. **El nombre de la tabla lo dice**: `sales_invoice`, no `invoice`.

**Alternativas descartadas.**

- _Una sola entidad con un discriminador de tipo._ Descartada por lo dicho: columnas nulas y riesgo
  de fuga.
- _Llamar `Receipt` a la de venta._ Descartada: en España el recibo y la factura no son lo mismo, y
  el nombre `SalesInvoice` deja claro de qué lado del negocio está.

---

## D-87 — La idempotencia del webhook se reserva con un `INSERT` atómico, no con un `SELECT` previo

**Estado:** aceptada.

**Contexto.** Stripe puede entregar el mismo evento varias veces, en paralelo y con reintentos de
hasta tres días. Aplicar un `invoice.paid` dos veces no duplica el cobro, pero sí puede duplicar el
recibo o pisar un estado más reciente con uno viejo.

**Decisión.** La reserva es un `INSERT` contra un índice único (`uniq_payment_event_external`). Si
inserta, el evento es nuestro y se aplica; si choca, ya estaba y se responde «duplicado». Nunca se
pregunta primero «¿existe?» para insertar después.

**Por qué.** Un `SELECT` seguido de un `INSERT` es una condición de carrera con ventana: dos
entregas simultáneas del mismo evento leen «no existe» las dos y las dos aplican. El índice único
la cierra en la base de datos, que es el único sitio donde se puede cerrar.

**Consecuencias.**

1. **El `INSERT` necesita su propia transacción.** Un choque contra un índice único deja la
   transacción de PostgreSQL en estado de aborto y cualquier consulta posterior falla con `25P02`.
   Se envuelve en `beginTransaction()`/`commit()` y se hace `rollBack()` al capturar
   `UniqueConstraintViolationException`. En DBAL 4 el anidamiento usa savepoints.
2. **El `INSERT` necesita `Types::BOOLEAN` explícito.** DBAL enlaza por defecto como cadena; `''` no
   es un booleano válido en PostgreSQL y el evento falla con `SQLSTATE[22P02]` antes de llegar a
   ninguna parte.
3. **Si aplicar falla, la reserva se suelta.** Se borra la fila y se responde 500 para que Stripe
   reintente. Sin soltarla, un fallo transitorio se convertiría en un evento perdido para siempre.

**Alternativas descartadas.**

- _`SELECT` y luego `INSERT`._ Descartada: condición de carrera.
- _Guardar solo `event.id` en caché._ Descartada: la caché se pierde en un despliegue y la
  idempotencia tiene que sobrevivir a un reinicio.
- _Confiar en que Stripe no repite._ Descartada: repite por diseño, y está documentado que repite.

---

## D-88 — La integración con la pasarela se escribe a mano, sin SDK

**Estado:** aceptada.

**Contexto.** Stripe publica `stripe/stripe-php`. La alternativa era escribir las cinco llamadas
que hacen falta —crear sesión, consultar sesión, consultar suscripción, listar y verificar la
firma— sobre `symfony/http-client`.

**Decisión.** Sin SDK. `StripeGateway` construye el cuerpo `application/x-www-form-urlencoded` y
parsea la respuesta; `StripeSignatureVerifier` verifica el HMAC. La versión de la API se fija en la
cabecera `Stripe-Version` (`2024-06-20`).

**Por qué.**

1. **Volumen.** Son cinco llamadas. El SDK son ~1 MB de clases para cinco llamadas.
2. **La versión de la API.** El SDK ata la versión de la API a la suya. Aquí interesa fijarla a mano:
   sin la cabecera, Stripe aplica la versión de la cuenta y un cambio de su lado puede cambiar la
   forma de la respuesta sin que nadie haya desplegado nada.
3. **La firma es la única defensa del endpoint público.** Conviene tenerla escrita donde se pueda
   leer y probar, no dentro de una dependencia. Tiene su propio test con once casos, incluida la
   rotación de secreto con varias `v1`.

**Consecuencias.**

1. **Si Stripe cambia el formato de una respuesta, se rompe aquí.** Mitigado con la versión fijada y
   con tests que fijan el contrato del cuerpo enviado.
2. **El parseo es responsabilidad nuestra.** `parse_str()` **no** sirve para inspeccionar el cuerpo
   con claves tipo `line_items[0][price_data][unit_amount]`: las convierte en arrays anidados. El
   test que comprueba el cuerpo decodifica a mano.
3. **Los dobles de test son triviales.** `FakeStripeGateway` implementa el puerto; no hay que
   simular un SDK.

**Alternativas descartadas.**

- _`stripe/stripe-php`._ Descartada por lo dicho. Si algún día hacen falta pagos por transferencia,
  disputas o varios métodos de pago, se replantea.
- _Un servicio de terceros que envuelva a Stripe._ Descartada: una dependencia más y un salto de red
  más para lo mismo.

---

## D-89 — El periodo de la suscripción lo dicta la pasarela, no se calcula en casa

**Estado:** aceptada.

**Contexto.** Una suscripción tiene un periodo (`currentPeriodStart`/`End`). Se podía calcular a
partir de la fecha de alta y la periodicidad, o copiarlo de lo que dice Stripe.

**Decisión.** Se copia. `Subscription::syncWithGateway()` recibe las fechas de la pasarela y las
guarda tal cual.

**Por qué.** En cuanto hay un prorrateo, un aplazamiento o un cambio de plan hecho en el panel de
Stripe, el cálculo local y el real dejan de coincidir. La versión que manda es la del cobro: si
Suscripta cree que el periodo acaba el día 3 y Stripe cobra el día 5, el cliente ve «vencida» una
suscripción que está pagada.

**Consecuencias.**

1. **`ApplyGatewaySubscription` es el único sitio que escribe el periodo.** Crear y actualizar son
   el mismo camino: se busca por `externalReference` y se sincroniza.
2. **La conciliación repara el periodo.** Un evento perdido se corrige en la siguiente pasada de
   `app:billing:reconcile` con los mismos datos.
3. **Si la pasarela no manda periodo, no se inventa.** `GatewaySubscription` lo exige, así que un
   evento sin fechas no se construye y no se aplica.

**Alternativas descartadas.**

- _Calcular en casa con `billingPeriod`._ Descartada por lo dicho.
- _Guardar las dos versiones y mostrar la local._ Descartada: dos verdades sobre lo mismo es cómo se
  acaba discutiendo con un cliente que tiene la factura delante.

---

## D-90 — La suscripción la crea el pago, no la apertura del checkout

**Estado:** aceptada.

**Contexto.** `StartCheckout` podía crear la `Subscription` en estado pendiente al abrir la sesión
de pago, o esperar a que la pasarela confirme.

**Decisión.** No se crea nada al abrir el checkout. La suscripción nace en
`checkout.session.completed` (o en la vuelta a `/checkout/gracias`, que consulta la sesión) y en
ningún otro sitio.

**Por qué.** La mayoría de los checkouts abiertos no se pagan: el cliente compara, duda, cierra la
pestaña. Si cada apertura dejara una suscripción pendiente, el panel se llenaría de fantasmas y
—peor— la comprobación de «ya tienes este plan» empezaría a rechazar clientes que nunca pagaron.
Una intención de pagar no es un contrato.

**Consecuencias.**

1. **Un pago abandonado no deja rastro.** Es lo correcto: no hay nada que limpiar.
2. **La comprobación de duplicado mira solo suscripciones vivas**, no intenciones.
3. **El periodo empieza cuando la pasarela lo dice** (D-89), no cuando se pulsó el botón.

**Alternativas descartadas.**

- _Crear en estado `PENDING` y confirmar después._ Descartada: hay que limpiar los abandonados y la
  limpieza siempre se olvida.
- _No comprobar duplicados y confiar en Stripe._ Descartada: dos clics dejan dos cobros recurrentes
  y devolver uno es trabajo manual.

---

## D-91 — Un solo mecanismo CSRF: el gestor de sesión

**Estado:** aceptada (fase 25).

**Contexto.** Symfony 8 trae CSRF **sin estado** por defecto: el token se deriva de una cookie que
pone el navegador y no se guarda nada en el servidor. Como los formularios de sesión del proyecto
(confirmar un descubrimiento, probar un buzón) usaban el gestor de sesión, en la aplicación convivían
los dos mecanismos. Funcionaba, pero cada `POST` nuevo obligaba a decidir cuál de los dos tocaba y
cada test manual tenía que leer el token del HTML con la estrategia correcta.

**Decisión.** Toda la aplicación usa el gestor de sesión: `framework.csrf_protection: ~`, sin
`stateless_token_ids`. Se borran la configuración de Turbo con `check_header` y el controlador
Stimulus `csrf_protection_controller.js`, que eran parte de la receta sin estado.

**Por qué.**

1. **Una sola forma de equivocarse.** Con dos mecanismos, «el token no vale» tiene dos causas
   distintas y dos arreglos distintos. Con uno, el diagnóstico es siempre el mismo.
2. **El token sobrevive a la pestaña.** El de sesión vale mientras la sesión exista; el sin estado
   depende de una cookie que el navegador puede no mandar (modo privado, bloqueo de terceros,
   petición desde `fetch` sin `credentials`).
3. **Los formularios ya lo usaban.** El backoffice no es una SPA: los `POST` son formularios HTML
   normales, que es exactamente el caso para el que el gestor de sesión está pensado.

**Consecuencias.**

1. **El token se enmascara en cada render.** Desde Symfony 8, `CsrfTokenManager` guarda un valor en
   la sesión (`_csrf/<id>`) y sirve `clave.valor` con una clave aleatoria: el HTML nunca lleva el
   secreto literal y dos renders seguidos dan cadenas distintas. Por eso un test **no** puede
   comparar tokens entre páginas; lo que se comprueba es que el token sirve después de otras
   peticiones y que está en la sesión. Está en `tests/Functional/Identity/CsrfUnificationTest.php`.
2. **Cada usuario necesita sesión para enviar un formulario.** Es lo que ya pasaba: el backoffice
   entero está detrás del login.
3. **Los tokens son por propósito, no un pase único.** `switch_organization` no vale para
   `resend_verification`, y hay un test que lo fija.
4. **Turbo ya no necesita cabecera de CSRF.** Con tokens de sesión el token viaja en el formulario;
   se elimina `config/packages/ux_turbo.yaml` y el controlador Stimulus asociado, que solo existían
   para la receta sin estado.

**Alternativas descartadas.**

- _Dejar los dos y documentar cuál toca._ Descartada: la documentación no impide que el siguiente
  formulario elija el mecanismo equivocado.
- _Todo sin estado._ Descartada: rompe los formularios de sesión existentes, obliga a un controlador
  Stimulus para Turbo y ata la validez del token a una cookie del cliente.

---

## D-92 — Qué valida el job `Lint` y qué no

**Estado:** aceptada.

**Contexto.** El job `Lint` (super-linter) llevaba rojo desde el primer día, así que su informe no
lo miraba nadie. Al ponerlo en verde aparecieron tres clases de hallazgos mezcladas: los que
señalaban código realmente mal escrito, los que aplicaban el estilo de otro proyecto (una guía de
estilo inglesa sobre documentación en español) y los que medían un artefacto en vez de su fuente.

**Decisión.** El job valida **estructura, formato y seguridad**. Un validador solo se desactiva
cuando su criterio no es el del proyecto, y el motivo queda escrito junto a la variable que lo
apaga en `.github/workflows/ci.yaml`. Lo que se valida y lo que no:

- **Shell**: `BASH`, `BASH_EXEC`, `SHELL_SHFMT` (shfmt lee su estilo de `.editorconfig`).
- **Front**: `CSS`, `CSS_PRETTIER`, `JAVASCRIPT_ES`, `JAVASCRIPT_PRETTIER`. `backoffice.css` se
  excluye porque es el artefacto de `backoffice.scss`.
- **Markdown**: `MARKDOWN`, `MARKDOWN_PRETTIER`, con `.github/linters/.markdownlint.json`.
- **YAML, JSON y XML**: `YAML`, `YAML_PRETTIER`, `JSON`, `JSON_PRETTIER`, `XML`. Los compose los
  mantiene Symfony Flex y están en `.prettierignore`.
- **Python**: `PYTHON_PYLINT`, `PYTHON_FLAKE8`, `PYTHON_ISORT`, `PYTHON_MYPY`, `PYTHON_RUFF` y
  `PYTHON_RUFF_FORMAT`, con ancho 120 (`.github/linters/.python-lint`).
- **Seguridad y CI**: `GITLEAKS`, `GITHUB_ACTIONS`, `GITHUB_ACTIONS_ZIZMOR`,
  `DOCKERFILE_HADOLINT`, `ENV`, `EDITORCONFIG`, `GIT_MERGE_CONFLICT_MARKERS`, `PRE_COMMIT`.
- **Duplicación**: `JSCPD`, con el umbral en el 2 % (ver abajo).
- **Fuera, porque solo hablan inglés**: `SPELL_CODESPELL` y `NATURAL_LANGUAGE` marcan «fase»,
  «historial» o piden «readme» en minúsculas.
- **Fuera, porque el job `Tests` los corre sobre la imagen real**: `PHP_BUILTIN`, `PHP_PHPCS`,
  `PHP_PHPSTAN` y `PHP_PSALM`.
- **Fuera, porque no hay nada que revisar**: `CHECKOV` y `TRIVY` (no hay IaC), `BIOME_*` y
  `PYTHON_BLACK` (el formato de Python lo fija `ruff format`).
- **Los ficheros de configuración se declaran** en el propio workflow (`MARKDOWN_CONFIG_FILE`,
  `CSS_FILE_NAME`, `JSCPD_CONFIG_FILE`, `GITLEAKS_CONFIG_FILE`, `EDITORCONFIG_FILE_NAME`,
  `PYTHON_PYLINT_CONFIG_FILE`): super-linter no descubre `.markdownlint.json` por su cuenta.

**Por qué.**

1. **Un solo formateador.** prettier decide el formato de todo lo que sabe formatear
   (`.prettierrc.json` en la raíz) y stylelint se queda solo con la corrección. Dos herramientas
   opinando sobre el mismo espacio es lo que hacía imposible saber cuál tenía razón.
2. **Se mide la fuente, no el artefacto.** `assets/styles/backoffice.css` es la compilación de
   `backoffice.scss` con Bootstrap dentro: revisarlo producía 3 150 avisos y el único clon que
   detectaba jscpd.
3. **La duplicación es un trinquete, no una invitación a trocear.** El proyecto tiene código
   simétrico a propósito (un repositorio por agregado, un detector por señal, tests espejo). Con
   `minTokens` 100 y umbral del 2 % —el estado actual es 1,75 %— el job avisa si la duplicación
   crece, sin pedir que se rompa el diseño para bajar el número.
4. **El inglés no es el idioma del proyecto.** `codespell` y `textlint` traen diccionarios y guías
   inglesas: sobre prosa española generan cientos de falsos positivos. Se apagan y la prosa se
   revisa a mano; el markdown sigue validado en estructura y formato.

**Consecuencias.**

1. **Un validador con su plantilla por defecto no es un validador configurado.** Mientras el
   workflow no declaró el fichero, markdownlint aplicó la plantilla de super-linter —que prohíbe
   los títulos repetidos— y el informe no tenía nada que ver con el criterio del proyecto.
2. **`.editorconfig` es la fuente del estilo de shfmt.** `switch_case_indent` y `binary_next_line`
   están ahí porque son el estilo que ya tenían los scripts; cambiarlos cambia el formateo.
3. **markdownlint usa los valores por defecto de super-linter** (`MD013` a 400 caracteres) más
   `MD024` con `siblings_only`, que es lo que necesita un changelog por fases.
4. **pylint comparte el ancho de 120 de ruff y flake8.** Con 100, `ruff format` volvía a juntar una
   línea de 104 y los dos validadores se peleaban en bucle.
5. **Los compose quedan fuera de prettier** (`.prettierignore`): Flex reescribe sus marcas
   `###> paquete ###` al margen y prettier las indentaría en cada actualización de receta. yamllint
   los sigue validando.
6. **Los documentos se reformatean con prettier** (tablas alineadas, listas y énfasis
   normalizados). Es la primera vez que el markdown del proyecto pasa por un formateador; a partir
   de aquí `npx prettier --write` sobre `*.md` es seguro.

**Alternativas descartadas.**

- _Dejar los validadores de prosa con una lista de palabras ignoradas._ Descartada: la lista crece
  con cada párrafo nuevo y no aporta nada que no haga ya una relectura.
- _Bajar el umbral de jscpd al 0 %._ Descartada: obliga a trocear código simétrico solo para
  satisfacer al medidor.

---

## D-93 — Un catálogo de proveedores con preset y dos formas de autenticación; autodiscovery sin descargar XML

**Estado:** aceptada. Sustituye la parte de D-23 que daba por hecho que «IMAP estándar» bastaba
para todos los proveedores.

**Contexto.** D-23 decidió que el IMAP estándar fuera el camino principal de ingesta, y era
cierto mientras todos los proveedores aceptaran usuario y contraseña. Ya no: Microsoft desactivó
la autenticación básica para las cuentas personales (`outlook.com`, `hotmail.com`) en 2024 y hoy
solo admite `AUTHENTICATE XOAUTH2`; Gmail acepta contraseña de aplicación pero la esconde detrás
de la verificación en dos pasos; Yahoo e iCloud **solo** aceptan contraseña de aplicación. Un
formulario que pide «servidor, puerto, usuario y contraseña» produce, en el mejor caso, un error
de credenciales correcto y, en el peor, un usuario convencido de que se ha equivocado al escribir
su contraseña.

Al mismo tiempo, el servidor IMAP de esos cuatro proveedores es fijo y conocido. Pedirlo es una
fuente de errores gratuita.

**Decisión.**

1. **`EmailAccountProvider` es un catálogo, no una etiqueta.** Cada caso declara su preset
   (`host`, `port`, `encryption`, y la URL oficial donde se genera la contraseña de aplicación),
   si admite OAuth2, si lo exige y si acepta contraseña. La aplicación **impone** el preset: si
   el formulario manda otro servidor para Gmail, se ignora. El cliente no decide a qué servidor
   se conecta la aplicación.
2. **Dos formas de autenticación, un solo envoltorio de credenciales.** `EmailAccountCredentials`
   es un sobre (JSON dentro de `credentialsEncrypted`) que contiene o una contraseña o un
   `accessToken` + `refreshToken` + `expiresAt`. Se reutiliza la columna existente y **no hay
   migración**; un valor que no sea JSON se interpreta como contraseña en claro, que es lo que
   había antes.
3. **Un solo punto traduce «buzón» a «parámetros de conexión».** `ResolveImapConnection` descifra,
   renueva el token si está a punto de caducar (margen de 120 s), **guarda** la credencial nueva
   antes de devolver la configuración y construye el `ImapConnectionConfig`. Antes ese bloque
   estaba copiado en cuatro servicios; con una sola forma de autenticación daba igual, con dos
   cada copia olvidada sería un buzón que funciona al conectarlo y falla al sincronizar.
4. **OAuth2 es un puerto con dos adaptadores** (Google y Microsoft), recogidos por etiqueta. El
   flujo es `authorization code` + PKCE, y la dirección del buzón se lee del `id_token` que
   devuelve el propio endpoint de tokens por TLS; no se valida su firma porque no hace falta:
   no viene del navegador.
5. **Autodiscovery sí, autoconfig por HTTP no.** `DiscoverImapSettings` prueba, en este orden,
   el registro SRV `_imaps._tcp`, el `_imap._tcp` y, si no hay suerte, sondea por convención
   `imap.<dominio>`, `mail.<dominio>` y `<dominio>` en 993/SSL y luego en 143/STARTTLS. **No** se
   descarga el XML de autoconfiguración de Thunderbird (`autoconfig.<dominio>`,
   `/.well-known/autoconfig/...`): es una URL que elige quien escribe el dominio, la petición
   sale de nuestros servidores y el resultado acaba en una conexión IMAP, es decir, una puerta
   abierta a SSRF que además no hace falta para nada.

**Consecuencias.**

1. **Microsoft solo se puede conectar con OAuth2.** No es una preferencia: con contraseña
   devuelve error de credenciales. `requiresOauth()` lo declara y el formulario lo impide antes
   de intentarlo, con un mensaje que dice qué hay que hacer en vez de «credenciales incorrectas».
2. **Gmail y Microsoft necesitan credenciales de aplicación registradas.** Sin
   `MAIL_OAUTH_GOOGLE_CLIENT_ID`/`SECRET` (o las de Microsoft) el botón correspondiente no se
   pinta y el proveedor sigue funcionando con contraseña de aplicación donde se pueda. El buzón
   no se queda sin salida: se degrada, no se rompe.
3. **Yahoo e iCloud no tienen OAuth2 para IMAP.** Su contrato es la contraseña de aplicación, así
   que el formulario enlaza a la página donde se genera en vez de fingir un botón que no existe.
4. **La detección es una pista, no una verdad.** El sondeo por convención puede acertar con un
   servidor que no es el del usuario (un hosting que aloja varios dominios); por eso el
   resultado **rellena** el campo y el usuario lo confirma con «Probar conexión», no se conecta
   solo. El `source` del resultado (`srv` o `convención`) se muestra para que se sepa de dónde
   sale.
5. **La detección tiene su propio limitador** (`mail_discovery`, 15/min por organización): es la
   única ruta que hace que nuestros servidores abran conexiones hacia fuera a petición del
   usuario.
6. **`webklex/php-imap` ya implementa XOAUTH2** (`ImapProtocol::authenticate()` manda
   `user=…\1auth=Bearer …\1\1`), así que no hace falta parchear la dependencia: basta con poner
   `authentication` a `oauth` en la configuración del cliente. Se comprobó leyendo el código
   instalado antes de añadir un parche que no habría hecho falta.

**Alternativas descartadas.**

- _Seguir con un solo formulario «servidor/puerto/usuario/contraseña» y dejar que el usuario
  averigüe lo demás._ Descartada: es exactamente el estado que produce los errores que esta
  decisión elimina, y con Microsoft ni siquiera funciona.
- _Guardar el token en una columna nueva (`oauthTokenEncrypted`, que ya existe en el esquema)._
  Descartada: obliga a migración y a que cada lector sepa mirar en dos sitios. Un sobre en la
  columna existente mantiene una sola fuente de verdad y sigue leyendo los datos antiguos.
- _Descargar el XML de autoconfiguración._ Descartada por SSRF, y porque el sondeo por convención
  cubre el mismo caso sin abrir esa puerta.
- _Parchear `webklex/php-imap` con `cweagans/composer-patches`._ Descartada al comprobar que el
  fallo no existe en la versión instalada: el parche habría añadido un plugin de Composer, un
  binario `patch` en la imagen y un fichero que mantener, para arreglar código correcto.

**Ampliación (asistente en dos pasos).** El formulario único se sustituyó por un asistente: el
primer paso es el catálogo y el segundo la credencial, con una acción distinta según el proveedor
—«Continuar con Google/Microsoft» cuando hay OAuth2 configurado, la contraseña de aplicación con
su enlace oficial cuando la admite, y solo el aviso cuando el proveedor exige OAuth2 y no hay
credenciales de aplicación registradas—. Tres detalles que costaron un fallo cada uno:

1. **El proveedor es un parámetro de ruta, no un campo del formulario.** Si viajara en el cuerpo,
   el cliente elegiría con qué reglas se valida su propia petición. La ruta
   `/mail/accounts/new/{provider}` lleva `requirements` con los valores del catálogo y el caso de
   uso sigue imponiendo el preset por su cuenta (por eso la garantía está probada en
   `ConnectEmailAccount`, no en la pantalla).
2. **`{% include … only %}` corta el contexto.** La tarjeta de OAuth2 nunca se pintó porque el
   `include` del formulario se quedaba con las variables declaradas y el registro de proveedores
   no estaba entre ellas; el mismo `only` dejó vacío el enlace de la contraseña de aplicación. Lo
   que la plantilla incluida necesite se pasa **explícito**.
3. **Un enlace no se rellena desde JavaScript si el objetivo es otro elemento.** El `href` se
   ponía sobre el párrafo, no sobre el `<a>`. Ahora el servidor pinta la dirección del catálogo y
   el controlador de Stimulus solo enseña u oculta la nota; sin `provider` en el DOM —el paso 2 no
   lo tiene— no hace nada.
