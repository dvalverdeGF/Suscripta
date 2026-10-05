# Producto

> Estado: **propuesta inicial**. Documento de definición previo a la implementación.

## 1. Problema

Autónomos y pequeñas empresas pagan cada mes una cantidad creciente de servicios recurrentes:
hosting, dominios, software, licencias, seguros, telefonía, suministros, herramientas de
marketing, formación, cuotas profesionales. La mayoría no tiene un inventario real de lo que
paga.

Las consecuencias son concretas y caras:

- **Dinero que se va sin que nadie lo mire.** Suscripciones olvidadas que siguen cobrándose.
- **Sorpresas.** Subidas de precio que nadie detecta hasta que revisa el extracto.
- **Renovaciones anuales que se escapan.** Contratos que se renuevan automáticamente porque el
  aviso llegó al correo y nadie lo leyó.
- **Duplicidades.** Dos herramientas que hacen lo mismo, pagadas a la vez.
- **Trabajo manual.** Mantener una hoja de cálculo de suscripciones es tedioso y se abandona.

El problema no es "no tener una app de suscripciones". El problema es **no saber qué se está
pagando, cuánto cuesta y cuándo hay que actuar**.

## 2. Usuario objetivo

**Primario:** autónomo o microempresa (1–10 personas) en España/UE, con entre 5 y 60 servicios
recurrentes, que recibe las facturas por correo electrónico y no tiene departamento financiero.

**Secundario:** pequeña empresa de hasta ~25 personas con un responsable administrativo que
quiere control sin implantar un ERP.

**No objetivo (v1):** grandes empresas, departamentos de compras, contabilidad profesional,
consumidores con 2–3 suscripciones de streaming.

## 3. Propuesta de valor

> **Descubrir y controlar automáticamente los servicios y gastos recurrentes de una persona o
> pequeña empresa a partir de su correo de facturación.**

El producto **no es** un "gestor de suscripciones" ni un "gestor de renovaciones". Esas son
categorías en las que ya hay decenas de productos y en las que el usuario sigue teniendo que
introducir todo a mano. Lo que define a este producto es **el descubrimiento automático desde
el correo donde realmente llegan las facturas**, y el control que se construye encima.

Tres pilares, en este orden:

1. **Descubrimiento automático.** Conectas el correo donde recibes tus facturas y el sistema
   encuentra los servicios recurrentes por ti. No tienes que introducir 40 servicios a mano.
2. **Control claro.** Qué pagas, cuánto te cuesta, cuándo vuelven a cobrarte y qué se renueva.
3. **Aviso a tiempo.** Alertas antes de una renovación, un cobro o una subida de precio.

La conexión al correo es una **capacidad diferencial importante**, pero no es el producto en
sí: el producto es el control que se obtiene a partir de ella. El correo es la **fuente**; el
valor está en el dominio (servicios, costes, renovaciones) y en lo que se construye encima
(alertas, histórico, previsiones).

### 3.1 Preguntas que el producto debe responder

| Pregunta | Dónde se responde |
|---|---|
| ¿Qué servicios estoy pagando? | Listado de servicios + dashboard |
| ¿Cuánto me cuestan? | Coste mensual equivalente y anual |
| ¿Cuándo volverán a cobrarme? | Próximos cobros |
| ¿Qué servicios se van a renovar? | Renovaciones y alertas de renovación |
| ¿Qué ha cambiado? | Eventos de servicio + alertas de cambio |
| ¿Qué facturas nuevas ha detectado el sistema? | Descubrimientos pendientes de revisar |
| ¿Qué gastos recurrentes han aumentado? | Historial de precios + alertas de subida |
| ¿Qué servicios debería revisar? | Alertas abiertas + descubrimientos pendientes |

### 3.2 Hipótesis de producto

> **Un autónomo o pequeña empresa está dispuesto a conectar su buzón de facturación si la
> aplicación puede descubrir automáticamente sus servicios recurrentes y convertirlos en
> información útil sobre costes y renovaciones.**

