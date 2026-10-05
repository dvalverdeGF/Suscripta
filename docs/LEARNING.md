Quiero que revises y actualices la arquitectura y documentación del proyecto para incorporar el **pipeline inteligente de análisis de correo** que describo a continuación.

No empieces todavía a implementar código. Primero analiza cómo encaja en la arquitectura existente y actualiza la documentación correspondiente.

# Objetivo

La aplicación recibe correos mediante IMAP y debe identificar automáticamente facturas, recibos, renovaciones y comunicaciones relacionadas con servicios recurrentes.

El objetivo NO es enviar cada correo a una IA.

El objetivo es construir un pipeline de **enriquecimiento progresivo**, donde cada mensaje se procese con el mecanismo más barato, rápido y determinista posible, recurriendo a IA solamente cuando sea necesario.

Además, el sistema debe aprender progresivamente los patrones del propio buzón para reducir el uso futuro de IA.

La regla fundamental es:

> **La IA debe resolver incertidumbre, no sustituir a la lógica de negocio.**

---

# 1. Pipeline general

Diseña el procesamiento siguiendo conceptualmente este flujo:

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

Este flujo debe quedar reflejado en `ARCHITECTURE.md`.

---

# 2. Nivel 0 — Ingesta y deduplicación

Cada correo debe identificarse de forma estable.

Investiga qué combinación de:

* IMAP UID.
* Message-ID.
* mailbox.
* hash de contenido.

es adecuada para garantizar que un mensaje no vuelva a procesarse innecesariamente.

El procesamiento debe ser idempotente.

Si el mismo correo vuelve a aparecer durante una sincronización:

> NO debe volver a consumir IA.

Documenta la estrategia elegida.

---

# 3. Nivel 1 — Metadatos

Antes de descargar/procesar todo el contenido, extraer:

* From.
* Reply-To.
* To.
* Subject.
* Date.
* Message-ID.
* IMAP UID.
* Content-Type.
* nombres de adjuntos.
* tipo de adjuntos.
* tamaño.
* dominio del remitente.

Siempre que sea posible, utilizar estos datos antes de descargar contenido pesado.

---

# 4. Nivel 2 — Filtros deterministas

Implementar conceptualmente un primer filtro barato.

Ejemplos de señales:

```text
invoice
factura
receipt
recibo
payment
pago
subscription
suscripción
renewal
renovación
billing
billing notice
statement
charge
```

También considerar:

* remitente;
* dominio;
* existencia de PDF;
* nombre del adjunto;
* patrones conocidos;
* listas de remitentes ignorados;
* listas de proveedores conocidos.

No depender únicamente de palabras clave.

El resultado debe ser una puntuación inicial:

```text
billingScore: 0..100
```

Por ejemplo:

```text
Asunto relacionado con factura       +
Remitente relacionado con billing    +
PDF adjunto                           +
Importe detectable                    +
Lenguaje de renovación                +
Proveedor conocido                    +
```

Los pesos concretos deben quedar configurables y documentados.

No convertir estos valores en reglas rígidas difíciles de modificar.

---

# 5. Estados del procesamiento

Define estados explícitos para que podamos observar qué ha ocurrido con cada mensaje.

Por ejemplo:

```text
RECEIVED
IGNORED
CANDIDATE
EXTRACTED
CLASSIFIED
MATCHED
DISCOVERY
REQUIRES_REVIEW
FAILED
```

No es obligatorio utilizar exactamente estos nombres.

Diseña una máquina de estados coherente.

Debe ser posible saber:

* qué ocurrió;
* cuándo ocurrió;
* qué extractor se utilizó;
* si intervino IA;
* qué resultado obtuvo;
* por qué se tomó una decisión.

---

# 6. Nivel 3 — Extracción determinista

Antes de llamar a IA, extraer mediante código todo lo que sea posible.

Por ejemplo:

* importes;
* monedas;
* fechas;
* números de factura;
* periodos;
* emails;
* dominios;
* URLs;
* identificadores;
* referencias de pedido.

Utilizar:

* regex;
* parsers;
* HTML parsing;
* extracción de texto PDF;
* OCR cuando corresponda.

No utilizar IA para extraer algo que pueda obtenerse de forma fiable mediante código.

El resultado debe ser un objeto intermedio estructurado.

Por ejemplo:

```text
ExtractedDocument
 ├── amount
 ├── currency
 ├── invoiceNumber
 ├── invoiceDate
 ├── dueDate
 ├── billingPeriod
 ├── sender
 ├── senderDomain
 ├── subject
 ├── documentType
 └── rawSignals
```

Adapta el modelo si existe una solución mejor.

---

# 7. Nivel 4 — Conocimiento de proveedores

El sistema debe distinguir entre:

### Proveedor conocido

Ejemplo:

```text
invoice@ovh.com
```

y:

```text
OVH
```

### Proveedor desconocido

Cualquier remitente que todavía no sepamos identificar.

Diseña un mecanismo de conocimiento de proveedores.

Por ejemplo:

