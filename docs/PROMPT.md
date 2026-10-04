# Definición y arquitectura inicial del producto

Actúa como arquitecto de software senior y product engineer. Antes de escribir código, analiza el repositorio actual y prepara la arquitectura y documentación inicial de un nuevo Micro-SaaS.

## 1. Objetivo del producto

Queremos construir una aplicación SaaS para autónomos y pequeñas empresas que permita **controlar todos sus servicios recurrentes, suscripciones, renovaciones y costes**, evitando que tengan que recordar manualmente qué servicios pagan, cuánto cuestan y cuándo vuelven a cobrarles.

La propuesta de valor NO es simplemente "una aplicación de suscripciones".

El concepto central es:

> **Saber qué servicios estás pagando, cuánto te cuestan y cuándo tienes que actuar, sin tener que acordarte de nada.**

La aplicación debe permitir introducir servicios manualmente, pero una de sus principales ventajas será poder **descubrir automáticamente servicios y gastos recurrentes a partir del correo electrónico de facturación del usuario**.

## 2. Descubrimiento mediante correo electrónico

El usuario podrá conectar una cuenta de correo utilizada para recibir facturas y avisos de servicios.

Inicialmente debe contemplarse:

* IMAP como mecanismo genérico de acceso.
* Arquitectura preparada para incorporar posteriormente proveedores específicos mediante OAuth, como Gmail o Microsoft.
* Acceso exclusivamente de lectura.
* Nunca enviar, modificar o eliminar correos.
* Credenciales y tokens almacenados de forma segura y cifrada.
* Posibilidad de desconectar una cuenta y eliminar sus credenciales.
* Procesamiento asíncrono de los mensajes.

El sistema analizará los mensajes y adjuntos buscando:

* Facturas.
* Recibos.
* Confirmaciones de pago.
* Renovaciones.
* Cambios de precio.
* Cambios de plan.
* Avisos de vencimiento.
* Comunicaciones relacionadas con servicios recurrentes.

No queremos convertir el producto inicialmente en un gestor contable completo.

El objetivo es **descubrir y controlar servicios recurrentes**.

## 3. Flujo de descubrimiento

El proceso debe ser aproximadamente:

```text
Conectar correo
       ↓
Sincronizar mensajes
       ↓
Detectar posibles facturas / recibos
       ↓
Extraer información
       ↓
Detectar proveedor y servicio
       ↓
Detectar recurrencia
       ↓
Crear propuesta
       ↓
Usuario confirma / corrige
       ↓
Servicio registrado
```

La aplicación NO debe crear automáticamente información crítica sin supervisión cuando exista incertidumbre.

Debe existir un concepto de:

**"Descubrimientos pendientes de revisar"**

Ejemplo:

> Hemos encontrado 27 posibles servicios.

```text
OVH
39,90 €
Mensual
Confianza: alta

[Confirmar] [Editar] [Ignorar]
```

Otro ejemplo:

```text
Servicio detectado:
Microsoft 365

Últimos pagos:
12,50 €
12,50 €
13,75 €

Posible cambio de precio detectado.

[Revisar]
```

## 4. Modelo conceptual

Diseña un modelo de dominio adecuado, evitando sobrediseño.

Como punto de partida, contempla conceptos como:

* User
* Organization / Workspace, si consideras que merece la pena desde el inicio
* Service
* Provider
* Subscription / Contract
* Invoice / Receipt
* Payment
* Renewal
* EmailAccount
* EmailMessage
* Document
* Discovery
* Notification

Pero NO implementes entidades innecesarias únicamente porque aparezcan en esta lista.

Determina qué conceptos deben existir realmente y cuáles pueden ser propiedades de otros.

La aplicación debe poder representar como mínimo:

* Proveedor.
* Servicio.
* Plan.
* Categoría.
* Precio.
* Moneda.
* Periodicidad.
* Próximo cobro.
* Fecha de renovación.
* Fecha de contratación.
* Historial de precios.
* Documentos/facturas relacionados.
* Cuenta de correo de origen.
* Estado: activo, cancelado, pausado, pendiente de revisión.
* Notas.
* Método de pago, cuando pueda conocerse.

## 5. Funcionalidad principal

El usuario debe poder consultar rápidamente:

### Próximos cobros

Ejemplo:

```text
PRÓXIMOS COBROS

12 OCT
OVH              39,90 €

15 OCT
Microsoft 365    12,50 €

21 OCT
GitHub           10,00 €

25 OCT
Adobe            24,99 €

Total próximo mes: 87,39 €
```

### Coste recurrente

Mostrar:

* Coste mensual equivalente.
* Coste anual estimado.
* Coste por categoría.
* Evolución del gasto.
* Servicios más caros.

Por ejemplo:

```text
GASTO RECURRENTE

Mensual: 237,20 €
Anual:   2.846,40 €
```

## 6. Alertas inteligentes

El sistema debe poder detectar y avisar sobre situaciones como:

* Próxima renovación.
* Próximo cobro.
* Aumento de precio.
* Cambio de plan.
* Nueva suscripción detectada.
* Servicio aparentemente duplicado.
* Factura recurrente que deja de recibirse.
* Servicio cuyo coste ha cambiado.
* Renovación anual próxima.
* Servicio sin actividad/documentación reciente, cuando pueda determinarse razonablemente.

