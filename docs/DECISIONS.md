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
- *Solo `User` como tenant.* Más simple hoy, pero añadir `Organization` después obliga a
  migrar la clave de aislamiento de **todas** las tablas y a reescribir el filtro de tenancy.
- *`Organization` con equipos completos desde el inicio.* Complejidad empresarial innecesaria.

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
carga). Sin la estructura modular, el monolito se convertiría en un *big ball of mud*.

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
- AssetMapper sirve el fichero directamente, con *digest* para el cache-busting.

**Alternativas.**
1. **Tailwind vía `symfonycasts/tailwind-bundle`.** Descarga el binario *standalone* de
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
inventar estilos por pantalla; se revisa en el *code review*.

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
   recurrente. "El remitente es OVH, el asunto contiene *facture*, hay un PDF y el importe
   aparece en el texto" sí lo es.
4. **Fiabilidad.** Un modelo alucina; una regex sobre una plantilla conocida, no. El dominio
   no puede depender de la estabilidad de un proveedor externo.
5. **Latencia y volumen.** Procesar 10.000 mensajes con IA tarda horas y consume cuota.

**Por qué la IA no contiene lógica de negocio.** El cálculo de periodicidad, próximo cobro,
comparación de precios, detección de duplicados y equivalencia mensual son **funciones puras
testeables** en `Shared\Domain` / `Services\Domain`. Si vivieran en un *prompt*, no se podrían
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

| Nivel | Uso | Cuándo |
|---|---|---|
| 5 · económica | Extracción estándar | Por defecto, cuando reglas y parsers no bastan |
| 6 · avanzada | Extracción difícil | Confianza insuficiente, campos contradictorios, proveedor desconocido con documento complejo, PDF difícil, información insuficiente |

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
consultable a *qué ocurrió, cuándo, con qué extractor, si intervino IA, con qué resultado y por
qué*, que es exactamente lo que exige el requisito de observabilidad.

---

## D-33 — Conocimiento de proveedores (global) separado del conocimiento del buzón (por cuenta)

**Estado:** aceptada.

**Contexto.** El sistema debe reconocer proveedores y aprender de cada buzón. Mezclar ambos
conocimientos produce contaminación cruzada: un patrón aprendido en el buzón de un usuario
afectaría al de otro.

**Decisión.** Tres entidades con ámbitos distintos:

| Entidad | Pregunta | Ámbito |
|---|---|---|
| `Provider` | ¿Quién es? | Global o del tenant |
| `ProviderIdentity` | ¿Cómo lo reconocemos? (dominio, remitente, patrón) | **Global** |
| `ProviderParser` | ¿Cómo extraemos sus datos? | **Global** |
| `MailboxKnowledgeEntry` | ¿Qué hemos aprendido de *este* buzón? | **Por cuenta de correo** |

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

| Puntuación | Resultado | Acción |
|---|---|---|
| ≥ 70 | HIGH | Asociación automática → `MATCHED` |
| 40–69 | MEDIUM | `Discovery` para revisión → `DISCOVERY` |
| < 40 | LOW | `Discovery` de servicio nuevo → `DISCOVERY` |

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
pueden contestar a posteriori si no se registran desde el primer día: *¿cuánto nos cuesta
procesar el buzón de este usuario?* y *¿cuánto cuesta de IA un usuario medio al mes?* Sin esos
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

| Señal | Problema |
|---|---|
| `folder` + `uid` | Los UID se repiten entre buzones; se invalidan si cambia `uidValidity` |
| `Message-ID` | Puede faltar, repetirse o falsificarse |
| `contentHash` | Dos correos idénticos legítimos colisionan; requiere descargar el contenido |

1. **Clave primaria de deduplicación:** `emailAccountId + folder + uid` (única, estable y
   gratuita), guardada junto con `uidValidity`.
2. **Clave secundaria:** `emailAccountId + messageId` (detecta el mensaje movido de carpeta).
3. **`contentHash`:** deduplica documentos, detecta el mismo mensaje en dos cuentas y sirve de
   **clave de caché de extracción**.
4. **Deduplicación entre cuentas a nivel de organización:** `Discovery` es tenant-scoped, así
   que el mismo servicio detectado por dos buzones produce un descubrimiento, no dos.

**Antes de cualquier llamada de IA** se comprueba que no exista ya un `ExtractedDocument`
válido para ese `contentHash`. Si existe, se reutiliza: coste cero.

**Regla explícita:** *si el mismo correo vuelve a aparecer durante una sincronización, no debe
volver a consumir IA.*

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

**Contexto.** El sistema "aprende" del buzón. La palabra *aprender* sugiere ML: clasificadores
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

- *Bloqueo total hasta verificar.* Descartada por fricción en el momento de menor valor
  percibido.
- *Sin verificación.* Descartada: permitiría registrar el correo de otra persona y, más
  adelante, conectar buzones en su nombre.
- *Token en base de datos.* Descartada: `UriSigner` ya resuelve caducidad e integridad sin
  estado adicional que purgar.

**Consecuencias.** Hay que recordar que la verificación es un requisito de la conexión de
buzón, no del acceso: la comprobación vive en el caso de uso de conexión, no en el firewall.
El aviso del armazón es informativo y no debe convertirse en un muro.