```text
ProviderKnowledge
 ├── provider
 ├── domains
 ├── senders
 ├── documentPatterns
 ├── parsers
 └── confidence
```

No hace falta que sea exactamente esta entidad.

Investiga cuál es el modelo adecuado.

---

# 8. Parsers específicos

Cuando un proveedor sea suficientemente conocido, debe ser posible utilizar un parser específico.

Ejemplo conceptual:

```text
OVH invoice
      ↓
OVH parser
      ↓
datos estructurados
```

La ventaja es que una vez conocido un proveedor:

> no debemos llamar a IA para cada factura futura.

El primer documento puede necesitar IA para comprender la estructura.

Posteriormente el sistema puede utilizar conocimiento persistente.

No quiero crear manualmente cientos de parsers desde el primer día.

Diseña una arquitectura que permita:

* parsers codificados;
* patrones configurables;
* conocimiento aprendido;
* fallback a IA.

---

# 9. Nivel 5 — IA económica

Si las reglas y conocimiento existente no son suficientes:

Utilizar un modelo económico para obtener datos estructurados.

La IA debe recibir únicamente el contenido relevante, no necesariamente el correo completo.

Antes de enviarlo:

* eliminar HTML innecesario;
* eliminar firmas;
* eliminar tracking;
* eliminar contenido irrelevante;
* limitar longitud;
* extraer texto útil de PDF;
* aprovechar los datos ya obtenidos determinísticamente.

La IA debe devolver exclusivamente una estructura validable.

Por ejemplo:

```json
{
  "provider": "...",
  "service": "...",
  "plan": "...",
  "documentType": "invoice",
  "amount": 29.90,
  "currency": "EUR",
  "billingPeriod": "monthly",
  "invoiceDate": "2026-10-03",
  "renewalDate": null,
  "confidence": 0.93
}
```

No acoples el dominio a un proveedor concreto de IA.

Utiliza una interfaz.

Por ejemplo:

```text
DocumentExtractorInterface
```

o una abstracción mejor si la arquitectura existente lo requiere.

---

# 10. Nivel 6 — Escalado a IA avanzada

Si la IA económica devuelve:

* confianza baja;
* campos contradictorios;
* proveedor desconocido;
* documento complejo;
* PDF difícil;
* información insuficiente;

podemos escalar a un modelo más potente.

Por ejemplo:

```text
cheap model
    ↓
confidence < threshold
    ↓
advanced model
```

El umbral debe ser configurable.

No utilizar el modelo avanzado como comportamiento predeterminado.

---

# 11. Validación posterior a IA

MUY IMPORTANTE:

La IA NO debe ser considerada una fuente de verdad.

Después de la extracción:

```text
IA
 ↓
DTO estructurado
 ↓
Symfony Validator
 ↓
reglas de negocio
 ↓
resultado válido
```

Validar:

* tipos;
* importes;
* moneda;
* fechas;
* periodicidades;
* rangos;
* campos obligatorios;
* coherencia interna.

Si la IA dice:

```text
amount = -8738291
```

no debemos aceptarlo.

Si dice:

```text
renewalDate < invoiceDate
```

debemos detectarlo.

La IA propone datos.

El sistema decide si son válidos.

---

# 12. Service Matching

Una vez obtenidos datos estructurados, NO crear automáticamente un nuevo servicio.

Primero buscar coincidencias con servicios existentes.

Crear un componente específico de matching.

Debe poder considerar:

* proveedor;
* dominio;
* remitente;
* nombre del servicio;
* plan;
* moneda;
* importe;
* periodicidad;
* historial;
* documentos anteriores;
* conocimiento del buzón.

Calcular una puntuación de coincidencia.

Conceptualmente:

```text
Provider match       +40
Domain match         +20
Service match        +20
Currency match        +5
Periodicity match     +5
Amount similarity     +5
Sender match          +5
```

Estos pesos son solamente un ejemplo.

Diseña un algoritmo mejor si lo consideras oportuno.

Resultado:

```text
HIGH CONFIDENCE
→ asociar automáticamente

MEDIUM CONFIDENCE
→ Discovery / revisión

LOW CONFIDENCE
→ nuevo Discovery
```

Los umbrales deben ser configurables.

---

# 13. Discovery

Cuando el sistema no tenga suficiente confianza:

NO crear directamente un servicio definitivo.

Crear un `Discovery` pendiente de revisión.

Ejemplo:

```text
Nuevo servicio detectado

OVH
Hosting
47,80 €/mes

Encontramos 4 documentos similares.

[Confirmar]
[Editar]
[Ignorar]
```

La confirmación del usuario debe convertirse en conocimiento útil para futuras detecciones.

---

# 14. Aprendizaje del buzón

Esta es una parte importante del diseño.

El sistema debe construir conocimiento específico de cada buzón.

Por ejemplo:

```text
MailboxKnowledge

invoice@ovh.com
→ OVH
→ factura
→ hosting

billing@github.com
→ GitHub
→ suscripción
→ Copilot
```

No estamos hablando necesariamente de Machine Learning.

