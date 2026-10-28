# Suscripta

Micro-SaaS para autónomos y pequeñas empresas que **descubren y controlan automáticamente sus
servicios y gastos recurrentes a partir de su correo de facturación**.

> Conecta el buzón donde realmente recibes tus facturas y Suscripta encuentra los servicios que
> estás pagando, cuánto suben, cuándo se renuevan y cuáles se han olvidado.

No es un «gestor de suscripciones» con formularios: es **descubrimiento automático desde el
correo** (IMAP genérico, cualquier proveedor) más el control que se construye encima. El detalle
del producto está en [docs/PRODUCT.md](docs/PRODUCT.md).

## Estado

| | |
|---|---|
| Fases del roadmap | **0 a 19 cerradas** (ver [docs/ROADMAP.md](docs/ROADMAP.md)) |
| Pendiente | Nada comprometido. La fase 20 (webhooks) es una idea, no un compromiso |
| Tests | **1 420** (unitarios y funcionales), todos en verde |
| Análisis estático | PHPStan **nivel 8**, sin errores |
| PHP / Symfony | PHP **8.5**, Symfony **8.1** |
| API pública | `/api/v1`, 13 rutas, OpenAPI en `/api/doc` |

## Qué hace

1. **Conecta tu correo** (IMAP o reenvío a una dirección dedicada). Suscripta no lee todo: filtra
   por remitente, asunto y adjuntos antes de gastar un céntimo en analizar.
2. **Extrae los datos de la factura** en capas: primero reglas y parsers deterministas, luego
   conocimiento de proveedores, y solo si hace falta IA —con presupuesto y consentimiento—.
3. **Propone un servicio** y te enseña *por qué* lo propone (las señales que han coincidido).
   Tú confirmas; el sistema no da de alta nada a tus espaldas.
4. **Vigila a partir de ahí**: historial de precios, subidas, renovaciones, próximos cobros,
   duplicados y servicios dormidos.
5. **Se integra con lo que ya usas**: API pública con claves revocables por ámbito.

## Empezar en cinco minutos

Requisitos: Docker con Compose v2.10+ y `make` (opcional).

```bash
git clone https://github.com/dvalverdeGF/Suscripta.git && cd Suscripta
make up            # construye si hace falta, levanta y espera a que esté sano
make migrate       # aplica las 19 migraciones
```

Abre **https://localhost** y acepta el certificado autofirmado. El resto de órdenes está en
`make help`:

| Orden | Qué hace |
|---|---|
| `make up` / `make down` | Levanta / detiene el entorno |
| `make logs` / `make sh` | Sigue los logs / abre una shell en el contenedor |
| `make db-reset` | Recrea la base de datos con migraciones y fixtures |
| `make migrate` / `make migration` | Aplica / genera migraciones |
| `make test` | Suite completa |
| `make qa` | Estilo + PHPStan + tests (lo que corre la CI) |

Sin `make`: `docker compose up -d --wait` y luego
`docker compose exec php bin/console doctrine:migrations:migrate -n`.

En **desarrollo** la pantalla de login enseña las credenciales de prueba, para no tener que
buscarlas en la base de datos. En producción esa ayuda no se pinta.

## Integrar otro proyecto con la API

La API existe para que **otros sistemas** puedan leer y escribir en Suscripta sin pasar por la
interfaz. Se autentica con una **clave de API** emitida desde el panel (`/api-keys`), con
ámbitos (`read` / `write`), caducidad opcional y revocación inmediata. En la base de datos solo
se guarda su huella HMAC: la clave en claro se enseña **una sola vez**.

```bash
curl -H "Authorization: Bearer sk_tuClave" https://localhost/api/v1/services
```

- Guía de integración de cero a la primera llamada: [docs/api/README.md](docs/api/README.md)
- Catálogo de errores: [docs/api/errors.md](docs/api/errors.md)
- Versión estable y política de deprecación: [docs/api/versioning.md](docs/api/versioning.md),
  [docs/api/deprecation.md](docs/api/deprecation.md)
- Contrato legible por máquina: **`/api/doc.json`** (OpenAPI 3); navegable en `/api/doc`.

## Documentación