Esta hipótesis es **la que hay que validar antes de construir el producto completo**. Si es
falsa, el resto del producto no tiene sentido: sin correo conectado, esto es otro gestor
manual más.

**Cómo validarla antes de construir todo el producto:**

1. **Vertical mínima end-to-end** (ver `ROADMAP.md`, fase 3): cuenta → conectar IMAP →
   encontrar una factura → extraer proveedor, importe y periodicidad → proponer servicio →
   usuario confirma → el servicio aparece en el dashboard con sus próximos costes. Es el
   instrumento de validación, no una demo.
2. **Prueba con usuarios reales** (5–10 autónomos/pymes del segmento objetivo) usando su propio
   buzón de facturación, midiendo:
   - **Tasa de conexión:** cuántos aceptan conectar el correo tras ver la propuesta de valor.
   - **Descubrimientos útiles:** cuántos servicios propuestos confirman sin editar (proxy de
     precisión) y cuántos confirman en total (proxy de valor).
   - **Tiempo hasta el primer valor:** minutos desde el registro hasta el primer servicio
     confirmado procedente del correo.
   - **Retención temprana:** si vuelven al dashboard en las dos semanas siguientes.
3. **Criterio de continuidad (a revisar con datos reales):** si una mayoría clara de los
   usuarios de prueba conecta el correo y confirma servicios sin editar, la hipótesis se
   sostiene y se profundiza el producto. Si la tasa de conexión es baja, hay que revisar la
   propuesta de valor o el modelo de confianza (privacidad, reenvío como alternativa) antes de
   seguir construyendo.
4. **Criterio de falsación:** si los usuarios prefieren introducir los servicios a mano aunque
   se les ofrezca el descubrimiento automático, la hipótesis es falsa y el producto debe
   replantearse.

Los umbrales concretos se fijarán antes de la prueba, no después de ver los resultados.

## 4. Funcionalidades

### 4.1 Núcleo (v1)

- **Alta manual de servicios** con proveedor, plan, categoría, precio, moneda, periodicidad,
  fechas y notas. Funciona sin conectar el correo.
- **Próximos cobros**: lista cronológica con importes y total del periodo.
- **Coste recurrente**: equivalente mensual, estimación anual, desglose por categoría,
  servicios más caros.
- **Historial de precios** por servicio, con variación porcentual.
- **Renovaciones**: fecha, preaviso, renovación automática, fin de compromiso.
- **Alertas**: próximo cobro, próxima renovación, subida de precio, cambio de plan, nuevo
  servicio detectado, posible duplicado, factura recurrente que deja de llegar, servicio sin
  actividad reciente.
- **Notificaciones** in-app y por email, configurables por tipo.
- **Documentos**: adjuntar facturas y documentos a un servicio.
- **Conexión de correo (IMAP, solo lectura)**, con **varias cuentas por organización**, y
  **descubrimiento** de servicios recurrentes.
- **Descubrimientos pendientes de revisar**: confirmar, editar o ignorar.
- **Explicabilidad**: para cada correo procesado, el usuario puede ver por qué se procesó o se
  ignoró, qué se extrajo y de dónde (§6.4).
- **Aprendizaje del buzón**: el sistema recuerda lo que el usuario confirma y corrige, de modo
  que cada vez necesita analizar menos correo y es más rápido y más barato (§6.5).

### 4.2 Conexión de correo: alcance

La conexión al correo es una **capacidad estratégica de primera clase**, no una integración
secundaria añadida al final. Pero la aplicación **no es un cliente de correo**: el correo es
una **fuente de información**.

**Compatibilidad objetivo:** cualquier servidor IMAP estándar, siempre que sea técnicamente
posible.

| Proveedor | Vía |
|---|---|
| Gmail | IMAP (contraseña de aplicación) u OAuth |
| Microsoft 365 / Outlook | IMAP u OAuth |
| Servidores IMAP propios | IMAP |
| Correo de hosting (cPanel, Plesk, etc.) | IMAP |
| Proveedores de correo corporativo | IMAP |
| Proveedores de dominios/hosting | IMAP |