No inventes conclusiones.

Las alertas que impliquen inferencias deben indicar claramente que son sugerencias.

Ejemplo:

> **Posible aumento de precio**
>
> Tu servicio de OVH ha pasado de 39,90 € a 47,80 €.
>
> [Ver historial]

## 7. Histórico de precios

El precio NO debe ser simplemente un campo mutable.

Debemos poder conservar el histórico de costes.

Ejemplo:

```text
OVH

2025
39,90 €/mes

2026
47,80 €/mes
```

Esto permitirá posteriormente mostrar:

> Este servicio ha aumentado un 19,8 % durante el último año.

Diseña el modelo de forma que esto pueda hacerse correctamente.

## 8. Documentos

Las facturas y documentos encontrados en el correo deben poder asociarse al servicio correspondiente.

El sistema debe poder conservar:

* Fecha.
* Nombre del fichero.
* Tipo.
* Tamaño.
* Origen.
* Servicio asociado.
* Factura asociada.

La arquitectura debe permitir almacenamiento local inicialmente y facilitar posteriormente S3/Backblaze u otro almacenamiento compatible.

No conviertas esto en un gestor documental generalista.

## 9. IA / extracción

La extracción de datos debe estar abstraída mediante interfaces.

No acoples el dominio a un proveedor concreto de IA.

Por ejemplo:

```text
InvoiceExtractorInterface
DocumentClassifierInterface
ServiceDiscoveryInterface
```

Debe ser posible utilizar posteriormente diferentes proveedores.

La IA debe utilizarse para:

* Clasificación.
* Extracción de datos.
* Detección de proveedor.
* Detección de servicio.
* Detección de recurrencia.
* Detección de cambios.

Pero las reglas de negocio críticas deben permanecer en código determinista siempre que sea posible.

No utilices IA simplemente porque pueda utilizarse.

## 10. Arquitectura técnica

Si el repositorio no establece otra cosa, utiliza:

* PHP 8.4
* Symfony 8
* Doctrine ORM
* PostgreSQL
* Symfony Messenger
* Twig para la primera interfaz web
* Symfony Security
* Symfony Validator
* Symfony UID
* UUIDv7
* Docker

Utiliza las versiones estables actuales disponibles en el proyecto.

Preferencias arquitectónicas:

* Código en inglés.
* Comentarios y PHPDoc en español cuando sean necesarios.
* Mensajes de validación en español.
* Doctrine mediante Attributes.
* UUIDv7 para identificadores.
* SOLID.
* DRY.
* Boy Scout Rule.
* Arquitectura limpia cuando aporte valor real.
* No crear capas abstractas sin necesidad.
* No implementar patrones por dogma.

Para operaciones de negocio complejas, prioriza servicios de aplicación / casos de uso y procesamiento asíncrono con Messenger antes que controladores personalizados con lógica de negocio.

## 11. Procesamiento asíncrono

El análisis del correo NO debe bloquear la interfaz.

Diseña un pipeline basado en Messenger similar a:

```text
EmailSync
   ↓
MessageDiscovery
   ↓
AttachmentExtraction
   ↓
DocumentClassification
   ↓
DataExtraction
   ↓
ServiceMatching
   ↓
RecurringPaymentDetection
   ↓
DiscoveryCreation
```

Debe ser:

* Reintentable.
* Idempotente.
* Observable.
* Tolerante a fallos.
* Capaz de procesar grandes cantidades de mensajes sin bloquear la aplicación.

Evita descargar/procesar indefinidamente el buzón completo en cada sincronización.

Debe existir algún mecanismo para conocer hasta dónde se ha sincronizado cada cuenta.

## 12. Privacidad y seguridad

Este punto es crítico.

La aplicación tendrá acceso a información financiera y potencialmente sensible.

Diseña desde el principio:

* Acceso mínimo.
* Solo lectura del correo.
* Cifrado de credenciales.
* Separación estricta de usuarios/organizaciones.
* No almacenar mensajes completos si no son necesarios.
* Política clara de retención.
* Posibilidad de eliminar documentos.
* Posibilidad de eliminar una cuenta de correo.
* Auditoría de acciones sensibles.
* Protección frente a acceso cruzado entre tenants.
* No enviar documentos a proveedores externos de IA sin una decisión explícita y documentada.
* Abstracción de proveedores de IA.

No almacenar contraseñas de correo en texto plano.

## 13. Multi-tenant

La aplicación será SaaS.

Diseña desde el principio para aislamiento entre organizaciones.

NO utilizar schema por tenant.

Usar relaciones mediante `organization_id`/equivalente y aplicar el aislamiento desde la capa de aplicación y seguridad.

Analiza si realmente necesitamos `Organization` desde la primera versión o si puede comenzar con `User` y evolucionar posteriormente.

No introduzcas complejidad empresarial innecesaria.

## 14. Notificaciones

El usuario deberá poder configurar avisos sobre:

