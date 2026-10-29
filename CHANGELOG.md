# Changelog

Todos los cambios relevantes de Suscripta. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el proyecto se rige por
[Versionado Semántico](https://semver.org/lang/es/).

Mientras el producto no alcance su primera versión pública (`1.0.0`), las entradas se agrupan
**por fase del roadmap** en lugar de por número de versión: cada fase es una unidad entregable
cerrada, verificada y commiteada. El detalle de lo que queda pendiente está en
[docs/ROADMAP.md](docs/ROADMAP.md).

## [No publicado]

### Añadido

- **Capturas de pantalla en el README**: `docs/img/` con cinco vistas (panel, propuestas,
  servicios, avisos y precios) tomadas contra la cuenta de demostración. Se regeneran con
  `make screenshots` (`tools/screenshots.py`, Playwright), así que no envejecen a mano.

### Corregido

- **La CI estaba roja en `main` y un clon limpio devolvía 500 en todo el backoffice**: las
  librerías vendorizadas (`bootstrap` 5.3.8 y `fontawesome` 7.1.0) vivían en `assets/vendor/`,
  que estaba ignorado por git, y ningún script las descargaba. Ahora se versionan; el resto de
  `assets/vendor/` —lo que genera `importmap:install`— sigue ignorado y `make up` lo reinstala.
- **Dependencias**: Symfony 8.1.7/8.1.8 → **8.1.10**, que corrige
  [CVE-2026-45070](https://symfony.com/cve-2026-45070) (inyección de cabeceras de correo a través
  de nombres de parámetro no token en `symfony/mime`). `composer audit` queda sin avisos.
- **PHPStan fallaba de forma intermitente** al agotar los 128 MB del `php.ini`: `make phpstan` y
  la CI pasan ahora `--memory-limit=1G`.
- El README anunciaba «fases 0 a 19», 1 420 tests y 19 migraciones: ahora refleja el estado real
  (fases 0–25, 1 609 tests, 23 migraciones) e incluye el cobro con Stripe.
- `docs/deployment.md` seguía diciendo «las 19 migraciones» en el orden de arranque.

### Pendiente

- **Antes de publicar:** sustituir los marcadores de las tres páginas legales
  (`[RAZÓN SOCIAL]`, `[NIF]`, `[DOMICILIO]`, `[REGISTRO Y TOMO]`).

---

## [Fase 25] — 2026-10-29 — Un solo CSRF

Toda la aplicación comprueba el CSRF con el gestor de sesión. Desaparece la receta sin estado que
Symfony 8 trae por defecto y que convivía con él. Commit de cierre: `a5b1207`.

### Cambiado

- **`framework.csrf_protection` sin `stateless_token_ids`**: los tokens se guardan en la sesión bajo
  `_csrf/<propósito>` y se enmascaran en cada render. Los formularios usan `token_id: submit`. No
  hubo que tocar plantillas ni controladores: los 45 `csrf_token()` y los ~20
  `isCsrfTokenValid()` ya iban por el gestor de sesión.

### Eliminado

- **`config/packages/ux_turbo.yaml`** y **`assets/controllers/csrf_protection_controller.js`**: solo
  existían para la receta sin estado (cabecera `X-CSRF-Token` en peticiones Turbo y doble-submit
  cookie). Con tokens de sesión sobran.

### Añadido

- **`tests/Functional/Identity/CsrfUnificationTest`** (6 casos): el token está en la sesión, no se
  emite la cookie del gestor sin estado, un formulario abierto hace rato sigue valiendo, los tokens
  son por propósito y uno inventado devuelve 403.

---

## [Fase 24] — 2026-10-29 — Cobro con Stripe

El producto ya se cobra solo: se paga desde la web, el plan se activa sin que nadie toque nada y el
recibo aparece en el panel de la organización. Commit de cierre: `5b32389`.

### Añadido

- **Módulo `Billing`** con las cuatro capas del resto del proyecto: `Domain` (puerto
  `PaymentGatewayInterface`, entidades `PaymentEvent` y `SalesInvoice`, seis objetos de valor),
  `Application` (`StartCheckout`, `CompleteCheckout`, `HandlePaymentEvent`,
  `ApplyGatewaySubscription`, `RecordSalesInvoice`, `ReconcileSubscriptions`),
  `Infrastructure/Stripe` y `UI`.
- **`GET /checkout/{priceId}`** abre una sesión de pago alojada en Stripe y redirige. Antes de
  mandar a nadie a pagar comprueba cinco cosas: pasarela configurada, precio vigente, plan activo,
  importe mayor que cero y que la organización no tenga ya ese plan vivo.
- **`POST /webhooks/stripe`**, endpoint público cuya única autenticación es la firma HMAC-SHA256,
  con ventana de tolerancia y soporte de rotación de secreto. Aplica seis tipos de evento e ignora
  el resto sin devolver error.
- **Idempotencia por `INSERT` atómico** sobre `payment_event`: un evento repetido responde 200 sin
  duplicar nada, y si aplicarlo falla la reserva se suelta para que el reintento se procese.
- **`GET /checkout/gracias`** aplica la suscripción al volver del pago **sin esperar al webhook**, y
  no afirma que se ha cobrado cuando la pasarela no lo confirma.
- **`GET /facturacion`**: plan actual y recibos de la organización, con enlace al recibo alojado.
- **`app:billing:reconcile`**: pone al día las suscripciones contra la pasarela, con el mismo código
  que el webhook. Es la red de seguridad para los eventos que Stripe deja de reintentar a los tres
  días.
- **`stripe` en el panel de integraciones**: clave secreta y secreto de firma del webhook, cifrados
  por organización.
- **Migración `Version20261028233512`** con `payment_event` y `sales_invoice`.
- **Contratar desde la web**: los precios de `/precios` y de la portada llevan al checkout cuando la
  pasarela está configurada y el plan tiene precio de venta, con un botón por periodo para no
  esconder el anual. El menú del backoffice gana la entrada de **Facturación**.

### Corregido

Cuatro defectos de producción que encontraron los tests de la fase, no una prueba manual:

- `StripeWebhookController` invocaba el manejador como método en vez de como invocable: **500 en
  cada evento**.
- `PaymentEventRepository::claim()` insertaba el booleano sin tipo explícito; DBAL lo enlazaba como
  cadena y PostgreSQL lo rechazaba (`SQLSTATE[22P02]`).
- `PaymentEventRepository::save()` usaba `EntityManager::merge()`, **eliminado en ORM 3**.
- El `INSERT` que choca con el índice único dejaba la transacción de PostgreSQL en aborto
  (`25P02`) y contaminaba todo lo que viniera después.

### Cambiado

- `Subscription` gana `syncWithGateway()`: el periodo lo dicta la pasarela, no se calcula en casa
  (D-89).
- El catálogo de integraciones pasa de cuatro proveedores a cinco.

### Seguridad

- El cobro lo hace Stripe en su dominio: Suscripta no recibe ni almacena datos de tarjeta.
- La verificación de la firma tiene su propio test con once casos, incluida la rotación de secreto
  con varias `v1` y la firma fuera de ventana.

### Documentación

- `ARCHITECTURE.md` §20: el módulo `Billing`, la idempotencia y el reparto de responsabilidades con
  la pasarela.
- `DECISIONS.md` D-86 a D-90: factura de venta ≠ factura descubierta, reserva atómica, sin SDK,
  el periodo lo dicta la pasarela y la suscripción la crea el pago.
- `operations.md` §8: el cron de conciliación y qué significa cada código de respuesta del webhook.

---

## [Fase 23] — 2026-10-28 — Web corporativa

La raíz del dominio vende el producto. Antes, `/` redirigía al login y no había una sola página
pública. Commit de cierre: `bb643c4`.

### Añadido

- **Módulo `Website`** dentro de la misma aplicación (D-83), con las cuatro capas del resto del
  proyecto: `Application` (`PlanCard`, `BuildPricingPage`, `Faq`, `SendContactMessage`),
  `UI/Form`, `UI/Controller` y sus plantillas.
- **Once páginas públicas**: inicio, producto, cómo funciona, precios, integraciones, seguridad,
  preguntas frecuentes, contacto, aviso legal, privacidad y condiciones.
- **`GET /` bifurca por sesión**: portada sin sesión, redirección a `/dashboard` con ella. El panel
  se muda de `/` a `/dashboard`, con una sola URL por pantalla.
- **Precios leídos del catálogo** de la fase 22, con la rebaja anual calculada en el servidor y
  «A consultar» cuando un plan no tiene precio. Los límites salen de `entitlements.yaml` (D-64).
- **`sitemap.xml` y `robots.txt`** generados desde la tabla de rutas, con `%app.site.url%` y no con
  el host de la petición.
- **JSON-LD** de `Organization` en todas las páginas, `SoftwareApplication` en la portada y
  `FAQPage` en las preguntas frecuentes, generado desde el mismo `Faq` que pinta el HTML.
- **Formulario de contacto** con validación, `Reply-To` de quien escribe, límite de cinco mensajes
  por hora e IP y correo al buzón de la web. No guarda nada en la base de datos.
- **`assets/styles/site.css`**: hoja propia de ~1 400 líneas con propiedades CSS como variables,
  sin SCSS ni build (D-85). La web no carga Bootstrap ni una línea de JavaScript.
- **Tema de formularios propio** (`form/site_theme.html.twig`) y dos globales de Twig
  (`site_url`, `site_contact_email`).
- **`PublicPagesTest`**, 22 casos: las once páginas sin sesión, `/` con sesión, `/dashboard`
  cerrado, precios del catálogo, plan sin precio, catálogo vacío, envío real de contacto, rechazo
  sin consentimiento, límite de frecuencia, sitemap, robots y JSON-LD.

### Corregido

- **El envío del formulario de contacto daba 500**: `TemplatedEmail` no admite `email` en el
  contexto porque es una variable reservada. La dirección pasa a `sender`.
- **El limitador de contacto hacía fallar la segunda ejecución de la suite**: vive en caché y la
  caché sobrevive. Los tests fijan una IP distinta por prueba en lugar de bajar el límite.
- **Diez ficheros de test pedían `/` esperando el panel**, que ahora es la portada.
- **Acentos escapados** (`\u00e9`) que quedaron literales en una plantilla por un heredoc con
  comillas.

### Decidido

- **D-83** — la web vive en la misma aplicación y `/` redirige al panel cuando hay sesión.
- **D-84** — las páginas públicas se declaran una a una en `access_control` y un test las recorre
  todas sin sesión.
- **D-85** — CSS plano escrito a mano para la web, frente al SCSS compilado del panel.

---

## [Fase 22] — 2026-10-28 — Catálogo comercial en el backoffice

Lo que se vende deja de estar en el código: planes, precios con historial y características se
gestionan desde el backoffice. Commit de cierre: `5e983b0`.

### Añadido

- **Módulo de catálogo dentro de `Subscriptions`** con `Plan`, `PlanPrice` y `PlanFeature`. No va
  en `Catalog`, que describe proveedores del mundo que no se facturan (D-81).
- **Panel `/catalog`** con nueve rutas: listado, alta, edición, ficha, publicación y cierre de
  precios, y alta, edición y borrado de características.
- **Precios con historial**: vigente = `validTo IS NULL`. Publicar un precio cierra el vigente del
  mismo periodo y moneda; publicar el mismo importe no crea fila (D-82).
- **`ROLE_OPERATOR`** y el comando `app:user:grant-operator <email> [--revoke]` para concederlo. El
  rol no se hereda de ningún plan y el enlace del menú solo aparece si se tiene (D-80).
- **`app:catalog:seed`**: siembra idempotente de los planes por defecto, con `--dry-run` y
  opciones de precio y moneda.
- **`app:catalog:link-subscriptions`**: rellena el `planId` de las suscripciones de la fase 21
  emparejando por nombre normalizado, y solo cuando hay un único candidato.
- **`SubscriptionFormType` acepta plan**, y las listas y fichas de suscripciones muestran su
  nombre con enlace a la ficha del catálogo, en lugar del UUID suelto.
- Tablas `plan`, `plan_price` y `plan_feature` (migración `Version20261028230248`).

### Cambiado

- `PlanPriceRepository::findHistoryForPlan()` devuelve **solo precios cerrados**: la ficha ya
  enseña los vigentes arriba y el historial no los repite.

### Decisiones

- **D-80** — El panel del catálogo exige `ROLE_OPERATOR`, un rol que no se hereda de ningún plan.
- **D-81** — El catálogo comercial vive en `Subscriptions` y `Plan.code` es el puente con los
  límites de `entitlements.yaml`.
- **D-82** — El precio se cierra, no se sobrescribe; publicar el mismo importe no crea historial.

### Notas

- **Los límites siguen en `entitlements.yaml`** y la ficha los muestra en solo lectura: copiarlos
  en la base de datos crearía una segunda verdad (D-64).
- **Nada se borra**: un plan se retira (`isActive = false`) y un precio se cierra. Las
  características sí se borran, porque son texto de presentación.
- `PlanPrice::externalPriceId` queda listo para el `price_…` de Stripe de la fase 24.
- El catálogo está sembrado en `dev` («Gratis» y «Profesional»); la base de datos de `test` se
  siembra en los propios tests.

---

## [Fase 21] — 2026-10-28 — `Subscription` como entidad propia

El producto ya distingue lo que se paga del contrato que lo sostiene. Commit de cierre: `4c71499`.

### Añadido

- **Módulo `Subscriptions`** con la entidad `Subscription`: plan, periodo en curso, estado de
  cobro, fechas de alta y baja, y referencia externa del cobrador.
- **Panel `/subscriptions`**: alta, edición, ficha y ciclo de vida (activar, pausar, reanudar,
  renovar, no renovar, eliminar), con filtros por estado y búsqueda por nombre.
- **`SubscriptionStatus`** (`trialing`, `active`, `past_due`, `paused`, `canceled`) con
  `isLive()`, `isPaid()` y `isFinal()`. No se reutiliza `ServiceStatus`: `past_due` es un estado
  de cobro que un servicio descubierto en el correo nunca tendrá (D-78).
- **Periodo calculado con aritmética de calendario**: una suscripción mensual del 31 de enero
  renueva el 28 de febrero, no el 2 de marzo.
- **Enlace opcional con un servicio del inventario**, por UUID suelto y sin clave ajena: un
  servicio detectado en una factura no se convierte en suscripción por existir.
- Tabla `subscription` con tres índices (migración `Version20261028224739`).
- Entrada «Suscripciones» en el menú de operación del backoffice.

### Decisiones

- **D-78** — `Subscription` es una entidad propia y no un `Service` con más campos.
- **D-79** — El nombre de la suscripción se congela en el alta; el importe no se guarda.

### Notas

- La migración **no rellena datos**: el plan de la fase preveía enlazar los servicios `MANUAL` con
  su plan del catálogo, pero el catálogo es la fase 22. El relleno se hace allí.
- La API pública no cambia en esta fase; el contrato se amplía en la 24.

---

## [Fase 20] — 2026-10-28 — Webhooks de salida

Un integrador deja de tener que preguntar: Suscripta le avisa cuando pasa algo, firmado.
Commit de cierre: `bd646a8`.

### Añadido

- **Suscriptores de webhook** (`/webhooks`): alta, edición, pausa, borrado, secreto mostrado una
  única vez al crear y recuperable desde la ficha con un `POST` explícito, y botón «Enviar
  prueba» que hace una entrega real y enseña el código de respuesta.
- **Cinco eventos**: `service.created`, `proposal.created`, `proposal.confirmed`,
  `charge.detected` y `ai.budget_exhausted`. Este último se emite **una vez por periodo**, no una
  vez por correo, para que agotar el presupuesto no genere cien avisos idénticos.
- **Firma HMAC-SHA-256** del cuerpo con el timestamp dentro de lo firmado
  (`X-Suscripta-Signature: t=…,v1=…`), verificación en tiempo constante y tolerancia de 300 s.
- **Reintentos propios**: ocho envíos (30 s, 2 min, 10 min, 1 h, 6 h, 24 h, 24 h) y abandono.
  El transporte de Messenger va con `max_retries: 0` para que solo haya una política.
- **Idempotencia real**: el cuerpo se serializa una vez y se guarda; el reintento manda los
  mismos bytes con la misma `Idempotency-Key`.
- **Comando `app:webhooks:maintain`** para reencolar lo vencido y abandonar lo caducado, con
  `--limit` y `--dry-run`.
- Tablas `webhook` y `webhook_delivery` (migración `Version20261028215037`).
- Documentación de integración en [`docs/api/webhooks.md`](docs/api/webhooks.md): contrato,
  cabeceras, catálogo de eventos, verificación de la firma con ejemplo en PHP, Python y Node.js,
  política de reintentos y buenas prácticas del receptor.

### Corregido

- **Clasificación de documentos.** `DeterministicExtractor::classify()` evaluaba
  `RENEWAL_NOTICE` antes que `INVOICE`, de modo que una factura que menciona «se renovará» se
  tipaba como `OTHER`. El orden pasa a `PRICE_CHANGE → PLAN_CHANGE → INVOICE → RECEIPT →
  RENEWAL_NOTICE → UNKNOWN`: el tipo del documento lo decide lo que **es**, no lo que anuncia.
- **El transporte `webhooks` no lo consumía ningún worker.** Estaba declarado en
  `messenger.yaml` y las entregas se encolaban, pero ni el worker de desarrollo ni el de
  producción lo tenían en su lista de `messenger:consume`: en producción se habrían quedado en
  `PENDING` para siempre. Añadido a los dos, al `Makefile` (con `make worker-webhooks`) y al cron
  documentado en `docs/operations.md`.
- **Recuento de intentos de una entrega.** `abandon()` no incrementaba el contador, así que tras
  ocho envíos el registro decía siete. Ahora el intento que provoca el abandono también cuenta, y
  `maxAttempts()` pasa a llamarse `maxRetries()` con un `maxDeliveries()` explícito al lado: el
  nombre anterior se leía como «envíos» y contaba reintentos.

### Seguridad

- **Guarda anti-SSRF en las URLs de webhook** (D-77, commit `4ed28dd`). El webhook hace la petición desde dentro de
  la red del servidor, así que la URL que configura un administrador era un cliente HTTP
  arbitrario: `https://10.0.0.5/`, `https://169.254.169.254/` (metadatos de la nube) o
  `https://localhost/`. Ahora se comprueba el esquema, **todas** las direcciones a las que resuelve
  el host (no solo la primera), 14 rangos IPv4 y 8 IPv6 —incluidas las IPv4-mapeadas
  `::ffff:10.0.0.1`— y los literales IP. Se valida al dar de alta el suscriptor (el error se
  explica en el campo) y otra vez en cada entrega, donde un destino interno abandona en el acto
  sin reintentos. La conexión se **ata** a la dirección comprobada (`resolve`) para que un cambio
  de DNS no la convierta en interna, y no se siguen redirecciones (`max_redirects: 0`), porque un
  `302` hacia los metadatos es el mismo ataque con un salto intermedio.
- `WEBHOOK_ALLOWED_PRIVATE_HOSTS`: allowlist de hosts que se saltan la comprobación. Vacía por
  defecto; `host.docker.internal` en `.env.test`.
- El resolutor DNS es un puerto (`HostResolverInterface`), y los tests usan
  `ControllableHostResolver` en lugar del DNS de la máquina.

### Documentación

- `docs/api/webhooks.md` § «Qué URLs se rechazan»: la tabla de casos, por qué se comprueban todas
  las direcciones, por qué se fija la IP y por qué no se siguen redirecciones.
- `docs/DECISIONS.md`: D-77.
- `docs/ROADMAP.md`: fase 20 cerrada con su tabla de entregado, decisiones y defectos; fases 21 a
  25 planificadas con alcance y verificación; tabla de deuda técnica conocida; **todas las
  preguntas abiertas respondidas** por el usuario.
- `docs/DECISIONS.md`: D-73 a D-76.
- `docs/ARCHITECTURE.md`: el módulo `Webhooks` y su lugar en el pipeline.

---

## [Fase 19] — 2026-10-28 — Cierre, documentación y publicación

El proyecto se puede entregar a alguien que no lo ha visto.

### Añadido

- `README.md` de producto: qué es Suscripta, estado real, arranque en cinco minutos, tabla de
  órdenes, cómo integrar la API e índice de los doce documentos. El contenido de
  `symfony-docker` queda al final, etiquetado como infraestructura.
- Este CHANGELOG, con una sección por fase.
- `docs/deployment.md`: guía del **primer** despliegue, complementaria a `docs/operations.md`.

### Corregido

- El botón «rellenar credenciales de demo» del login no hacía nada
  (`document.getElementByById` en lugar de `getElementById`).

---

## [Fase 18] — 2026-10-28 — API pública

La API deja de ser interna: otros sistemas pueden leer y escribir en Suscripta sin pasar por la
interfaz.

### Añadido

- API REST versionada bajo `/api/v1` (13 rutas): servicios, propuestas, documentos, cuentas de
  correo, alertas, métricas, plan y cobros próximos.
- Autenticación por clave de API con dos cabeceras admitidas (`Authorization: Bearer` y
  `X-Api-Key`), ámbitos `read`/`write` y caducidad opcional.
- Panel de claves en el backoffice (`/api-keys`): alta, secreto mostrado una única vez y
  revocación con CSRF.
- Limitador de peticiones `api`: 300/minuto por clave, ventana deslizante, con cabeceras
  `X-RateLimit-*` y `Retry-After`.
- CORS configurable por lista blanca (`API_CORS_ALLOWED_ORIGINS`), con preflight atendido antes
  del router.
- Errores en `application/problem+json` con `violations[]` en español para los `422`.
- Contrato OpenAPI 3 navegable en `/api/doc` y descargable en `/api/doc.json`.
- Documentación de integración, catálogo de errores, versionado y política de deprecación en
  `docs/api/`.
- 35 tests funcionales de API (526 aserciones): autenticación, ámbitos, aislamiento entre
  organizaciones, CORS, contrato, rate limit, panel de claves y OpenAPI.

### Corregido

- **Preflight CORS devolvía 405**: el listener corría después del router y el router rechazaba
  el `OPTIONS`. Ahora escucha con prioridad 40, por delante de `RouterListener` (32).
- **Los `422` llegaban sin `violations`**: en Symfony 8 la `ValidationFailedException` viaja como
  `previous` de una `HttpException`, así que el listener no la encontraba. Ahora la desempaqueta.
- **El panel de claves devolvía 500**: `ApiKeyFormType` declaraba `choices` como mapa
  etiqueta→string mientras `choice_label` esperaba un enum, y usaba la opción inexistente
  `placeholder_value`.
- **La pantalla del secreto devolvía 500**: `created.html.twig` pasaba una URL literal a
  `url()`, que espera un nombre de ruta.
- **`match_reasons` publicaba `["Array","Array"]`**: la vista casteaba a `string` una lista de
  estructuras. Ahora viaja como objetos `{signal, weight, detail?}`.

## [Fase 17] — 2026-10-22 — Preparación SaaS

### Añadido

- Planes y cuotas por organización, con límites verificados antes de consumir recursos.
- Onboarding guiado desde el registro hasta el primer servicio confirmado.
- Observabilidad operativa: métricas de negocio y de pipeline, panel de estado y comandos de
  operación.
- Runbook de operación en `docs/operations.md`.

## [Fase 16] — 2026-10-22 — Hardening de seguridad y RGPD

### Añadido

- Cifrado de credenciales de correo en reposo con rotación de clave.
- Política de retención y purga de datos, exportación y borrado a petición del interesado.
- Cabeceras de seguridad y CSP con nonce por respuesta.
- Auditoría de acciones sensibles ampliada.
- `docs/SECURITY.md`: modelo de amenazas, acceso al correo y tratamiento de datos personales.

### Corregido

- Endurecimiento de la comparación de tokens y de los flujos de recuperación de contraseña.

## [Fase 15] — 2026-10-22 — Detección de cambios

### Añadido

- Detección de subidas de precio, cambios de periodicidad y cobros duplicados.
- Separación explícita entre **hechos** (lo que consta en la factura) e **inferencias** (lo que el
  sistema deduce), visible en la interfaz.

## [Fase 14] — 2026-10-22 — Control de coste de IA y observabilidad

### Añadido

- Presupuesto de IA por organización, con corte automático al agotarse.
- Medición de coste por análisis en microcéntimos y desglose por proveedor de IA.
- Panel de integraciones: credenciales de proveedores y elección del modelo de IA **para cada
  necesidad** (extracción, clasificación, emparejamiento), con prueba de conexión.
- Métricas de pipeline y trazas de cada nivel de análisis.

## [Fase 13] — 2026-10-22 — Aprendizaje del buzón

### Añadido

- El sistema aprende de las decisiones del usuario: remitentes y asuntos descartados dejan de
  analizarse, y los confirmados suben de prioridad.
- Reglas aprendidas visibles y revocables desde el backoffice.

## [Fase 12] — 2026-10-22 — Service matching y descubrimientos

### Añadido

- Emparejamiento de facturas con servicios existentes y detección de recurrencia.
- Deduplicación de propuestas, caducidad de las antiguas y revisión en lote.
- Alertas de servicio nuevo y *read model* de descubrimientos.
- Cada propuesta explica **por qué** se propone: señales, pesos y detalle.

## [Fase 11] — 2026-10-21 — IA económica y avanzada

### Añadido

- Niveles 5–7 del pipeline: IA económica para extracción y IA avanzada para los casos difíciles.
- Validación de la salida del modelo contra el esquema antes de aceptarla.
- Integraciones de IA configurables desde el panel, con credenciales cifradas.

## [Fase 10] — 2026-10-21 — Extracción determinista

### Añadido

- Niveles 3–4: extracción por reglas y parsers, y conocimiento de proveedores (plantillas por
  remitente).
- Caché de extracción y clasificador explicable.
- La inmensa mayoría de las facturas se resuelven **sin IA**.

## [Fase 9] — 2026-10-05 — Pipeline: filtros y billing score

### Añadido

- Niveles 0–2: ingesta, extracción de metadatos, filtros baratos y *billing score*.
- Métricas del pipeline y decisiones explicables por correo.

## [Fase 8] — 2026-10-05 — Conexión de correo completa

### Añadido

- Conexión IMAP genérica con validación de credenciales y sincronización incremental.
- Ingesta por reenvío a una dirección dedicada del tenant.
- Soporte multi-proveedor y estados de conexión con diagnóstico.

## [Fase 7] — 2026-10-05 — Documentos

### Añadido

- Adjuntos y documentos vinculados a servicios y facturas.
- Almacenamiento con deduplicación por huella y descarga autorizada.

## [Fase 6] — 2026-10-05 — Renovaciones, alertas y notificaciones

### Añadido

- Avisos de renovación antes del cobro, con preferencias por usuario y por canal.
- Notificaciones en la aplicación y por correo.

## [Fase 5] — 2026-10-05 — Historial de precios y cambios

### Añadido

- Historial de precios con precio vigente (`validTo IS NULL`) y variaciones calculadas.
- Evolución histórica del gasto.

## [Fase 4] — 2026-10-05 — Dashboard completo

### Añadido

- Panel con gasto total, desglose por categoría, ranking de servicios y ventanas de cobro.

## [Fase 3] — 2026-10-05 — Vertical end-to-end

### Añadido

- Recorrido completo IMAP → factura → propuesta → confirmación → dashboard.
- Bandeja de descubrimientos con confirmación y descarte.

## [Fase 2] — 2026-10-05 — Servicios manuales

### Añadido

- Catálogo global de proveedores y categorías, con entradas propias del tenant.
- `Service`, `ServicePrice` y `ServiceEvent` (append-only).
- CRUD completo con validación en español y cálculos de dominio (equivalente mensual, coste
  anual, próximo cobro).
- Auditoría de acciones sensibles.

## [Fase 1] — 2026-10-05 — Identidad y multi-tenancy

### Añadido

- `User`, `Organization` y `Membership`, con organización personal automática al registrarse.
- Registro, login, logout, verificación de correo y recuperación de contraseña.
- `TenantContext` y filtro de Doctrine: aislamiento entre organizaciones garantizado en la capa
  de persistencia.
- Tests de aislamiento que fallan si se desactiva el filtro.

## [Fase 0] — 2026-10-05 — Base del proyecto

### Añadido

- Symfony 8 sobre la plantilla FrankenPHP/Caddy, con Doctrine ORM y migraciones sobre PostgreSQL.
- Messenger con transporte Doctrine y transporte de fallos.
- AssetMapper, Turbo, Stimulus y Mercure. Sin Node ni `npm`.
- PHPUnit, PHPStan nivel 8 y PHP-CS-Fixer; CI con base de datos de test y `schema:validate`.
- Estructura modular vacía (`src/Shared`, `src/Identity`, …).

---

## Cómo se escribe una entrada

Al cerrar una fase, añadir una sección `## [Fase N] — AAAA-MM-DD — Título` con las listas
`Añadido`, `Cambiado`, `Corregido` y `Eliminado` que apliquen. Cada corrección debe decir qué
fallaba y qué se hizo, no solo «se arregló». Cuando el proyecto tenga etiquetas, las fases se
convertirán en versiones y este archivo seguirá la convención estándar.
