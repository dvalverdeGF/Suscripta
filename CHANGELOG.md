# Changelog

Todos los cambios relevantes de Suscripta. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el proyecto se rige por
[Versionado Semántico](https://semver.org/lang/es/).

Mientras el producto no alcance su primera versión pública (`1.0.0`), las entradas se agrupan
**por fase del roadmap** en lugar de por número de versión: cada fase es una unidad entregable
cerrada, verificada y commiteada. El detalle de lo que queda pendiente está en
[docs/ROADMAP.md](docs/ROADMAP.md).

## [No publicado]

### Pendiente

- Fase 23 — Web corporativa (once páginas). Planificada.
- Fase 24 — Checkout con Stripe. Planificada.
- Fase 25 — CSRF unificado con el gestor de sesión. Planificada.

---

## [Fase 22] — 2026-10-28 — Catálogo comercial en el backoffice

Lo que se vende deja de estar en el código: planes, precios con historial y características se
gestionan desde el backoffice. Commit de cierre: `PENDIENTE_SHA`.

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
