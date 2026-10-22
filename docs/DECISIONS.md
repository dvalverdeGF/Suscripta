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

- *Sincronizar en cada punto de entrada.* Descartada: es la situación que produjo el fallo, y
  añade un punto de fallo por cada camino nuevo.
- *Middleware de Messenger + sincronización en los comandos.* Descartada por lo mismo: sigue
  habiendo varios sitios donde acordarse.
- *Pasar el `organizationId` a mano en cada consulta.* Descartada: contradice D-11 y multiplica
  las oportunidades de error.
- *Row Level Security de PostgreSQL.* Sigue siendo la evolución deseable como capa adicional,
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

- *Comparar solo direcciones exactas.* Descartada: obliga a enumerar cada remitente de cada
  plataforma y se queda obsoleta en cuanto cambian.
- *Comparar por sufijo de cadena.* Descartada por el vector de suplantación descrito arriba.
- *Expresiones regulares en configuración.* Descartada: traslada a quien configura el riesgo de
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

- *Devolver el secreto enmascarado (`sk-••••abcd`).* Descartado: sigue siendo parte del
  secreto en el DOM, y no aporta nada: nadie necesita el final de su token.
- *Borrar cuando el campo viene vacío.* Descartado por el motivo anterior.
- *Un botón de «borrar credenciales» en cada ficha.* Elegido: es explícito, reversible (se puede
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

- *Veto permanente por clave de propuesta.* Descartado: envejece mal y no se puede deshacer.
- *No suprimir nada, o suprimir solo mientras la propuesta siga viva.* Descartado: el usuario
  seguiría recibiendo la misma pregunta cada vez que llega una factura, que es exactamente lo
  que hace inservible la bandeja.
- *Borrar la propuesta descartada.* Descartado: se pierde la evidencia y la explicación de por
  qué el sistema dejó de proponerlo.

**Consecuencias.** `DiscoverySettings` es política de producto, no constantes: vive en un
parámetro de contenedor. La clave de supresión es la misma que identifica una propuesta
(proveedor, importe, moneda y periodicidad), de modo que suprimir es por *servicio*, no por
*remitente*.

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

- *Propagar la primera excepción.* Descartado: un error puntual destruye el trabajo de veinte.
- *Validar todo el lote antes de tocar nada (todo o nada).* Descartado: es peor; el usuario
  pierde también las propuestas válidas y tiene que empezar de cero.
- *Confirmar en silencio solo lo que funciona, sin avisar de los fallos.* Descartado: el usuario
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

- *La detección siempre pisa la declarada.* Descartado: un único cobro aislado puede convertir un
  plan trimestral en mensual y desordenar todas las alertas de cobro.
- *No detectar nada nunca.* Descartado: la detección es el mecanismo que permite dar de alta un
  servicio sin que el usuario declare su periodicidad a mano.
- *Detectar siempre y guardar ambas.* Descartado: obliga al usuario a decidir entre dos
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
serializa en caché, se indexa o se exporta deja de ser «la entidad» y pasa a ser *una* forma de
la entidad. Cada consumidor atado a su forma interna se rompe en cuanto esa forma cambia, y el
cambio se propaga a pantallas, cálculos y al pipeline.

**Decisión.** El módulo `Services` publica un **read model** en `src/Services/Domain/Dto/` y una
interfaz de lectura `ServiceViewRepositoryInterface`. Las lecturas —panel, detalle de servicio,
alertas, documentos, facturas, descubrimientos, emparejamiento, histórico de precios, evolución
del gasto— consumen DTOs. La entidad `Service` queda para el módulo que la escribe.

**Alternativas.**

- *Un único modelo (entidad) para todo.* Descartado: es la causa del problema, no su solución.
- *CQRS completo, con modelos separados de lectura y de escritura por módulo.* Descartado por
  coste: no aporta nada aquí donde la operación de lectura es simple, y multiplica el trabajo sin
  un beneficio proporcional. El read model se aplica donde aporta: el servicio, que es la entidad
  más consultada y más inestable.
- *Repositories de Doctrine filtrados sin read model.* Descartado: reproduce el problema con
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
mundo», y el conocimiento del buzón dice «en *mi* correo, este remitente es *este* proveedor».

El conflicto es real: un usuario puede tener un correo de `facturacion@miempresa.com` que para el
catálogo no es nadie y que en su buzón es su proveedor deIsso. O al revés: el catálogo dice que
`newsletter@proveedor.com` es el proveedor X, y el usuario sabe que en su buzón son newsletters
que no quiere pagar por entender.

**Decisión.** Cuando ambos dicen algo y no coinciden, **manda el conocimiento del buzón**. Es el
conocimiento más específico (está a un solo paso del correo concreto), el más reciente y el que el
usuario puede ver y corregir en la pantalla de conocimiento. El catálogo global sigue siendo el
punto de partida para buzones que no han aprendido nada, y para los buzones de otros usuarios.

**Alternativas.**

- *El catálogo global siempre gana.* Descartado: obligaría al usuario a corregir un catálogo que
  es de todos para resolver un problema que es suyo, y no offería ninguna forma de salir de
  ahí.
- *Preguntar al usuario cuando chocan.* Descartado por coste: convertir cada correo ambiguo en
  una pregunta es exactamente lo que el producto evita. El desacuerdo se resuelve solo, a favor
  de lo específico, y el usuario puede corregirlo cuando quiera.
- *Fusionar los dos.* Descartado: son datos de alcances distintos. Un patrón de asunto útil en un
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

- *Usar la IA siempre que el documento no sea accionable.* Es lo que hacía el sistema antes de
  esta fase: correcto, pero caro. Con la periodicidad aprendida, la segunda factura del mismo
  proveedor se resuelve sin gastar un token.
- *Aprender la periodicidad y aplicarla siempre, aunque el texto diga otra cosa.* Descartado:
  el texto es la fuente directa y un correo puede traer un plan distinto al habitual. Lo aprendido
  rellena huecos, no pisa lo que el correo dice.
- *Guardar la periodicidad como un dato del servicio y no del buzón.* Descartado: el servicio es
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

- *Excluir al primer descarte.* Descartado: castiga el error. Un solo descarte no es evidencia
  suficiente para dejar de leer el correo de un proveedor.
- *Excluir siempre al primer descarte pero con la regla de los dos descartes.* Lo mismo que lo
  anterior con más pasos.
- *No contar los descartes.* Descartado: el usuario repetiría el gesto en cada correo del mismo
  remitente sin que el sistema aprenda nada.

**Consecuencias.** `RecordSenderRejection` es idempotente en la confianza (nunca baja sola) y
`MailboxKnowledgeEntry::excludes()` centraliza el umbral. El descarte sigue siendo reversible:
borrar la regla de ignorado devuelve el remitente al circuito.

## D-54 — Promover al catálogo global es explícito, nunca automático

**Estado:** aceptada.

**Contexto.** El conocimiento del buzón es privado: describes *tus* correos. El catálogo global es
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

- *Promover automáticamente lo que se repite.* Descartado: «se repite» no significa «vale para
  los demás», y el coste de filtrar datos entre usuarios no se puede recuperar.
- *Un interruptor «compartir lo que aprendo».* Descartado: es la misma promoción automática con
  un interruptor, solo que más difícil de entender.
- *Promover hacia el catálogo de la organización.* Descartado: no es el catálogo global, y
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

- *Guardar el gasto en la fila de presupuesto.* Descartado: es una segunda verdad, y además
  obliga a actualizar una fila por cada llamada, que es justo lo que hace que se desincronice.
- *Calcular el gasto contando filas sin agregar.* Descartado: es O(n) por llamada de IA, y el
  número de llamadas crece con el uso.
- *No guardar nada y calcular el presupuesto al vuelo.* Descartado: el tope es una **decisión**,
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

- *Un solo comportamiento (parar siempre).* Descartado: una organización con un tope bajo
  dejaría de detectar facturas, que es justo lo que ha pagado.
- *Degradar siempre.* Descartado: quien fija un tope de 0 € quiere que no se gaste, y el
  sistema no puede inferir esa intención.
- *Un tope único con el modo implícito en el importe.* Descartado: el importe no dice qué
  hacer; un tope de 5 € y uno de 500 € pueden significar cosas distintas según la organización.

**Consecuencias.** `AiBudgetMode` es parte de la política guardada. `AiBudgetGuard::allowsCall()`
responde a «¿puedo llamar?» y `allowsTier()` a «¿puedo llamar a *este* nivel?», que es la
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

| Motivo | Significado | Qué hace el pipeline |
|---|---|---|
| `NONE` | Hay documento | Se usa |
| `NOT_CONFIGURED` | No hay IA activada o no hay proveedor | Revisión manual |
| `NO_TEXT` | El correo no tiene texto que analizar | Revisión manual |
| `NOT_NEEDED` | La escalera no tenía nada que hacer | Revisión manual |
| `FAILED` | Se llamó al proveedor y falló | Revisión manual |
| `RATE_LIMITED` | Se pasó el límite de peticiones | Revisión manual (se reintentará) |
| `BUDGET_EXHAUSTED` | No hay presupuesto | **`DEFERRED`** |

Solo `BUDGET_EXHAUSTED` aplaza el correo. Los demás van a revisión manual, que es lo que
corresponde: son casos que necesitan una decisión.

**Alternativas.**

- *Devolver `null` y que el pipeline pregunte por separado si el presupuesto está agotado.*
  Descartado: el pipeline tendría que consultar el guard, es decir, el módulo de IA, para
  distinguir dos `null` que significan cosas distintas. La razón tiene que viajar con el
  resultado.
- *Añadir un estado `REQUIRES_REVIEW` con submotivo.* Descartado: el estado de la máquina dice
  **dónde** está el correo, no **por qué**. Mezclarlos obliga a duplicar estados.
- *Un estado nuevo `AWAITING_BUDGET`.* Descartado: `DEFERRED` ya dice exactamente eso, y el
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

- *Que `Ai` consulte el repositorio de mensajes.* Descartado: viola la regla de dependencias y
  además hace que el módulo de IA sepa qué es un estado de procesamiento.
- *Que un comando de la aplicación sea el que avise a los dos.* Descartado: el aviso dejaría de
  ser fiable (depende de que alguien ejecute el comando) y además seguiría haciendo falta una
  dependencia en algún sitio para preguntar «¿ha cambiado el presupuesto?».
- *Un evento de Messenger (`BudgetRaised`).* Descartado por ahora: el evento es asíncrono y la
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

- *Confiar en el texto de la propuesta.* Descartado: el usuario lee el texto, pero el sistema tiene
  que poder **filtrar** y **priorizar**. La distinción tiene que ser consultable.
- *Bajar la confianza a un número fijo (por ejemplo, 50).* Descartado: el recorte tiene que ser
  una **banda** con nombre, porque la banda es lo que la interfaz sabe pintar y lo que los filtros
  saben interpretar. Un número suelto se volvería a subir por accidente en la siguiente fase.
- *No distinguir, y que el usuario lo adivine por el texto.* Descartado: es exactamente el
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

- *Borrar el servicio duplicado y sus facturas.* Descartado: el usuario pierde el histórico de lo
  que pagó y de lo que le cobraron. Es la clase de pérdida que hace que alguien deje de confiar en
  la herramienta.
- *Cancelar el servicio más antiguo en vez del más reciente.* Descartado: el original es el que
  tiene los eventos, las facturas y el `nextChargeAt` acumulado. Cancelar el más antiguo tiraría
  justo la información que el usuario necesita.
- *Un «deshacer» para el duplicado.* Descartado por ahora: no se ha pedido, y el historial ya
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

- *Borrar solo los datos y dejar la organización.* Descartado: deja credenciales y binarios
  recuperables bajo un identificador que ya no apunta a nada, y el «borrado» solo sería cierto
  en la interfaz.
- *Conservar los datos agregados (importes, conteos) para métricas.* Descartado: se conservan
  los **importes agregados de uso de IA**, que no son personales y sí sirven para las métricas
  del panel. Las filas de detalle sí se borran.
- *Reclamar la organización si el usuario tiene otras.* Descartado: complica el modelo de
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

| Categoría | Plazo |
|---|---|
| Correo (mensajes y eventos de procesamiento) | 365 días |
| Documentos (binario tras el borrado lógico) | 30 días |
| Propuestas ignoradas | 90 días |
| Avisos resueltos | 365 días |
| Libro de auditoría | 730 días |
| Uso de IA | 730 días |
| Conocimiento del buzón | 365 días |
| Caché de extracción | 30 días |

**Alternativas.**

- *Constantes en el código.* Descartado: cambia la política de retención exige un despliegue.
- *Un plazo único para todo.* Descartado: o se purga antes de tiempo (se pierde trazabilidad) o
  se conserva demasiado (no se cumple la política).
- *Plazos por módulo.* Descartado: el mismo tipo de dato (los correos) vive en dos módulos y
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

- *El agregador importa los repositorios.* Descartado: crea un nodo que depende de todos los
  módulos y que hay que tocar cada vez que se añade uno.
- *Un evento de dominio «borrar organización» al que cada módulo se suscriba.* Descartado:
  introduce orden de ejecución implícito y difficulta de garantizar que todos los
  suscriptores se han ejecutado (y que han terminado) antes de responder.
- *Un servicio de dominio gigante con un método por módulo.* Descartado: es el mismo problema
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
*qué planes existen*, y los **límites viven en configuración**
(`config/packages/entitlements.yaml`). Cada capacidad se declara con su tipo:

- `limit`: un número. `null` = sin techo; `0` = la capacidad no está disponible.
- `flag`: un interruptor (hoy, la disponibilidad de la inteligencia artificial).

La comprobación de que **todos los planes declaran todas las capacidades** la hace
`EntitlementSettings` al construirse: si alguien añade una capacidad al código y olvida
meterla en la configuración, el contenedor no arranca. Es un error de despliegue, visible el
primer día, y no un límite que desaparece en silencio.

**Alternativas.**

- *Los números en el enum de PHP.* Descartado: cambiar un precio exigiría un despliegue, y
  el enum quedaría mezclando dos cosas que cambian por motivos distintos (el catálogo de
  planes y sus precios).
- *Los números en la base de datos.* Descartado por ahora: haría falta una tabla de planes
  editable en caliente, y no hay quien la edite. Cuando los planes se vendan en autoservicio,
  esta decisión se revisará (ver D-65).
- *Sin planes, solo cuotas por organización.* Descartado: no permite distinguir «gratis» de
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

- *Borrar lo que no cabe al bajar de plan.* Descartado: es una sorpresa muy desagradable
  (el cliente pierde datos por un cambio comercial que él no pidió) y sería irreversible sin
  un botón de «deshacer» que no existe.
- *Dejar el plan editable desde el panel.* Descartado **por ahora**: en esta versión no hay
  cobro, así que un botón «cambiar a profesional» sería regalar el plan de pago. El cambio lo
  hace el operador de la instalación con `app:plan:set`, y la pantalla `/plan` solo informa.
  Cuando exista el cobro, esta decisión se revisa.
- *Guardar el historial de planes en una tabla aparte.* Descartado: el libro de auditoría ya
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

- *Guardar el paso actual en la base de datos.* Descartado: mentiría en cuanto el usuario
  conecta el buzón desde otra pantalla (o desde la API en el futuro). Un «paso 2 de 3» guardado
  es un dato que se queda viejo solo.
- *Bloquear la aplicación hasta terminar la guía.* Descartado: un cliente que ya tiene sus
  datos cargados y solo quiere consultar una factura no podría ni mirar su factura. El
  objetivo de la guía es que el primer impacto sea una explicación, no un muro.
- *Guardar los pasos en configuración.* Descartado: el contenido de la guía cambia con el
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

- *Un fixture de Doctrine.* Descartado: los fixtures no son idempotentes de forma útil (crean
  duplicados si se repiten) y no participan del aislamiento por organización, que en este
  producto no es opcional.
- *Un script SQL.* Descartado: se desincroniza del esquema en cuanto cambia una entidad.
- *Dejar la cuenta de demo en la base de datos de desarrollo.* Descartado: nadie sabe qué
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

- *Céntimos con decimales (NUMERIC).* Descartado: el producto guarda los importes en enteros
  de céntimos por decisión anterior; tener dos representaciones del dinero en la misma base de
  datos es una fuente de errores de redondeo.
- *Guardar el coste en la moneda del proveedor y convertir al presentar.* Descartado: los
  proveedores cobran en varias monedas y el tipo de cambio cambia; el gasto registrado debe
  ser inmutable.
- *Redondear a céntimos.* Descartado: pierde el dato justo en el rango que importa.

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

- *Marcar la columna como `enumType` y esperar que DQL devuelva cadenas.* Descartado: es
  exactamente lo que no hace; Doctrine devuelve la instancia del enum.
- *Usar `->value` a ciegas.* Descartado: falla con el mismo `TypeError` cuando el valor llega
  como string, que es lo que ocurre en algunos caminos.
- *Forzar el tipo en la consulta con un `CASE`.* Descartado: ensucia el DQL y esconde el
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

- *Buscar el proveedor iterando el localizador y comparando `capability()`.* Descartado:
  convierte una búsqueda O(1) en un recorrido y, sobre todo, deja el contrato débil: el
  localizador tendría que contener algo que no cumple lo que promete.
- *Declarar el localizador con claves fijas en el YAML* (`services: '@…ServiceUsageProvider'`).
  Descartado: obliga a tocar el núcleo cada vez que un módulo publica un contador, que es
  justo lo que la etiqueta evita.
- *Un método estático `getDefaultCapabilityName()` por proveedor.* Descartado: es la vía
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

(a) está descartada de entrada: la API debe ser *stateless*. Una sesión por petición significaría
cookies, estado en el servidor, y un integrador que no es un navegador (un cron, un servicio
en Go, un script de shell) tendría que(handle cookies, y además arrastraría la superficie de
ataque del login con contraseñas. (b) es una integración enorme —proveedores, consentimientos,
scopes de terceros, tokens OAuth— para resolver un problema que no tenemos: el integrador es
*nuestro* cliente, con una relación comercial directa, no un usuario final arbitrario.

**Decisión.** Una **clave de API propia**, por organización, con **ámbitos** (`read`, `write`),
caducidad opcional y revocación. Se emite desde el panel de integraciones del backoffice, se
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

- *Reutilizar la contraseña del usuario con HTTP Basic.* Descartada: mezcla dos referencias
  (usuario y organización), no se puede revocar sin cambiar la contraseña, y no soporta ámbitos.
- *Bearer tokens firmados (JWT) sin estado.* Descartada: el beneficiario es «revocar una
  credencial concreta», y un JWT revocado exige una lista de revocación, que es una base de
  datos de lista negra — es decir, el estado que se quería evitar.
- *Guardar la clave en claro y compararla.* Descartada: un volcado de la base de datos da
  acceso a la API de todos los clientes.
- *OAuth2 como proveedor.* Descartada por coste y por alcance (ver arriba). Si algún día hace
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

- *Serializar las entidades directamente.* Descartada: ata el contrato público al modelo interno.
- *Un repositorio «de la API» central que consulta todas las tablas.* Descartada: convierte la
  API en un segundo modelo de dominio, duplicaría las reglas de agregación y dejaría a los
  módulos con dos fuentes de verdad.
- *Reutilizar los repositorios del backoffice tal cual.* Descartada: sirven para la interfaz
  (con relaciones eager y formatos pensados para plantillas), no para un contrato de integración.

**Consecuencias para el código.** Las interfaces viven en el módulo que las publica
(`src/<Módulo>/Domain/Repository/`), las implementaciones en su capa de infraestructura, y los
bindings en `config/services.yaml`. La API (`src/Api/`) **no declara repositorios propios**: solo
consume interfaces de otros módulos, que es lo que mantiene la frontera del módulo (§17.2 de
`ARCHITECTURE.md`).
