# Seguridad y privacidad

> Estado: **propuesta inicial**. Este documento define los requisitos de seguridad y privacidad
> que la implementación deberá cumplir. La aplicación maneja información financiera y acceso a
> correo electrónico: el nivel de exigencia es alto desde el primer commit.

## 1. Principios

1. **Acceso mínimo.** Solo se pide lo estrictamente necesario para descubrir servicios
   recurrentes.
2. **Solo lectura del correo.** Nunca se envía, modifica, mueve ni elimina correo.
3. **Nada de secretos en claro.** Ni en base de datos, ni en logs, ni en el repositorio.
4. **Aislamiento estricto entre organizaciones.** Ninguna consulta puede cruzar tenants.
5. **Minimización de datos.** No se almacena el cuerpo completo de los mensajes si no es
   necesario.
6. **El usuario manda.** Puede desconectar su correo, borrar documentos y borrar su cuenta con
   sus datos.
7. **Nada sale a terceros sin decisión explícita.** El envío de documentos a proveedores de IA
   externos requiere consentimiento documentado.
8. **La privacidad es arquitectura, no marketing.** Los principios de este documento son
   requisitos de diseño verificables, no argumentos de venta. Se separan explícitamente
   **correo**, **documentos** y **datos estructurados**, con políticas de retención y borrado
   distintas para cada uno (§6).
9. **Sin afirmaciones absolutas.** **No se afirma "100 % privado", "100 % UE" ni equivalentes**
   hasta que la implementación y los proveedores realmente utilizados permitan sostenerlo
   (ver `DECISIONS.md` D-25). La privacidad se describe por lo que el sistema **hace**
   (solo lectura, minimización, cifrado, borrado, retención, registro de accesos), no por
   eslóganes.

## 2. Acceso al correo

### 2.1 Alcance

- **IMAP genérico** en v1, con conexión de **solo lectura**.
- Se seleccionan carpetas en modo lectura (`EXAMINE` / `SELECT` con `readonly`). Nunca se
  ejecutan `STORE`, `COPY`, `MOVE`, `EXPUNGE`, `APPEND` ni `DELETE`.
- Se leen únicamente cabeceras, estructura MIME y adjuntos relevantes. El cuerpo se procesa en
  memoria y **no se persiste completo**.
- **OAuth (Gmail, Microsoft) en fases posteriores**, siempre con scopes de solo lectura:
  - Gmail: `https://www.googleapis.com/auth/gmail.readonly`
  - Microsoft Graph: `Mail.Read` (nunca `Mail.ReadWrite` ni `Mail.Send`)

### 2.2 Credenciales

- Las contraseñas de aplicación IMAP **nunca** se guardan en texto plano.
- Cifrado con **libsodium** (`sodium_crypto_secretbox`), clave de 32 bytes fuera de la base de
  datos (variable de entorno / gestor de secretos).
- Cada registro cifrado incluye: versión de clave (`keyId`), nonce aleatorio y ciphertext. El
  formato permite **rotación de clave** sin re-cifrar todo de golpe.
- Los tokens OAuth se cifran con el mismo mecanismo y se refrescan de forma controlada.
- Las credenciales **nunca** aparecen en logs, mensajes de excepción, trazas de Messenger ni
  respuestas HTTP. Los DTOs de credenciales implementan `__debugInfo()` para redactar.
- Al desconectar una cuenta se **eliminan** credenciales y tokens (no se desactivan: se borran).

### 2.3 Superficie de riesgo

- Un fallo de conexión no debe filtrar la contraseña en el mensaje de error mostrado al
  usuario: se registra el error técnico y se muestra un mensaje genérico.
- Los errores de autenticación repetidos desactivan la cuenta (`status = error`) y generan una
  notificación, sin reintentos infinitos.

### 2.4 Ingesta por reenvío

La vía de reenvío (`DECISIONS.md` D-21) no requiere credenciales, pero introduce su propia
superficie de riesgo:

- Cada organización/cuenta tiene una **dirección de ingesta única y no adivinable**.
- Se **verifica el remitente**: solo se procesan correos cuyo `From`/`Return-Path` esté
  autorizado por el usuario, o que incluyan un token de verificación. El resto se descarta y se
  registra.
- Se aplican límites de tamaño y de frecuencia por dirección para evitar abuso.
- El contenido reenviado se trata exactamente igual que el obtenido por IMAP: minimización,
  sin cuerpo completo, y misma política de retención.
- La dirección puede rotarse y desactivarse desde la UI.

## 3. Documentos

- Almacenamiento **privado**, fuera del directorio público. Nunca accesibles por URL directa.
- La descarga pasa siempre por un controlador que verifica autenticación y pertenencia a la
  organización.
- `DocumentStorageInterface` con implementación local (`var/storage`) en v1 y S3/Backblaze
  después. En S3: bucket privado, URLs firmadas de corta duración, sin ACL públicas.