**La arquitectura no se diseña alrededor de Gmail** (ver `DECISIONS.md` D-23). Gmail es un
proveedor más, con sus particularidades (scopes restringidos, evaluación CASA anual para acceso
server-side), y por eso existe además el **reenvío de correo** como alternativa de ingesta
(D-21).

**Varias cuentas a la vez.** Una organización puede conectar **más de una cuenta de correo**
(por ejemplo, la del titular y la del asesor, o una cuenta de facturación y una dirección de
reenvío). Todas alimentan el mismo inventario de servicios: el sistema **deduplica entre
cuentas** para no proponer dos veces el mismo servicio porque la factura llegó por dos buzones
(ver `ARCHITECTURE.md` §4.5 y `DECISIONS.md` D-27).

### 4.3 Posterior

- OAuth con Gmail y Microsoft 365.
- Canales de notificación adicionales (push, Slack, Telegram).
- Almacenamiento de documentos en S3/Backblaze.
- Exportación de datos, informes anuales.
- Planes y límites del SaaS.

## 5. Qué NO es el producto

| No es | Por qué |
|---|---|
| Un ERP | No hay contabilidad, ni asientos, ni inmovilizado, ni tesorería. |
| Un programa de contabilidad | No calcula impuestos, ni IVA, ni modelos, ni conciliación bancaria. |
| Un gestor documental genérico | Los documentos existen solo como evidencia de un servicio. Sin carpetas, sin búsqueda global, sin OCR masivo. |
| Un cliente de correo | No se lee, responde, mueve ni borra correo. Solo se analiza. |
| Un CRM | No hay clientes, oportunidades ni pipeline. |
| Una plataforma de facturación | No se emiten facturas a tus clientes. |
| Un agregador bancario | v1 no se conecta a bancos. |
| Un comparador de precios | No recomienda cambiar de proveedor. |

**Regla de decisión:** si una funcionalidad no contribuye directamente a controlar servicios,
costes, renovaciones o cambios, se cuestiona y por defecto se descarta.

## 6. Flujo principal

### Onboarding

```text
Crear cuenta
     ↓
Conectar correo (o "seguir sin conectar")
     ↓
Analizar facturas (asíncrono, con progreso visible)
     ↓
"Tenemos 27 posibles servicios para ti"
     ↓
Confirmar / corregir / ignorar
     ↓
Dashboard
```

El usuario puede **trabajar manualmente sin conectar el correo** en cualquier momento, y
desconectar la cuenta sin perder los servicios ya confirmados.

### Descubrimiento

```text
Conectar correo
     ↓
Sincronizar mensajes (acotado, con cursor)
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

**Nada crítico se crea sin supervisión.** Cuando hay incertidumbre, el sistema propone y
espera confirmación.

### Revisión de descubrimientos

```text
OVH
39,90 €
Mensual
Confianza: alta

