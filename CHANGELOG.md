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

- Fase 19 — Cierre, documentación y publicación (este CHANGELOG, el README de producto y la
  guía de despliegue son parte de ella).
- Fase 20 — Webhooks de salida. Sin planificar.

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