- Cada documento guarda `checksumSha256` para deduplicar y detectar corrupción.
- Borrado: soft delete (`deletedAt`) con purga física posterior según política de retención.
- Los documentos se asocian a un servicio y, si procede, a una factura. No hay explorador de
  archivos genérico.

## 4. Extracción e inteligencia artificial

### 4.1 Extracción por capas

**No se asume que todos los documentos deban enviarse a una API externa de IA.** La extracción
se aplica en cascada y se detiene en cuanto hay suficiente confianza (ver `ARCHITECTURE.md`
§4.11):

| Capa | Datos que salen del sistema |
|---|---|
| 1. Determinista (cabeceras, metadatos, texto embebido) | No |
| 2. Reglas (patrones de proveedor, plantillas, importes, periodicidad) | No |
| 3. OCR (solo documentos sin capa de texto) | No |
| 4. IA (solo si las anteriores no alcanzan el umbral) | **Sí, si el proveedor es externo** |

Las capas 1–3 se ejecutan **siempre en nuestra infraestructura**. La capa 4 es la única que
puede implicar transferencia a un tercero, y solo con consentimiento explícito.

### 4.2 Reglas de la capa de IA

- **Por defecto, sin proveedor externo.** v1 funciona con reglas deterministas. La IA es una
  mejora opcional.
- Toda llamada a IA pasa por `AiProviderInterface`. El dominio no conoce ningún proveedor, y
  **el proveedor es intercambiable** (incluido un modelo autoalojado) sin tocar el dominio.
- **Enviar documentos o texto a un proveedor externo requiere una decisión explícita y
  documentada** (ver `DECISIONS.md` D-08), con:
  - Consentimiento del usuario a nivel de organización.
  - Contrato de encargado de tratamiento (DPA) con el proveedor.
  - Registro en la política de privacidad.
- Antes de enviar contenido se aplica **minimización y redacción**: se envían solo los campos
  necesarios (importes, fechas, nombre del proveedor), no el documento completo, salvo que sea
  imprescindible.
- **Nunca** se envían credenciales, tokens ni datos de otras organizaciones.
- Los resultados de IA son **propuestas**, nunca escrituras directas: pasan por `Discovery` y
  requieren confirmación.
- Se registra qué proveedor y qué versión de modelo procesó cada documento (trazabilidad), y
  qué capa resolvió cada extracción (`extractionMethod`), para poder medir el valor real de la
  IA y reducir su uso.

### 4.3 Evolución hacia procesamiento más privado

El objetivo es poder migrar a procesamiento más privado o europeo (OCR y modelos autoalojados)
**sin reescribir el dominio**. La arquitectura lo permite porque el dominio solo ve el
resultado de la extracción, no cómo se obtuvo.

**Mientras eso no esté implementado y verificado, no se afirma que el procesamiento sea
"100 % privado" ni "100 % UE"** (D-25).

## 5. Aislamiento entre organizaciones (multi-tenant)

- Base de datos única, esquema único, `organization_id` en toda entidad de negocio.
- **Doctrine Filter** obligatorio que añade el filtro de organización a toda consulta.
- El `organization_id` se deriva siempre de la sesión autenticada, **nunca** de parámetros de
  la petición.
- Los mensajes de Messenger llevan el `organizationId` en un *stamp*; el handler lo restaura
  en el `TenantContext` y falla si no hay tenant válido.
- **Tests de aislamiento obligatorios** en CI: un usuario de la organización A no puede leer,
  modificar ni borrar datos de la organización B, ni siquiera conociendo el UUID.
- Los identificadores son UUIDv7 (no enumerables), pero el aislamiento **no depende** de que
  sean difíciles de adivinar.

## 6. Retención y eliminación

**Tres categorías de datos, con políticas distintas.** La separación es deliberada y forma
parte del diseño:

| Categoría | Qué contiene | Sensibilidad |
|---|---|---|
| **Correo** | `EmailAccount`, `EmailMessage`, cursores, extractos de cuerpo | Alta: comunicaciones de terceros |
| **Documentos** | `Document` (adjuntos y ficheros conservados) | Alta: contenido financiero |
| **Datos estructurados** | `Service`, `ServicePrice`, `Invoice`, `Discovery`, `Alert` | Media: datos del negocio del usuario |

El correo y los documentos son **evidencia temporal**; los datos estructurados son **el
producto**. Por eso el correo se purga antes y los servicios confirmados sobreviven a la
desconexión del buzón.

| Dato | Retención por defecto | Eliminación |
|---|---|---|
| Credenciales de correo | Mientras la cuenta esté conectada | Borrado inmediato al desconectar |
| Metadatos de mensajes (`EmailMessage`) | 12 meses (configurable) | Purga programada |
| Extracto de cuerpo (`bodyExcerpt`) | Solo mientras exista un descubrimiento pendiente | Borrado al resolver el descubrimiento |
| Documentos | Hasta que el usuario los borre | Soft delete + purga física |
| Facturas | Mientras exista el servicio | Cascada al borrar el servicio |
| Descubrimientos ignorados | 90 días | Purga programada |
| Alertas resueltas | 12 meses | Purga programada |
| `AuditLog` | 24 meses | Purga programada (append-only hasta entonces) |

