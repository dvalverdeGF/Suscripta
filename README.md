# Suscripta

Micro-SaaS para autónomos y pequeñas empresas que **descubren y controlan automáticamente sus
servicios y gastos recurrentes a partir de su correo de facturación**.

> **Conecta el correo donde realmente recibes tus facturas y descubre automáticamente los
> servicios que estás pagando.**

No es un "gestor de suscripciones" ni un "gestor de renovaciones": es **descubrimiento
automático desde el correo** (IMAP genérico, cualquier proveedor) más el control que se
construye encima. Ver [docs/PRODUCT.md](docs/PRODUCT.md) §3 y §10.

## Documentación del producto

| Documento | Contenido |
|---|---|
| [docs/PRODUCT.md](docs/PRODUCT.md) | Problema, usuario, propuesta de valor, hipótesis de producto, funcionalidades, qué NO es el producto, flujo, casos de uso, diferenciación. |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Módulos, modelo de dominio, separación fuente/procesamiento/dominio/valor, extracción por capas, **pipeline de análisis de correo (§13)**, Messenger, persistencia, integraciones, multi-tenancy, frontend. |
| [docs/SECURITY.md](docs/SECURITY.md) | Acceso al correo, credenciales, documentos, extracción e IA, control de coste de IA, aislamiento de tenants, retención, RGPD. |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Fases de desarrollo con criterios de verificación (vertical end-to-end en la fase 3; pipeline de análisis en las fases 9–14). |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Decisiones arquitectónicas (ADR) y su motivo. |
| [docs/COMPETITORS.md](docs/COMPETITORS.md) | Análisis de soluciones similares, matriz de capacidades y hueco de mercado. |
| [docs/LEARNING.md](docs/LEARNING.md) | **Especificación de origen del pipeline de análisis de correo.** Documento de requisitos; el diseño resultante está en `ARCHITECTURE.md` §13. |

> Estado actual: **documentación y arquitectura**. No hay todavía código de producto.

---

# Symfony Docker

A [Docker](https://www.docker.com/)-based installer and runtime for the [Symfony](https://symfony.com) web framework,
with [FrankenPHP](https://frankenphp.dev) and [Caddy](https://caddyserver.com/) inside!

Coding-agents ready: ships with a [Dev Container](https://containers.dev/) and a [one-page guide](docs/agents.md)
to run [OpenCode](https://opencode.ai), [Claude Code](https://claude.ai/claude-code), or any AI coding assistant,
against a local or a remote model, with an optional network sandbox.

![CI](https://github.com/dunglas/symfony-docker/workflows/CI/badge.svg)

## Getting Started

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Run `docker compose build --pull --no-cache` to build fresh images
3. Run `docker compose up --wait` to set up and start a fresh Symfony project
4. Open `https://localhost` in your favorite web browser and [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
5. Run `docker compose down --remove-orphans` to stop the Docker containers.

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