Puede ser simplemente conocimiento estructurado y persistente.

El objetivo es:

### Primera sincronización

```text
10.000 mensajes
→ 400 candidatos
→ 100 necesitan IA
→ 15 necesitan IA avanzada
```

### Sincronizaciones posteriores

```text
200 mensajes nuevos
→ 30 candidatos
→ 25 resueltos mediante conocimiento existente
→ 5 IA económica
→ 0 IA avanzada
```

La aplicación debe aprender de:

* confirmaciones;
* correcciones;
* proveedores conocidos;
* remitentes;
* patrones;
* parsers;
* asociaciones anteriores.

---

# 15. Billing Score

Diseña un componente independiente para determinar si un correo parece relacionado con facturación.

Algo similar a:

```text
BillingClassifier
```

Debe devolver:

```text
score
classification
reasons
```

Ejemplo:

```text
score: 91
classification: BILLING_RELATED

reasons:
- sender matches known provider
- subject contains invoice
- PDF attachment
- amount detected
```

Esto permitirá explicar posteriormente al usuario por qué un correo ha sido procesado.

---

# 16. Cost Control de IA

Esto es CRÍTICO.

La IA debe considerarse un recurso con presupuesto.

Cada llamada debe registrarse.

Crear un concepto similar a:

```text
AiUsage
 ├── mailbox
 ├── operation
 ├── provider
 ├── model
 ├── inputTokens
 ├── outputTokens
 ├── estimatedCost
 ├── createdAt
 └── metadata
```

Necesitamos poder responder:

> ¿Cuánto nos cuesta procesar el buzón de este usuario?

Y:

> ¿Cuánto cuesta de IA un usuario medio al mes?

Debe existir un presupuesto configurable por buzón/plan.

Si se alcanza el límite:

```text
NO MÁS IA
```

El procesamiento puede:

* continuar mediante reglas;
* quedar pendiente;
* esperar al siguiente periodo;
* solicitar una acción al usuario.

Nunca permitir llamadas ilimitadas a IA durante una sincronización.

---

# 17. Idempotencia y reintentos

Todos los pasos del pipeline deben ser:

* idempotentes;
* reintentables;
* observables.

Si falla la IA:

```text
no perder el correo
no duplicar Discovery
no duplicar Invoice
no duplicar coste
```

Especialmente importante con Symfony Messenger.

Diseña mensajes y handlers de forma que un retry no produzca duplicados.

---

# 18. Procesamiento progresivo

No debemos descargar ni analizar todo el contenido de todos los correos inmediatamente.

Siempre que sea posible:

```text
headers
  ↓
metadata
  ↓
candidate
  ↓
body
  ↓
attachment
  ↓
OCR
  ↓
IA
```

Cada nivel debe ejecutarse solamente cuando sea necesario.

Esto reduce:

* tráfico;
* almacenamiento;
* CPU;
* coste de IA;
* tiempo de procesamiento.

---

# 19. Privacidad

El pipeline debe minimizar la información que sale del sistema.

Antes de enviar contenido a un proveedor de IA:

* enviar únicamente lo necesario;
* no enviar correos irrelevantes;
* no enviar buzones completos;
* no enviar información que pueda extraerse localmente;
* registrar qué proveedor/modelo ha procesado el documento.

La arquitectura debe permitir cambiar de proveedor de IA sin modificar el dominio.

---

# 20. Actualiza la documentación

Actualiza:

```text
/docs/ARCHITECTURE.md
/docs/PRODUCT.md
/docs/SECURITY.md
/docs/ROADMAP.md
/docs/DECISIONS.md
```

Incluye:

* diagrama del pipeline;
* estados;
* interfaces;
* responsabilidades;
* estrategia de IA;
* estrategia de matching;
* estrategia de aprendizaje del buzón;
* control de costes;
* privacidad;
* idempotencia;
* observabilidad.

Añade decisiones arquitectónicas explicando especialmente:

1. Por qué no enviamos todos los correos a IA.
2. Por qué la IA no contiene la lógica de negocio.
3. Por qué utilizamos extracción determinista antes de IA.
4. Por qué existe un modelo económico y uno avanzado.
5. Por qué existe conocimiento específico del buzón.
6. Cómo evitamos pagar dos veces por el mismo mensaje.
7. Cómo limitamos económicamente el uso de IA.

# Restricción

NO IMPLEMENTES TODAVÍA EL PIPELINE.

Primero modifica la documentación y presenta:

1. arquitectura propuesta;
2. entidades necesarias;
3. interfaces;
4. flujo completo;
5. estados;
6. estrategia de scoring;
7. estrategia de matching;
8. estrategia de aprendizaje;
9. control de costes de IA;
10. riesgos y decisiones pendientes.

No añadas Machine Learning real salvo que exista una necesidad demostrada.

La primera versión debe poder funcionar con:

**reglas + parsers + IA estructurada + scoring + conocimiento persistente.**

El objetivo es que la IA sea una herramienta de resolución de incertidumbre y descubrimiento, no una dependencia permanente para procesar cada correo.
 