* Renovaciones.
* Próximos cobros.
* Cambios de precio.
* Nuevos servicios detectados.

Inicialmente puede bastar con email y notificaciones dentro de la aplicación.

La arquitectura debe permitir añadir posteriormente otros canales sin modificar el dominio.

## 15. Dashboard

El dashboard debe responder inmediatamente a estas preguntas:

1. ¿Cuánto estoy pagando periódicamente?
2. ¿Qué me van a cobrar próximamente?
3. ¿Qué servicios tengo?
4. ¿Qué ha cambiado?
5. ¿Qué cosas requieren mi atención?

No quiero un dashboard lleno de gráficos inútiles.

Prioriza información accionable.

## 16. UX

La aplicación debe ser extremadamente sencilla.

El usuario no debería necesitar introducir manualmente decenas de servicios.

El onboarding ideal sería:

```text
Crear cuenta
     ↓
Conectar correo
     ↓
Analizar facturas
     ↓
"Tenemos 27 servicios para ti"
     ↓
Confirmar / corregir
     ↓
Dashboard
```

Debe existir siempre la posibilidad de trabajar manualmente sin conectar el correo.

## 17. Modelo de negocio

No implementes todavía pagos, planes ni facturación del SaaS.

Pero diseña la aplicación teniendo en cuenta que posteriormente puede existir:

* Plan gratuito limitado.
* Plan profesional.
* Límites por número de servicios.
* Límites de cuentas de correo.
* Límites de documentos/procesamiento.

No hagas que estos límites contaminen el dominio desde el principio.

## 18. Documentación obligatoria

Antes de modificar código, crea:

```text
/docs/ARCHITECTURE.md
/docs/PRODUCT.md
/docs/ROADMAP.md
/docs/SECURITY.md
/docs/DECISIONS.md
```

### PRODUCT.md

Explicar:

* Problema.
* Usuario objetivo.
* Propuesta de valor.
* Funcionalidades.
* Qué NO es el producto.
* Flujo principal.
* Diferenciación.
* Casos de uso.

### ARCHITECTURE.md

Explicar:

* Arquitectura.
* Módulos.
* Entidades.
* Casos de uso.
* Messenger.
* Persistencia.
* Integraciones.
* Multi-tenancy.
* Seguridad.

### SECURITY.md

Explicar especialmente:

* Acceso al correo.
* Credenciales.
* Tokens.
* Documentos.
* IA.
* aislamiento de tenants.
* eliminación de datos.
* retención.

### ROADMAP.md

Dividir el desarrollo en fases pequeñas y verificables.

Por ejemplo:

1. Base del proyecto.
2. Usuarios/autenticación.
3. Servicios introducidos manualmente.
4. Dashboard.
5. Renovaciones y notificaciones.
6. Historial de precios.
7. Conexión IMAP.
8. Descubrimiento de facturas.
9. Extracción de información.
10. Detección de recurrencia.
11. Revisiones/confirmaciones.
12. Hardening de seguridad.
13. Preparación SaaS.

No asumas que este orden es definitivo: analízalo y modifícalo si existe una secuencia mejor.

### DECISIONS.md

Registrar decisiones arquitectónicas relevantes y su motivo.

## 19. Investigación previa

Antes de fijar definitivamente el producto, identifica también:

* Qué soluciones similares existen.
* Qué hacen.
* A qué público se dirigen.
* Qué funcionalidades ofrecen.
* Qué hueco podría ocupar nuestro producto.

No quiero que inventes una diferenciación simplemente porque suene bien.

Si encuentras productos claramente competidores, documenta sus ventajas y debilidades.

El objetivo es encontrar un producto pequeño que pueda competir por **simplicidad, enfoque y utilidad**, no intentar construir un nuevo ERP.

## 20. Restricciones

MUY IMPORTANTE:

* No empieces implementando funcionalidades todavía.
* Primero inspecciona el repositorio.
* No instales paquetes sin justificarlo.
* No modifiques configuración existente salvo que sea necesario para documentar una decisión.
* No generes código de infraestructura todavía.
* No crees entidades todavía.
* No hagas migraciones.
* No hagas un frontend todavía.

Primero quiero documentación y arquitectura.

Cuando termines:

1. Resume qué has encontrado en el repositorio.
2. Explica las decisiones arquitectónicas.
3. Indica qué dudas o riesgos quedan abiertos.
4. Indica qué funcionalidades NO debemos construir en la primera versión.
5. Presenta el roadmap.
6. No comiences la implementación hasta que la documentación esté completa.

## Criterio principal

No quiero una aplicación enorme.

Quiero un producto que haga excepcionalmente bien una cosa:

> **Descubrir y controlar automáticamente los servicios que una persona o pequeña empresa paga de forma recurrente, y avisarle antes de que algo importante ocurra.**

La conexión al correo es una **ventaja de automatización**, no el producto en sí.

Evita convertirlo en:

* ERP.
* Programa de contabilidad.
* Gestor documental genérico.
* Cliente de correo.
* CRM.
* Plataforma de facturación.

Si una funcionalidad no contribuye directamente a controlar servicios, costes, renovaciones o cambios, cuestiona si debe existir.