- **Desconectar una cuenta de correo** elimina credenciales, cursores y mensajes asociados. Los
  servicios ya confirmados **permanecen** (son datos del usuario, no del correo).
- **Borrar la organización** elimina todos sus datos en cascada, incluidos documentos
  almacenados.
- **Exportación de datos** disponible (JSON/CSV) antes de cualquier borrado.

## 7. Auditoría

Se registra en `AuditLog` (append-only) toda acción sensible:

- Conexión, reconexión y desconexión de cuentas de correo.
- Cambios de credenciales o tokens.
- Descarga y borrado de documentos.
- Confirmación, edición o ignorado de descubrimientos.
- Cambios de precio y cancelaciones de servicios.
- Altas, bajas y cambios de rol de miembros de la organización.
- Exportación y borrado de datos.
- Cambios en la configuración de notificaciones y de privacidad.

Cada entrada incluye actor, acción, objetivo, metadatos, IP y user-agent. El log **no** contiene
secretos ni contenido de correo.

## 8. Protección de la aplicación

- **Autenticación:** Symfony Security, contraseñas con `argon2id` (o `bcrypt` si el entorno no
  soporta argon2). Verificación de email en el registro.
- **Sesiones:** cookies `HttpOnly`, `Secure`, `SameSite=Lax`; regeneración de ID al
  autenticar; cierre de sesión invalidando la sesión.
- **CSRF:** protección activa en todos los formularios.
- **XSS:** escape por defecto de Twig; `|raw` prohibido salvo justificación revisada.
- **Inyección SQL:** Doctrine/DBAL con parámetros vinculados. Sin SQL concatenado.
- **SSRF:** las URLs de proveedores y de almacenamiento se validan contra una lista permitida.
- **Subida de ficheros:** validación de MIME real (no solo extensión), límite de tamaño,
  almacenamiento fuera del árbol público, nombre de fichero saneado.
- **Rate limiting:** en login, en el endpoint de conexión de correo y en las acciones de
  sincronización manual.
- **Cabeceras:** HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy`,
  `Content-Security-Policy` restrictiva.
- **Dependencias:** Dependabot ya configurado en el repositorio; revisión de vulnerabilidades
  en CI.

## 9. Secretos y configuración

- Ningún secreto en el repositorio. `.env` solo con valores de ejemplo; los reales en
  `.env.local` (ignorado) o en el gestor de secretos del entorno.
- Secretos requeridos: `APP_SECRET`, `APP_ENCRYPTION_KEY` (cifrado de credenciales),
  `DATABASE_URL`, credenciales de correo saliente, claves de proveedores de IA (opcionales).
- En producción, secretos vía variables de entorno del orquestador o Docker secrets, nunca en
  la imagen.
- Rotación documentada para `APP_ENCRYPTION_KEY` (soporte de `keyId` en el formato cifrado).

## 10. Cumplimiento (RGPD)

- **Base jurídica:** ejecución de contrato para los datos de cuenta; consentimiento explícito
  para el acceso al correo y para el envío a proveedores de IA.
- **Minimización:** solo metadatos y extractos; nunca el buzón completo.
- **Derechos:** acceso, rectificación, supresión, portabilidad (exportación) y oposición,
  implementados como casos de uso (`ExportOrganizationData`, `DeleteOrganizationData`).
- **Encargados de tratamiento:** registro de proveedores (hosting, email transaccional, IA) con
  DPA firmado.
- **Residencia de datos:** preferencia por hosting en la UE.
- **Registro de actividades de tratamiento** y política de privacidad publicada antes del
  lanzamiento.
- **Notificación de brechas:** procedimiento documentado.

## 11. Checklist de seguridad por fase

Antes de considerar terminada cada fase del roadmap:

- [ ] No hay secretos en el repositorio ni en logs.
- [ ] Toda entidad nueva de negocio tiene `organization_id` y está cubierta por el filtro.
- [ ] Existe test de aislamiento para los nuevos accesos a datos.
- [ ] Las acciones sensibles nuevas escriben en `AuditLog`.
- [ ] Los datos nuevos tienen política de retención definida.
- [ ] No se persiste contenido de correo más allá de lo estrictamente necesario.
- [ ] Las dependencias nuevas se revisan por vulnerabilidades y licencia.
- [ ] Si se añade una capa de extracción, se verifica que las capas 1–3 no envían datos fuera
      del sistema y que la capa de IA sigue desactivada por defecto.
- [ ] Ninguna afirmación de privacidad publicada supera lo que la implementación y los
      proveedores sostienen (D-25).
