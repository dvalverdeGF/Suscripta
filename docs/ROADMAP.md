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

**IMAP no es una funcionalidad experimental de última fase.** Aparece en la fase 3 en su
versión mínima (conectar, leer, encontrar una factura) y se endurece en la fase 8. La
arquitectura lo contempla desde el principio (`ARCHITECTURE.md` §4.10, `DECISIONS.md` D-23).

## Fase 0 — Base del proyecto

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
- Prefiltro determinista por remitente/asunto/adjunto y **una** extracción por reglas para los
  proveedores más comunes (importe, moneda, periodicidad). Sin OCR, sin IA.
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

**Objetivo:** responder a las ocho preguntas del producto (`PRODUCT.md` §3.1).

- Próximos cobros (30/60 días) con totales.
- Coste recurrente: mensual equivalente, anual, por categoría, servicios más caros.
- Recuento de servicios por estado y categoría.
- Listado de servicios con filtros.
- Descubrimientos pendientes y alertas abiertas en portada.

**Verificación:** con datos de prueba, los totales del dashboard coinciden con los cálculos de
dominio; consultas sin N+1.

## Fase 5 — Historial de precios y cambios

**Objetivo:** hacer visible la evolución del coste.

- Vista de historial de precios por servicio con variación porcentual y absoluta.
- Detección determinista de cambio de precio (comparación de filas consecutivas).
- `ServiceEvent` de tipo `price_changed` y `plan_changed`.
- Evolución del gasto agregada por periodo.

**Verificación:** un cambio de precio produce la variación correcta; el historial es inmutable.

## Fase 6 — Renovaciones, alertas y notificaciones

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

## Fase 7 — Documentos

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

## Fase 8 — Conexión de correo completa (IMAP y reenvío)

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

## Fase 9 — Extracción por capas

**Objetivo:** convertir mensajes en datos estructurados, con el mínimo envío a terceros.

- Prefiltro determinista barato (remitente, asunto, adjuntos, dominios conocidos).
- `DocumentClassifierInterface` con implementación por reglas.
- `InvoiceExtractorInterface` con parser determinista (PDF/texto) donde sea fiable.
- **OCR** para documentos sin capa de texto, ejecutado en nuestra infraestructura.
- `AiProviderInterface` con implementación nula por defecto y adaptador opcional, activado
  solo con consentimiento explícito (D-08).
- Registro de `extractionMethod` por extracción, para medir el valor real de cada capa.
- Deduplicación por hash y por `messageId`.

**Verificación:** un conjunto de facturas de prueba se clasifica y extrae correctamente; sin
proveedor de IA configurado el sistema sigue funcionando; las capas 1–3 no envían datos fuera;
los fallos no bloquean el lote.

## Fase 10 — Detección de recurrencia y matching

**Objetivo:** decidir si un gasto es recurrente y a qué servicio pertenece.

- `RecurrenceDetectorInterface` determinista (intervalos entre facturas, importes).
- `ServiceDiscoveryInterface` para emparejar con servicios existentes (proveedor + importe +
  periodicidad).
- Cálculo de `nextChargeAt` a partir del historial.
- Puntuación de confianza (`confidenceScore`).

**Verificación:** con historiales sintéticos, la periodicidad detectada es correcta; los
emparejamientos ambiguos quedan en confianza baja; no se fija `nextChargeAt` con datos
irregulares.

## Fase 11 — Descubrimientos y revisión (completo)

**Objetivo:** el usuario confirma, corrige o ignora, con evidencia visible.

- `DiscoveryEvidence` con enlace a correo y documentos de origen.
- Bandeja de descubrimientos con evidencia, filtros y acciones en lote.
- Confirmar → crea `Service` + `ServicePrice` + `ServiceEvent`.
- Editar → permite corregir antes de confirmar.
- Ignorar → descarta y no vuelve a proponer lo mismo.
- Expiración de descubrimientos antiguos.
- Alertas de tipo `new_service_detected`.

**Verificación:** confirmar un descubrimiento crea el servicio con su precio inicial; ignorar
no vuelve a proponerlo; el flujo completo funciona sin intervención manual en la base de datos.

## Fase 12 — Detección de cambios

**Objetivo:** detectar lo que ha cambiado y avisar.

- Cambio de precio detectado desde facturas nuevas.
- Cambio de plan.
- Posible duplicado (inferencia).
- Factura recurrente que deja de recibirse (inferencia).
- Servicio sin actividad reciente (inferencia).
- Todas las inferencias marcadas con `isInference` y presentadas como sugerencias.

**Verificación:** cada tipo de alerta tiene test con datos sintéticos; las inferencias se
muestran como sugerencias, no como hechos.

## Fase 13 — Hardening de seguridad y RGPD

**Objetivo:** cumplir `SECURITY.md` de punta a punta.

- Exportación de datos (`ExportOrganizationData`).
- Borrado de organización y de cuenta de correo con cascada completa.
- Purga programada según política de retención (correo, documentos y datos estructurados por
  separado).
- Revisión de cabeceras, CSRF, rate limiting y validación de subidas.
- Auditoría completa de acciones sensibles.
- Revisión de dependencias y de secretos.
- Tests de aislamiento ampliados a todos los módulos.
- Revisión de las afirmaciones de privacidad publicadas: **solo se afirma lo que la
  implementación y los proveedores sostienen** (D-25).

**Verificación:** checklist de `SECURITY.md` §11 completo; exportar y borrar datos funciona;
ningún secreto en logs.

## Fase 14 — Preparación SaaS

**Objetivo:** dejar el producto listo para planes y crecimiento.

- `EntitlementCheckerInterface` con implementación de límites.
- Onboarding guiado (conectar correo o continuar sin él).
- Observabilidad: métricas de sincronización, extracción y alertas.
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