[Confirmar] [Editar] [Ignorar]
```

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

### 6.4 Explicabilidad: por qué el sistema hizo lo que hizo

El usuario está conectando su **buzón de facturación**. Tiene derecho a saber qué se ha hecho
con él. Por eso la explicación no es una pantalla de depuración escondida: es parte del
producto.

Para cada correo procesado, el usuario puede ver:

- **Por qué se procesó o se ignoró**, con los motivos concretos: *"el remitente es un proveedor
  conocido"*, *"el asunto contiene una palabra de facturación"*, *"tiene un PDF adjunto"*,
  *"se detectó un importe"*.
- **Qué se extrajo**: proveedor, servicio, importe, moneda, periodicidad, fechas.
- **Cómo se extrajo**: si bastaron reglas y parsers o si fue necesario un modelo de IA.
- **De dónde salió**: los correos y documentos concretos que sustentan la propuesta.
- **Con qué se asoció**: por qué el sistema cree que es el mismo servicio que ya tenía
  (*"coincide el proveedor y el importe"*).

**Por qué importa.** Un gasto recurrente mal detectado es peor que un gasto no detectado: el
usuario toma decisiones de dinero con esa información. Un sistema que dice "29,90 €" sin poder
explicar de dónde sale no es utilizable para eso.

**Consecuencia de diseño.** El sistema **no envía todos los correos a una IA**. La mayoría se
resuelven con reglas y parsers, que son explicables por construcción. La IA se reserva para lo
que de verdad es ambiguo, y su resultado se valida antes de usarse.

### 6.5 El sistema aprende de tu buzón

El sistema **no vuelve a analizar desde cero** lo que ya ha visto. Cuando el usuario confirma
un descubrimiento o corrige un dato, esa información se guarda como conocimiento del buzón:

```text
invoice@ovh.com  →  OVH  →  factura  →  hosting
```

A partir de ahí, el mismo tipo de correo se resuelve **sin IA y sin intervención del usuario**.

**Qué nota el usuario:**

- La primera sincronización tarda más y pregunta más: es normal, el sistema está conociendo el
  buzón.
- Las siguientes son más rápidas y generan menos descubrimientos que revisar.
- Las correcciones se aplican de forma **predecible**: el usuario puede ver y editar lo que el
  sistema ha aprendido.

**Qué NO es.** No es una caja negra que "aprende sola". Es conocimiento explícito, inspeccionable
y editable. Si el sistema se equivoca, el usuario puede ver por qué y corregirlo.

## 7. Pantallas principales

1. **Dashboard** — responde a las preguntas de §3.1 (ver §8).
2. **Servicios** — listado filtrable por estado, categoría y proveedor.
3. **Detalle de servicio** — datos, historial de precios, documentos, eventos, notas.
4. **Próximos cobros** — calendario/lista con totales.
5. **Descubrimientos** — bandeja de revisión.
6. **Alertas** — abiertas, reconocidas, descartadas.
7. **Cuentas de correo** — conectar, estado de sincronización, desconectar.
8. **Ajustes** — perfil, notificaciones, privacidad (exportar/eliminar datos).

## 8. Dashboard: las preguntas que debe responder

| Pregunta | Respuesta en pantalla |
|---|---|
| ¿Qué servicios estoy pagando? | Listado y recuento por estado y categoría. |
| ¿Cuánto me cuestan? | Equivalente mensual y estimación anual. |
| ¿Cuándo volverán a cobrarme? | Cobros de los próximos 30/60 días con total. |
| ¿Qué servicios se van a renovar? | Renovaciones próximas con preaviso. |
| ¿Qué ha cambiado? | Últimos cambios de precio/plan y eventos recientes. |
| ¿Qué facturas nuevas ha detectado el sistema? | Descubrimientos pendientes de revisar. |
| ¿Qué gastos recurrentes han aumentado? | Variaciones de precio destacadas. |
| ¿Qué servicios debería revisar? | Alertas abiertas y descubrimientos pendientes. |

**Sin gráficos decorativos.** Solo información accionable.

## 9. Alertas

| Alerta | Tipo | Inferencia |
|---|---|---|
| Próximo cobro | Determinista | No |
| Próxima renovación | Determinista | No |
| Renovación anual próxima | Determinista | No |
| Aumento de precio | Determinista (comparación de precios) | No |
| Cambio de plan | Determinista si el plan está en la factura | Parcial |
| Nueva suscripción detectada | Inferencia | Sí |
| Servicio aparentemente duplicado | Inferencia | Sí |
| Factura recurrente que deja de recibirse | Inferencia | Sí |
| Servicio sin actividad reciente | Inferencia | Sí |

Las alertas inferidas se presentan **explícitamente como sugerencias** (`Alert.isInference`),
con la evidencia disponible y sin afirmar conclusiones que no se puedan sostener.

Ejemplo:

> **Posible aumento de precio**
>
> Tu servicio de OVH ha pasado de 39,90 € a 47,80 €.
>
> [Ver historial]

## 10. Diferenciación

### 10.1 Nivel de certeza de esta afirmación

> **La investigación realizada hasta ahora no ha identificado competidores directos que
> combinen IMAP genérico, descubrimiento automático de servicios y enfoque específico en
> autónomos/pymes españolas o europeas.**

Esto es un **hallazgo de investigación acotado**, no una afirmación absoluta. No significa "no
existe ningún competidor que haga esto". La investigación se basó en consultas directas a
páginas oficiales de producto, precios y privacidad, y a fichas de tiendas de aplicaciones;
varios buscadores estaban bloqueados o eran solo JS, y algunas páginas no se pudieron leer por
completo (`COMPETITORS.md` §12). **Si aparece un producto que contradiga esta afirmación, hay
que actualizar este documento y `COMPETITORS.md`.**

### 10.2 Los tres grupos de competidores

**Competidores tradicionales — introducción manual.**
El usuario introduce sus servicios y suscripciones a mano. Es la mayoría del mercado español y
europeo (QuietSub, Recur, Subscro, Subflow, Renulo, TrackMyPlans, SubBuddy, Subo) y también el
tracker open source más popular (Wallos). Ventaja: simplicidad y privacidad. Debilidad: el
trabajo recae en el usuario, que abandona el mantenimiento.

**Apps de consumo basadas en correo.**
Detectan suscripciones mediante correo, pero suelen estar orientadas al consumidor y/o depender
de Gmail o de integraciones concretas (Unbilled, Track-Subs, Subzero, Subby, What's My Subs,
Yuki, Subflo, Spendbox, LemSubs, SpendKeeper, Subscription Freedom). Ventaja: automatización.
Debilidad: enfoque de consumo, casi todas solo Gmail, muchas en beta o pre-lanzamiento, y sin
flujos de autónomo/pyme.

**Plataformas de gestión de gasto SaaS para empresa.**
Cledara, Vertice, Zluri, Spendbase, Ramp, Brex, Payhawk. Ventaja: profundidad y control de
gasto corporativo. Debilidad: pesadas, guiadas por ventas, diseñadas para tarjetas corporativas
y equipos financieros.

### 10.3 Nuestra posición

> **Conecta el correo donde realmente recibes tus facturas y descubre automáticamente los
> servicios que estás pagando.**

1. **IMAP genérico, no Gmail.** Compatible con cualquier servidor IMAP estándar siempre que sea
   técnicamente posible: Gmail, Microsoft 365, servidores IMAP propios, correo de hosting,
   proveedores de correo corporativo, proveedores de dominios/hosting. **La arquitectura no se
   diseña alrededor de Gmail** (ver `DECISIONS.md` D-23).
2. **Enfoque en autónomos y pequeñas empresas españolas y europeas**, no en consumidores ni en
   grandes empresas. Es el segmento que queda entre los dos grupos extremos.
3. **Descubrimiento automático de servicios a partir de facturas y comunicaciones recibidas por
   correo**, sin exigir conexión bancaria ni tarjeta corporativa.
4. **Privacidad y diseño orientado a la normativa y expectativas europeas** (RGPD, ePrivacy),
   con reenvío de correo como alternativa al acceso al buzón (D-21).
5. **Simplicidad deliberada.** Sin contabilidad, sin ERP, sin gestión de gastos de equipo.
6. **Historial de precios y detección de cambios** como ciudadano de primera clase.

### 10.4 Lo que NO afirmamos

No se harán afirmaciones de **"100 % privado"**, **"100 % UE"** ni equivalentes hasta que la
implementación y los proveedores realmente utilizados permitan sostenerlas (ver
`DECISIONS.md` D-25). La privacidad se describe por lo que el sistema **hace** (solo lectura,
minimización, cifrado, borrado, retención), no por eslóganes.

### 10.5 Criterio final

El producto **no gana por tener más funcionalidades**. La ventaja competitiva es:

> **"Conecta tu correo y descubre automáticamente todo lo que estás pagando."**

Todo lo demás (dashboard, historial, alertas, documentos) debe **reforzar esa experiencia**, no
competir con ella.

El análisis detallado de competidores, con fuentes, precios observados y matriz de capacidades,
está en `COMPETITORS.md`.

## 11. Casos de uso

**CU-1 — Autónomo que no sabe lo que paga.**
Conecta su correo de facturación. En unos minutos ve 27 servicios propuestos. Confirma 22,
corrige 3, ignora 2. Descubre que sigue pagando dos herramientas que ya no usa.

**CU-2 — Subida de precio silenciosa.**
Un proveedor sube el precio un 19,8 %. El sistema detecta la diferencia entre la factura
nueva y la anterior y genera una alerta de inferencia con el historial.

**CU-3 — Renovación anual con preaviso.**
Un contrato se renueva cada 12 meses con 30 días de preaviso. El sistema avisa 45 días antes
para que haya margen para decidir.

**CU-4 — Servicio duplicado.**
Dos herramientas de email marketing con importes similares y periodicidad mensual. El sistema
sugiere una posible duplicidad; el usuario decide.

**CU-5 — Factura que deja de llegar.**
Un servicio llevaba 14 meses facturando y deja de hacerlo. El sistema lo señala como posible
cancelación o cambio de proveedor, sin afirmarlo.

**CU-6 — Control sin correo.**
Un usuario que no quiere conectar su correo introduce sus 12 servicios a mano y usa el
dashboard, las alertas y el historial de precios igual.

**CU-7 — Desconexión.**
El usuario desconecta su cuenta de correo. Se eliminan credenciales y mensajes asociados; los
servicios confirmados permanecen.

## 12. Métricas de éxito (propuestas)

**Métricas de la hipótesis (§3.2) — las primeras que hay que medir:**

- **Tasa de conexión de correo:** % de usuarios que conectan un buzón tras ver la propuesta de
  valor. Es la métrica que valida o falsa la hipótesis.
- **Tiempo hasta el primer valor:** minutos desde el registro hasta el primer servicio
  confirmado procedente del correo.
- **Descubrimientos útiles:** servicios confirmados sin editar / servicios propuestos.

**Métricas de producto:**

- **Activación:** % de usuarios que confirman ≥ 5 servicios en la primera semana.
- **Valor del descubrimiento:** servicios confirmados procedentes de correo / total.
- **Retención:** usuarios que vuelven al dashboard al menos una vez al mes.
- **Accionabilidad:** % de alertas abiertas que el usuario reconoce o descarta (no ignoradas
  en bloque).
- **Precisión:** % de descubrimientos confirmados sin edición (proxy de calidad del extractor).

**Métricas del pipeline de análisis** (ver `ARCHITECTURE.md` §13.16). Son las que determinan si
el producto es económicamente viable, y por eso se miden desde el primer día:

- **% de mensajes resueltos sin IA.** Cuanto más alto, mejor: significa que reglas, parsers y
  conocimiento del buzón bastan. Objetivo: crecer con la antigüedad del buzón.
- **Coste de IA por usuario y mes.** Es el dato que fija el precio del producto. Sin él, el
  modelo de negocio es una apuesta.
- **Coste de IA por buzón en la primera sincronización frente a las siguientes.** Mide si el
  aprendizaje funciona de verdad.
- **% de mensajes descartados en el filtro determinista** (nivel 2). Mide cuánto trabajo se
  ahorra antes de descargar contenido.
- **Tasa de escalado a IA avanzada.** Si es alta, el umbral o el modelo económico están mal
  ajustados.
- **Tasa de confirmación de descubrimientos.** Mide la precisión percibida.
- **Tiempo medio de procesamiento por mensaje**, por nivel de extracción.

## 13. Modelo de negocio (no implementado en v1)

Se diseña con la posibilidad de:

- Plan gratuito limitado (p. ej. hasta N servicios, sin correo).
- Plan profesional (servicios ilimitados, correo, alertas).
- Límites por número de servicios, cuentas de correo y documentos procesados.

**Estos límites no contaminan el dominio**: se resuelven con `EntitlementCheckerInterface`
(ver `ARCHITECTURE.md` §4.9). No se implementan pagos, planes ni facturación del SaaS en v1.
