# Competidores y hueco de mercado

> Estado: **investigación inicial**. Los datos se obtuvieron consultando páginas oficiales de
> producto, precios y privacidad, y fichas de tiendas de aplicaciones. **Los precios cambian
> con frecuencia**: trátese cada cifra como "observada en el momento de la investigación" y
> verifíquese antes de usarla. Lo que no se pudo verificar se marca como **[SIN VERIFICAR]** o
> **[INCIERTO]**.

## 1. Resumen ejecutivo

El mercado se divide en **tres capas que apenas se solapan**:

1. **Finanzas personales de consumo** que detectan suscripciones **vinculando cuentas
   bancarias** (Rocket Money, Emma, Snoop, Fintonic).
2. **Gestores de suscripciones manuales** (Bobby, TrackMySubs, Wallos y una oleada de apps
   españolas/europeas).
3. **Plataformas de gestión de gasto SaaS para empresa** (Cledara, Vertice, Zluri, Spendbase,
   Ramp, Brex, Payhawk).

**El descubrimiento por correo es un nicho real, emergente y todavía delgado.** Se localizaron
~15 productos que escanean o reenvían correo para detectar cargos recurrentes (Unbilled,
Track-Subs, What's My Subs, Subzero, Subby, Bill Radar, Yuki, Subflo, Spendbox, LemSubs,
SpendKeeper, Gennai, Subscription Freedom, ExpenseBot, SparkReceipt). La mayoría son **muy
tempranos** (listas de espera, beta), **solo Gmail** y **orientados a consumo**. Casi ninguno
combina descubrimiento por correo con un conjunto de funcionalidades real para
**autónomos/pymes**.

**El hueco más claro:** una herramienta **enfocada, privada por diseño y nativa UE/RGPD** que
(1) descubra costes recurrentes **desde el correo (IMAP genérico + OAuth, solo lectura)**, (2)
esté pensada para **autónomos y pequeñas empresas** (no consumo, no gran empresa) y (3) se
mantenga **simple**: sin ERP, sin conexión bancaria, sin tarjetas virtuales.

> **Nivel de certeza.** **La investigación realizada hasta ahora no ha identificado
> competidores directos que combinen IMAP genérico, descubrimiento automático de servicios y
> enfoque específico en autónomos/pymes españolas o europeas.** No es una afirmación de que no
> exista ninguno: es lo que se pudo verificar (§12). Si aparece un producto que lo contradiga,
> hay que actualizar este documento y `PRODUCT.md` §10.

**Realidad regulatoria:** leer el buzón de un usuario en la UE activa el **RGPD** (base
jurídica, minimización, retención) y la **Directiva ePrivacy art. 5** (confidencialidad de las
comunicaciones). Para **Gmail** en concreto, el scope de lectura es un **"restricted scope"** de
Google, que exige verificación de la app y, si se procesa en servidor, una **evaluación anual
de seguridad CASA** por un tercero aprobado. Es una barrera de entrada real que muchos
competidores pequeños evitan usando **reenvío de correo** en lugar de acceso al buzón.

## 2. Competidores de consumo (EE. UU.)

| Producto | Qué hace | Precio observado | Descubrimiento | Correo |
|---|---|---|---|---|
| **Rocket Money** (ex Truebill) | Encuentra suscripciones, las cancela, negocia facturas, presupuestos. 10M+ miembros. | Gratis; Premium 7–14 $/mes; Premium+ 15 $/mes | Conexión bancaria | ❌ |
| **Bobby** (iOS) | Tracker manual con recordatorios de vencimiento. 4,75★, ~8.005 valoraciones. | Gratis + compras integradas | Manual | ❌ |
| **Trim** | Antiguo asistente de negociación y detección de suscripciones. | — | Conexión bancaria | ❌ |

**Trim fue adquirido por OneMain Financial en abril de 2021**; `asktrim.com` redirige a
`onemainmymoney.com`. No es un competidor independiente activo.