| Documento | Contenido |
|---|---|
| [docs/PRODUCT.md](docs/PRODUCT.md) | Problema, usuario, propuesta de valor, funcionalidades, qué **no** es el producto. |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Módulos, dominio, pipeline de análisis (§13), multi-tenancy, API (§17). |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Las decisiones y **por qué**, con las alternativas descartadas. |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Las 21 fases, qué se entregó en cada una y qué falta. |
| [docs/SECURITY.md](docs/SECURITY.md) | Modelo de amenazas, acceso al correo, credenciales, RGPD. |
| [docs/operations.md](docs/operations.md) | Runbook: despliegue, copias, restauración, incidentes. |
| [docs/deployment.md](docs/deployment.md) | **Desplegar en producción**, paso a paso, con verificación. |
| [docs/COMPETITORS.md](docs/COMPETITORS.md) | Alternativas del mercado y el hueco que ocupa Suscripta. |
| [CHANGELOG.md](CHANGELOG.md) | Qué cambió en cada versión (Keep a Changelog). |
| [docs/LEARNING.md](docs/LEARNING.md) | Especificación de origen del pipeline: el encargo, no el diseño. |
| [docs/agents.md](docs/agents.md) | Cómo trabajan los agentes de IA en este repositorio. |

## Cómo está construido

Monolito modular en Symfony, sin microservicios: un módulo por concepto del negocio
(`Identity`, `Services`, `Documents`, `Mailbox`, `Processing`, `Discovery`, `Ai`, `Api`…), cada
uno con su dominio, su aplicación y su infraestructura, comunicados por interfaces y no por
entidades ajenas. El aislamiento entre organizaciones lo garantiza un `TenantContext` que fijan
tanto el firewall del backoffice como el autenticador de la API: **un solo mecanismo**, no dos
que puedan divergir.

La IA es una herramienta de última instancia, no el motor: llega después de los filtros, los
parsers y el conocimiento de proveedores, y su coste se mide en microcéntimos por organización
con presupuesto y límites. Los detalles están en [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Licencia

El producto es propietario. La plantilla de infraestructura sobre la que se apoya (Symfony
Docker, de Kévin Dunglas) es MIT; sus guías siguen disponibles más abajo porque describen el
entorno que usa este proyecto.

---

# Symfony Docker (infraestructura)

A [Docker](https://www.docker.com/)-based installer and runtime for the [Symfony](https://symfony.com) web framework,
with [FrankenPHP](https://frankenphp.dev) and [Caddy](https://caddyserver.com/) inside!

Coding-agents ready: ships with a [Dev Container](https://containers.dev/) and a [one-page guide](docs/agents.md)
to run [OpenCode](https://opencode.ai), [Claude Code](https://claude.ai/claude-code), or any AI coding assistant,
against a local or a remote model, with an optional network sandbox.

![CI](https://github.com/dunglas/symfony-docker/workflows/CI/badge.svg)

## Features

- Production, development and CI ready
- Just 1 service by default
- Super-readable configuration
- Blazing-fast performance thanks to [the worker mode of FrankenPHP](https://frankenphp.dev/docs/worker/)
- [Installation of extra Docker Compose services](docs/extra-services.md) with Symfony Flex
- Automatic HTTPS (in dev and prod)
- HTTP/3 and [Early Hints](https://symfony.com/blog/new-in-symfony-6-3-early-hints) support
- Real-time messaging thanks to a built-in [Mercure hub](https://symfony.com/doc/current/mercure.html)
- [Vulcain](https://vulcain.rocks) support
- Native [XDebug](docs/xdebug.md) integration
- [Hot Reloading](https://frankenphp.dev/docs/hot-reload/)
- [Dev Container](https://containers.dev/) support
- [AI coding agents](docs/agents.md) with an optional network sandbox
- Rootless, slim production image

**Enjoy!**

## Docs

1. [Options available](docs/options.md)
2. [Using Symfony Docker with an existing project](docs/existing-project.md)
3. [Support for extra services](docs/extra-services.md)
4. [Deploying in production](docs/production.md)
5. [Debugging with Xdebug](docs/xdebug.md)
6. [TLS Certificates](docs/tls.md)
7. [Using MySQL instead of PostgreSQL](docs/mysql.md)
8. [Using Alpine Linux instead of Debian](docs/alpine.md)
9. [Using a Makefile](docs/makefile.md)
10. [Updating the template](docs/updating.md)
11. [Troubleshooting](docs/troubleshooting.md)
12. [Using AI coding agents](docs/agents.md)

## License

Symfony Docker is available under the MIT License.

## Credits

Created by [Kévin Dunglas](https://dunglas.dev), co-maintained by [Maxime Helias](https://twitter.com/maxhelias) and sponsored by [Les-Tilleuls.coop](https://les-tilleuls.coop).