Fuentes: [rocketmoney.com/feature/manage-subscriptions](https://www.rocketmoney.com/feature/manage-subscriptions),
[rocketmoney.com/learn/personal-finance/how-much-does-rocket-money-cost](https://www.rocketmoney.com/learn/personal-finance/how-much-does-rocket-money-cost),
[apps.apple.com — Bobby](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023),
[en.wikipedia.org/wiki/OneMain_Financial](https://en.wikipedia.org/wiki/OneMain_Financial).

## 3. Competidores de consumo (Reino Unido / UE)

| Producto | Qué hace | Precio observado | Descubrimiento | Correo |
|---|---|---|---|---|
| **Emma** (UK) | Presupuestos; detecta suscripciones desde cuentas bancarias. | Plus 4,99 £/mes (41,99 £/año); Pro 9,99 £/mes; Ultimate 14,99 £/mes | Open banking | ❌ |
| **Snoop** (UK) | Gestión de dinero, facturas, cambio de proveedores, score. | Gratis; Plus 5,99 £/mes o 47,99 £/año **[INCIERTO]** | Conexión bancaria | ❌ |
| **Plum** (UK/UE) | Ahorro, inversión, presupuestos. Entidad en Chipre. | Tiers (Plus/Pro/Ultra) **[SIN VERIFICAR]** | **[SIN VERIFICAR]** | ❌ |
| **Fintonic** (ES) | Finanzas personales; "detecta los cargos recurrentes sola, pero necesita acceso a tus cuentas". | Gratis | Conexión bancaria | ❌ |
| **Bnext** (ES) | — | — | — | — |

**Bnext cerró como neobanco de consumo y pivotó a infraestructura financiera.** El año exacto
del cierre es inconsistente entre fuentes españolas **[INCIERTO]**. No es un competidor vivo.

**Snoop:** su propio centro de ayuda indica 5,99 £/mes o 47,99 £/año; algunas reseñas de
terceros citan precios anteriores (4,99 £/mes, 39,99 £/año) **[INCIERTO]**.

Fuentes: [help.emma-app.com — suscripciones](https://help.emma-app.com/en/article/how-can-i-check-my-recurring-subscriptions-hxlh8/),
[help.emma-app.com — precios](https://help.emma-app.com/en/article/how-much-does-emma-plusproultimate-cost-1ywhulq/),
[snoopadmin.zendesk.com](https://snoopadmin.zendesk.com/hc/en-gb/articles/4584733957661-How-much-does-it-cost),
[withplum.com](https://withplum.com/), [fintonic.com/es-ES/alertas/](https://www.fintonic.com/es-ES/alertas/),
[forbes.es — Bnext](https://forbes.es/economia/870969/bnext-cierra-como-neobanco-del-13-de-abril-y-pivota-hacia-servicios-de-infraestructura-financiera/).

## 4. Plataformas de gestión de gasto SaaS (pyme / gran empresa)

| Producto | Qué hace | Precio observado | Descubrimiento | Correo |
|---|---|---|---|---|
| **Cledara** | Gestión SaaS pyme + tarjetas virtuales; captura y concilia facturas de software. SOC 2 I y II. | Gratis condicional o 100 £/mes; módulos 200–1.500 £ | Tarjetas virtuales + plataforma | ❌ (captura facturas, no buzón) |
| **Vertice** | Compras y negociación SaaS para gran empresa. | Sin precio público (demo) | — | ❌ |
| **Zluri** | SaaS Management + Identity Governance. | Sin precio público (demo) | — | ❌ |
| **Spendbase** (`spendbase.co`) | Banca digital y tarjetas virtuales. | 0 $/usuario/mes | — | ❌ |
| **Spendbase** (`spendbase.app`) | Tracker de suscripciones y gastos para agencias. | Gratis hasta 10 subs; 19 $/mes; 49 $/mes | Manual | ❌ |
| **Ramp** | Gestión de gasto; extracción OCR de facturas, Bill Pay, integraciones contables. | Gratis | OCR de facturas/recibos | ❌ (OCR, no buzón) |
| **Brex** | Tarjetas corporativas, gastos, viajes, bill pay. 35.000+ empresas. | Sin precio público | Entrada de facturas con IA | ❌ |
| **Payhawk** | Gestión de gasto (tarjetas, gastos, facturas). | Programa Growth 149 €/mes (elegible <20 empleados en ES/EEE/UK) | — | ❌ |
| **Wallester** | **No es un tracker**: plataforma BaaS de emisión de tarjetas. | Gratis para empezar | — | ❌ |

**Atención:** existen **dos entidades distintas** llamadas Spendbase (`spendbase.co` banca vs
`spendbase.app` tracker). `spendbase.com` es un dominio aparcado en venta. No confundirlas.

**Wallester** se incluye solo como herramienta adyacente de control de gasto; no es un tracker
de suscripciones.

Fuentes: [cledara.com/pricing](https://www.cledara.com/pricing), [vertice.one/pricing](https://www.vertice.one/pricing),
[zluri.com](https://www.zluri.com/), [spendbase.co/pricing](https://www.spendbase.co/pricing/),
[spendbase.app/pricing](https://spendbase.app/pricing), [ramp.com/pricing](https://ramp.com/pricing),
[brex.com](https://www.brex.com/), [payhawk.com/es/planes-y-precios](https://payhawk.com/es/planes-y-precios),
[wallester.com](https://wallester.com/).

## 5. Productos con descubrimiento por correo (el segmento clave)

### 5.1 Escaneo del buzón (OAuth)

| Producto | Qué hace | Precio observado | Correo |
|---|---|---|---|
| **Unbilled** (`unbilled.app`) | Escaneo automático del correo (Gmail/Outlook), detección con IA, recordatorios de renovación, multi-moneda, **reenvío**, importación de extractos (CSV/PDF), gestor de seguros. Operado por **Isle Dynamics (Cyprus) Ltd.**, sujeto al RGPD. | Gratis (5 subs, 50 correos); **Pro 1 €/mes** (500 correos); prueba 30 días | ✅ Gmail/Outlook |
| **Track-Subs** (`track-subs.com`) | "Escanea tus recibos de correo y encuentra cada cargo recurrente. Sin banco. Sin entrada manual." Detección en dos capas (matching determinista de 60+ proveedores + IA). Multi-moneda. | Gratis (10 subs, 1 escaneo/mes); Pro 6,99 $/mes o 59,99 $/año | ✅ Gmail; Outlook/IMAP "próximamente" |
| **What's My Subs** (`whatsmysubs.com`) | Conecta Gmail; convierte recibos y avisos de renovación en una lista. Alertas de subida de precio. | **[SIN VERIFICAR]** | ✅ Gmail |
| **Subzero** (`subzero.money`) | "Conecta Gmail. Encontramos cada suscripción." Solo lectura, ~2 minutos. | Gratis | ✅ Gmail |
| **Subby** (`subby.io`) | Escaneo de Gmail en <60 s. **En lanzamiento** (lista de espera). | Gratis (pre-lanzamiento) | ✅ Gmail |
| **Bill Radar** (`billsradar.com`) | "Lee solo correos de facturación, extrae lo relevante y nunca almacena el contenido completo." | **[SIN VERIFICAR]** | ✅ (solo facturación) |
| **Yuki** (`yukihq.com`) | Conecta Gmail, lee recibos y avisos, construye lista viva de suscripciones. | **[SIN VERIFICAR]** | ✅ Gmail |
| **Subflo** (`github.com/huzaifa525/subflo`) | Open source (MIT). Escaneo de Gmail por IMAP con contraseña de aplicación, detección en 3 capas (búsqueda IMAP → regex 40+ patrones → confirmación LLM), auto-escaneo cada 24 h. | Gratis / autoalojado | ✅ Gmail IMAP |

### 5.2 Reenvío de correo (sin acceso al buzón)

| Producto | Qué hace | Precio observado | Correo |
|---|---|---|---|
| **Spendbox** (`spendbox.co`) | Reenvías recibos a una dirección dedicada; parsea 2.400+ proveedores, detecta renovaciones, categoriza. "Sin alias, sin acceso de terceros arriesgado." | Gratis 50 correos/mes (beta) | ✅ reenvío |
| **LemSubs** (`lemsubs.com`) | Reenvías recibos a una dirección privada; los archiva contra la suscripción. Argumenta explícitamente que **"el scope OAuth de leer todo el correo es un riesgo de privacidad"**. Incluye paso de "confirmar coincidencia". | **[SIN VERIFICAR]** | ✅ reenvío |
| **SpendKeeper** (`spendkeeper.app`) | App iPhone; filtro de Gmail que reenvía recibos; datos en el dispositivo, sincronización iCloud. | Gratis | ✅ reenvío |
| **Subscription Freedom** (`subscriptionfreedom.com`) | "Nunca nos conectamos a tu banco ni entramos en tu buzón." Reenvío automático o subida manual (PDF/imagen + IA). | **[SIN VERIFICAR]** | ✅ reenvío |

### 5.3 Extracción de facturas para pyme

- **Gennai** (`gennai.io`) — extrae **facturas de suscripción desde el correo** para empresas:
  facturas HTML en línea, adjuntos PDF y enlaces a portales de proveedor; escaneo retroactivo
  de facturas SaaS/ad/cloud. Enfoque de **contabilidad/cuentas a pagar**, no de tracker simple.
  Precio **[SIN VERIFICAR]**.

### 5.4 Herramientas adyacentes de recibos por correo

**ExpenseBot**, **SparkReceipt**, **Mailparser.io** (parseo genérico de correo),
**SubDupes** (blog).

**Patrón clave:** los productos nativos de correo eligen mayoritariamente **reenvío** en lugar
de OAuth de buzón, precisamente para evitar la carga del restricted scope/CASA de Google y la
percepción de "leer todo el correo".

## 6. Open source

| Proyecto | Qué es | Descubrimiento | Licencia / estado |
|---|---|---|---|
| **Wallos** (`ellite/Wallos`) | Tracker de suscripciones autoalojado en PHP; categorías, multi-moneda, notificaciones (email/Discord/Pushover/Telegram/Gotify/webhooks), OIDC/OAuth. | **Manual** | Activo |
| **Subflo** (`huzaifa525/subflo`) | Tracker con IA autoalojado. | **Gmail IMAP + LLM** | **MIT**, activo |
| **Actual Budget** | Presupuestos con cifrado extremo a extremo. | Importación bancaria | Activo |
| **Firefly III** | Gestor de finanzas personales autoalojado. | Importación bancaria | Activo |
| **Maybe Finance** | App de finanzas personales. | Importación bancaria | **AGPLv3, sin mantenimiento activo** (última v0.6.0) |

**Conclusión:** el único proyecto open source con descubrimiento **por correo** es **Subflo**
(MIT, Gmail IMAP + LLM). Wallos, el tracker autoalojado más popular, es **manual**. Es un hueco
notable en open source.

Fuentes: [github.com/ellite/Wallos](https://github.com/ellite/Wallos), [wallosapp.com](https://wallosapp.com/),
[github.com/huzaifa525/subflo](https://github.com/huzaifa525/subflo), [actualbudget.org](https://actualbudget.org/),
[firefly-iii.org](https://www.firefly-iii.org/), [github.com/maybe-finance/maybe](https://github.com/maybe-finance/maybe).

## 7. Mercado español / europeo de "gestor de suscripciones"

Un clúster amplio, mayoritariamente **manual**, **gratuito** y **móvil**. **Ninguno de los
verificados hace descubrimiento por correo.**

| Producto | Qué hace | Precio observado | Descubrimiento |
|---|---|---|---|
| **QuietSub** (`quietsub.app`) | iOS/Android; entrada manual; recordatorios; totales anuales; enlaces de cancelación; "sin acceso bancario". | Gratis; Premium 11,99 €/año | Manual |
| **Recur** (`recur.es`) | Manual; recordatorio por email 3 días antes; "100 % privado, sin conexión bancaria". | Gratis | Manual |
| **Subscro** (`subscro.com`) | iOS; calendario, estadísticas, multi-moneda, grupos, iCloud, sin cuenta. | Gratis hasta 3 subs; pago único | Manual |
| **Subflow** (`subflow.ing`) | Web; "smart add" (pegar/subir contenido → autorrelleno), notificaciones, cosuscripciones, extensión Raycast. | Gratis | Semi-manual |
| **Renulo** (`renulo.app`) | Web; plantillas, multi-moneda, auth con Supabase. | Gratis | Manual |
| **TrackMyPlans** (`trackmyplans.com`) | iOS; "100 % privado, funciona sin conexión". | Gratis | Manual |
| **SubBuddy** (`subbuddy.io`) | Web; "sin vincular tu banco"; gratis 3 subs. | Gratis (3 subs) | Manual |
| **Subo** (App Store ES) | iPhone; "sin conectar tu cuenta bancaria". | **[SIN VERIFICAR]** | Manual |
| **FindRecurring** (`findrecurring.com`) | Web; **pegas un extracto bancario** → detecta cargos recurrentes; sin cuenta, no almacena. | Gratis | Extracto pegado |
| **SubLess** (`subless.me`) | Español; importación por captura/PDF/extracto. | **[SIN VERIFICAR]** | Captura/PDF/extracto |

Fuentes: [quietsub.app/es](https://quietsub.app/es), [recur.es](https://www.recur.es/),
[subscro.com](https://www.subscro.com/), [subflow.ing/es](https://subflow.ing/es),
[renulo.app/es](https://renulo.app/es), [trackmyplans.com/es/inicio](https://trackmyplans.com/es/inicio),
[subbuddy.io/es](https://subbuddy.io/es), [findrecurring.com](https://findrecurring.com/),
[subless.me](https://subless.me/es/blog/mejores-apps-control-suscripciones-iphone).

## 8. Tabla comparativa

| Producto | Segmento | Precio (observado) | Descubrimiento | ¿Correo? |
|---|---|---|---|---|
| Rocket Money | Consumo (US) | Gratis; 7–14 $/mes; 15 $/mes | Banco | ❌ |
| Bobby | Consumo | Gratis + IAP | Manual | ❌ |
| Trim | Consumo (US) | — | Banco | ❌ |
| Emma | Consumo (UK) | 4,99–14,99 £/mes | Open banking | ❌ |
| Snoop | Consumo (UK) | Gratis; 5,99 £/mes | Banco | ❌ |
| Plum | Consumo (UK/UE) | Tiers **[SIN VERIFICAR]** | **[SIN VERIFICAR]** | ❌ |
| Fintonic | Consumo (ES) | Gratis | Banco | ❌ |
| Bnext | — | — | — | — (cerrado) |
| Cledara | Pyme/mediana | Gratis condicional o 100 £/mes; módulos 200–1.500 £ | Tarjetas virtuales | ❌ |
| Vertice | Gran empresa | Sin precio público | — | ❌ |
| Zluri | Gran empresa | Sin precio público | — | ❌ |
| Spendbase (.co) | Pyme | 0 $/usuario/mes | — | ❌ |
| Spendbase (.app) | Pyme/agencia | Gratis (10 subs); 19 $/mes; 49 $/mes | Manual | ❌ |
| Ramp | Pyme→gran empresa (US) | Gratis | OCR facturas | ❌ |
| Brex | Pyme→gran empresa | Sin precio público | IA facturas | ❌ |
| Payhawk | Pyme | 149 €/mes | — | ❌ |
| Wallester | Empresa/autónomo | Gratis para empezar | — | ❌ |
| **Unbilled** | Consumo→pyme | Gratis (5 subs); **Pro 1 €/mes** | **Correo + reenvío + extracto** | ✅ Gmail/Outlook |
| **Track-Subs** | Consumo | Gratis (10 subs); 6,99 $/mes | **Correo** | ✅ Gmail (IMAP pronto) |
| **What's My Subs** | Consumo | **[SIN VERIFICAR]** | **Correo** | ✅ Gmail |
| **Subzero** | Consumo | Gratis | **Correo** | ✅ Gmail |
| **Subby** | Consumo | Gratis (pre-lanzamiento) | **Correo** | ✅ Gmail |
| **Bill Radar** | Consumo | **[SIN VERIFICAR]** | **Correo (solo facturación)** | ✅ |
| **Yuki** | Consumo→prosumer | **[SIN VERIFICAR]** | **Correo** | ✅ Gmail |
| **Subflo** | Autoalojado | Gratis (MIT) | **Gmail IMAP + LLM** | ✅ Gmail IMAP |
| **Spendbox** | Consumo→pyme | Gratis (50 correos/mes) | **Reenvío** | ✅ reenvío |
| **LemSubs** | Consumo→pyme | **[SIN VERIFICAR]** | **Reenvío** | ✅ reenvío |
| **SpendKeeper** | Consumo | Gratis (iPhone) | **Reenvío (filtro Gmail)** | ✅ reenvío |
| **Gennai** | **Pyme** | **[SIN VERIFICAR]** | **Extracción de facturas** | ✅ |
| **Subscription Freedom** | Consumo | **[SIN VERIFICAR]** | **Reenvío** | ✅ reenvío |
| **Wallos** | Autoalojado | Gratis (OSS) | Manual | ❌ |
| Actual Budget / Firefly III / Maybe | Autoalojado | Gratis (OSS) | Banco | ❌ |
| QuietSub / Recur / Subscro / Subflow / Renulo / TrackMyPlans / SubBuddy / Subo | Consumo (ES/UE) | Gratis–11,99 €/año | Manual | ❌ |
| FindRecurring | Consumo | Gratis | Extracto pegado | ❌ |

### 8.1 Matriz de capacidades

Comparación por capacidad. **Leyenda:** ✅ verificado en la investigación (§2–§7) · ❌
verificado como ausente · **?** no verificado o no se pudo confirmar.

> **Aviso metodológico.** Las celdas con **?** significan *no verificado*, no *ausente*. No se
> han inventado funcionalidades: si la investigación no lo confirmó, queda como **?**. Las
> páginas de precios y de funcionalidades de varios productos no se pudieron leer completas
> (§12).

| Producto | Intro manual | Gmail | Outlook | IMAP genérico | Descubr. automático | Autón./pyme | ES/UE | Hist. precios | Renovac. | Privacidad | Almac. docs |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **Consumo con banco** | | | | | | | | | | | |
| Rocket Money | ❌ | ❌ | ❌ | ❌ | ✅ (banco) | ❌ | ❌ (US) | ? | ? | ? | ? |
| Emma | ❌ | ❌ | ❌ | ❌ | ✅ (open banking) | ❌ | ✅ (UK) | ? | ? | ? | ? |
| Snoop | ❌ | ❌ | ❌ | ❌ | ✅ (banco) | ❌ | ✅ (UK) | ? | ? | ? | ? |
| Fintonic | ❌ | ❌ | ❌ | ❌ | ✅ (banco) | ❌ | ✅ (ES) | ? | ? | ? | ? |
| **Manuales** | | | | | | | | | | | |
| Bobby | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ? | ✅ | ? | ? |
| Wallos (OSS) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ? | ✅ | ✅ (autoalojado) | ? |
| QuietSub / Recur / Subscro / Subflow / Renulo / TrackMyPlans / SubBuddy / Subo | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (ES/UE) | ? | ✅ | ? | ? |
| **Nativas de correo** | | | | | | | | | | | |
| Unbilled | ❌ | ✅ | ✅ | ❌ | ✅ | ? | ? | ? | ? | ? | ? |
| Track-Subs | ❌ | ✅ | ? (previsto) | ? (previsto) | ✅ | ❌ | ❌ | ? | ? | ? | ? |
| What's My Subs | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ? | ? | ? | ? |
| Subzero | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ? | ? | ? | ? |
| Subby | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ? | ? | ? | ? |
| Bill Radar | ❌ | ✅ | ? | ? | ✅ (solo facturación) | ? | ? | ? | ? | ? | ? |
| Yuki | ❌ | ✅ | ❌ | ❌ | ✅ | ? | ? | ? | ? | ? | ? |
| Subflo (OSS) | ❌ | ✅ | ❌ | ❌ (Gmail IMAP) | ✅ | ❌ | ❌ | ? | ? | ✅ (autoalojado) | ? |
| Spendbox | ❌ | ❌ | ❌ | ❌ | ✅ (reenvío) | ✅ | ? | ? | ? | ? | ? |
| LemSubs | ❌ | ❌ | ❌ | ❌ | ✅ (reenvío) | ✅ | ? | ? | ? | ? | ? |
| SpendKeeper | ❌ | ❌ | ❌ | ❌ | ✅ (reenvío) | ❌ | ❌ | ? | ? | ? | ? |
| Subscription Freedom | ❌ | ❌ | ❌ | ❌ | ✅ (reenvío) | ❌ | ❌ | ? | ? | ? | ? |
| Gennai | ❌ | ? | ? | ? | ✅ (facturas) | ✅ | ? | ? | ? | ? | ? |
| **Empresa** | | | | | | | | | | | |
| Cledara | ❌ | ❌ | ❌ | ❌ | ✅ (tarjetas) | ✅ | ✅ (UK) | ? | ? | ? | ? |
| Spendbase (.app) | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ? | ? | ? | ? | ? |
| Ramp | ❌ | ❌ | ❌ | ❌ | ✅ (OCR) | ✅ | ❌ (US) | ? | ? | ? | ? |
| Brex | ❌ | ❌ | ❌ | ❌ | ✅ (IA) | ✅ | ❌ (US) | ? | ? | ? | ? |
| Payhawk | ❌ | ❌ | ❌ | ❌ | ? | ✅ | ✅ (UE) | ? | ? | ? | ? |
| Vertice | ❌ | ❌ | ❌ | ❌ | ? | ✅ | ✅ (UK) | ? | ? | ? | ? |
| Zluri | ❌ | ❌ | ❌ | ❌ | ? | ✅ | ? | ? | ? | ? | ? |
| **Objetivo (no verificado, es nuestra propuesta)** | | | | | | | | | | | |
| Suscripta | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (por diseño) | ✅ |

**Lectura de la matriz.** Ninguna fila verificada combina **IMAP genérico** + **descubrimiento
automático** + **enfoque autónomo/pyme** + **España/UE**. Las nativas de correo se concentran
en Gmail y en consumo; las de pyme/empresa no usan correo genérico; las manuales no descubren
nada. La última fila es **nuestro objetivo**, no un hecho verificado.

## 9. El hueco: qué segmento está desatendido

> **Nivel de certeza.** **La investigación realizada hasta ahora no ha identificado
> competidores directos que combinen IMAP genérico, descubrimiento automático de servicios y
> enfoque específico en autónomos/pymes españolas o europeas.** Esto es un hallazgo acotado a
> lo que se pudo verificar (§12), no una afirmación de que no exista ningún competidor. Si
> aparece un producto que lo contradiga, hay que actualizar este documento y `PRODUCT.md` §10.

**Segmento desatendido: autónomos y pequeñas empresas de la UE que quieren descubrimiento
automático de costes recurrentes por correo, sin conexión bancaria, sin ERP y sin ciclos de
venta empresariales.**

Evidencia:

1. **Las herramientas de consumo van por banco y son solo de consumo.** Rocket Money, Emma,
   Snoop y Fintonic detectan por agregación bancaria. No tienen multi-cliente, IVA ni gasto de
   empresa.
2. **Las herramientas nativas de correo son de consumo y casi todas solo Gmail.** Unbilled,
   Track-Subs, Subzero, Subby, What's My Subs, Bill Radar, Yuki, SpendKeeper y Subscription
   Freedom son de consumo; varias están en beta o pre-lanzamiento; la mayoría solo soportan
   **Gmail** (Track-Subs lista Outlook/IMAP como "próximamente"). Ninguna anuncia flujos de
   autónomo/pyme (etiquetado por cliente, exportación lista para el asesor, multi-entidad).
3. **Las herramientas de pyme/gran empresa son pesadas y guiadas por ventas.** Cledara,
   Vertice, Zluri, Ramp, Brex y Payhawk exigen demo, tarjetas o incorporación de un equipo
   financiero. Gennai es lo más cercano en pyme, pero está posicionado para
   **contabilidad/cuentas a pagar**, no como tracker simple para autónomos.
4. **El mercado español/UE es todo manual.** Todos los "gestores de suscripciones" españoles
   verificados (QuietSub, Recur, Subscro, Subflow, Renulo, TrackMyPlans, SubBuddy, Subo) son
   **manuales**; ninguno hace descubrimiento por correo.
5. **El open source es manual salvo Subflo.** Wallos, el más popular, es manual.

**Dónde puede ganar un producto pequeño y enfocado** (simplicidad, enfoque y utilidad, no un
ERP):

- **Correo primero, nativo UE, privado por diseño:** IMAP + OAuth de solo lectura **y** un
  mecanismo de reenvío como alternativa (el patrón que usan LemSubs/Spendbox/Subscription
  Freedom para esquivar la carga del restricted scope de Google). Posicionamiento: "nunca
  tocamos tu banco".
- **Enfoque autónomo/pyme, no consumo:** etiquetado por cliente/proyecto, exportación lista
  para el asesor, multi-moneda, marcado de gasto deducible. Lo que les falta a las herramientas
  de consumo y les sobra a las empresariales.
- **La simplicidad como foso:** las empresariales son complejas y guiadas por ventas; las de
  consumo van por banco y son solo de consumo. Un producto de un solo propósito —"encuentra
  cada coste recurrente en tu correo, avísame antes de que renueve, expórtalo para mi
  asesor"— está libre.
- **UE/RGPD como característica:** una entidad en la UE con una historia clara de base jurídica
  (art. 6) es un diferenciador frente a herramientas centradas en EE. UU.

## 10. Productos que hacen parseo de facturas por correo/IMAP

- **Escaneo del buzón (OAuth):** Unbilled (Gmail/Outlook), Track-Subs (Gmail; Outlook/IMAP
  previsto), What's My Subs (Gmail), Subzero (Gmail), Subby (Gmail, pre-lanzamiento), Bill
  Radar (solo facturación), Yuki (Gmail), Subflo (Gmail IMAP + LLM, open source).
- **Reenvío (sin acceso al buzón):** Spendbox, LemSubs, SpendKeeper, Subscription Freedom.
- **Extracción de facturas para pyme:** Gennai (HTML en línea + PDF + enlaces a portales).
- **Herramientas adyacentes de recibos:** ExpenseBot, SparkReceipt, Mailparser.io.

**Notable:** **ninguno de los competidores de la lista original del encargo** (Rocket Money,
Bobby, Subly, TrackMySubs, Trim, Cledara, Vertice, Spendbase, Zluri, Ramp, Brex, Emma, Snoop,
Plum, Fintonic, Bnext, Wallester, Payhawk) hace **parseo del buzón por correo/IMAP** para
descubrir gasto recurrente. Los jugadores nativos de correo son una **cohorte separada y más
reciente**.

**Oportunidad abierta:** no se pudo verificar que ninguna de las herramientas nativas de correo
soporte **IMAP genérico** (cualquier proveedor) hoy. Track-Subs lista IMAP como "próximamente";
Subflo usa **Gmail IMAP con contraseña de aplicación**. Una oferta de **IMAP genérico** parece
un hueco real, **según lo verificado hasta ahora** — no una certeza de que nadie lo haga.

**Conclusión operativa.** La ventaja competitiva que se persigue no es tener más
funcionalidades, sino esta:

> **"Conecta tu correo y descubre automáticamente todo lo que estás pagando."**

Todo lo demás (dashboard, historial, alertas, documentos) debe reforzar esa experiencia
(`PRODUCT.md` §10.5).

## 11. Consideraciones regulatorias y de privacidad (UE)

**A. Base jurídica del RGPD (art. 6).** Las dos bases relevantes para el escaneo de recibos por
correo son el **consentimiento (art. 6.1.a)** y la **ejecución de un contrato (art. 6.1.b)**.
Unbilled mapea explícitamente los datos de recibos de correo a "art. 6.1.b — contrato; art.
6.1.a — consentimiento". El consentimiento debe ser una **acción positiva**, **granular**,
**separada de los términos y condiciones** y **fácil de retirar**.

**B. Minimización, retención y seguridad (art. 5).** El RGPD exige protección de datos desde el
diseño y por defecto, conservación "no más de lo necesario" y medidas técnicas apropiadas
(cifrado/pseudonimización). Los buzones son "un tesoro de datos personales": la política de
retención y el cifrado importan.

**C. Directiva ePrivacy, art. 5 — confidencialidad de las comunicaciones.** Acceder o almacenar
información en el equipo terminal del usuario (incluido el contenido de correo) activa la regla
de confidencialidad de las comunicaciones. Las **Directrices 2/2023 del CEPD** sobre el alcance
técnico del art. 5.3 son la referencia interpretativa actual. En la práctica refuerza la
necesidad de **consentimiento explícito e informado** antes de leer el buzón.

**D. "Restricted scope" de Google + CASA (la barrera práctica para Gmail).** El acceso de
lectura a Gmail es un **restricted scope**. Las apps que lo usan deben pasar **verificación** y,
si acceden a datos restringidos desde un servidor de terceros, someterse a una **evaluación
anual de seguridad por un tercero aprobado por Google** (CASA). El proceso "puede tardar varias
semanas" y requiere completar antes la verificación de marca. Por eso muchos competidores
pequeños usan **reenvío de correo** en lugar de OAuth de buzón.

**E. Cómo lo enmarcan los competidores (privacidad como marketing).**
- **Track-Subs:** solo lectura "aplicada a nivel de API por Google y Microsoft"; enumera
  exactamente qué lee y qué **no** lee.
- **Unbilled:** responsable en la UE (Chipre); mapeo del art. 6; ofrece **reenvío** e
  **importación de extractos** como alternativas sin acceso al buzón.
- **LemSubs / Subscription Freedom / SpendKeeper:** evitan explícitamente el acceso al buzón;
  SpendKeeper mantiene los datos **en el dispositivo**.
- **Cledara:** política de privacidad empresarial con contacto de DPO.

**Implicaciones prácticas para el producto:**
1. Ofrecer **reenvío + IMAP/OAuth** para que el usuario elija.
2. Documentar una **base jurídica del art. 6** clara y una política de **minimización y
   retención**.
3. Presupuestar la **verificación del restricted scope de Google + CASA anual** si se escanea
   Gmail en servidor.
4. Tratar el consentimiento del **art. 5 de ePrivacy** como requisito de primer nivel.
5. Valorar una **entidad en la UE** por posicionamiento RGPD.

Fuentes: [unbilled.app/privacy](https://unbilled.app/privacy),
[track-subs.com/privacy](https://www.track-subs.com/privacy),
[ico.org.uk — Consent](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/a-guide-to-lawful-basis/consent/),
[gdpr.eu/article-5](https://gdpr.eu/article-5-how-to-process-personal-data/),
[eprivacy-directive.eu/article-5](https://eprivacy-directive.eu/article-5-confidentiality-of-the-communications/),
[EDPB Guidelines 2/2023](https://www.edpb.europa.eu/documents/guideline/guidelines-22023-on-technical-scope-of-art-53-of-eprivacy-directive_en),
[developers.google.com — Restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification),
[lemsubs.com](https://lemsubs.com/features/receipts-inbox),
[subscriptionfreedom.com/help](https://subscriptionfreedom.com/help),
[spendkeeper.app](https://spendkeeper.app/),
[cledara.com/privacy-policy](https://www.cledara.com/privacy-policy).

## 12. Incertidumbres y datos no verificados

- **Snoop Plus:** el centro de ayuda oficial dice 5,99 £/mes o 47,99 £/año; reseñas de terceros
  citan cifras anteriores **[INCIERTO]**.
- **Plum:** no se pudo verificar si tiene detección de suscripciones ni sus precios exactos
  (sitio con muro de cookies) **[SIN VERIFICAR]**.
- **Bnext:** confirmado el cierre como neobanco y el pivote; el **año exacto es inconsistente**
  entre fuentes españolas **[INCIERTO]**.
- **Spendbase:** **dos entidades distintas** (`spendbase.co` banca vs `spendbase.app` tracker);
  `spendbase.com` es un dominio aparcado. No confundir.
- **Sin página de precios pública:** What's My Subs, Bill Radar, Yuki, LemSubs, Gennai,
  Subscription Freedom, Subo, SubLess **[SIN VERIFICAR]**.
- **Wallester** es una plataforma BaaS de tarjetas, **no** un tracker de suscripciones.
- **Ningún competidor de la lista original del encargo** hace parseo del buzón por correo/IMAP.
- **Limitaciones de búsqueda:** varios buscadores estaban bloqueados o eran solo JS; los
  hallazgos se basan en consultas directas a páginas oficiales y fichas de tiendas. Algunas
  páginas pesadas en JS (Bobby, Bnext, Plum) no se pudieron leer por completo.
- **No verificado:** si alguna de las herramientas nativas de correo soporta **IMAP genérico**
  (cualquier proveedor) hoy. Track-Subs lista IMAP como "próximamente"; Subflo usa Gmail IMAP
  con contraseña de aplicación. Una oferta de IMAP genérico parece un hueco abierto.
