# Suscripta — atajos de desarrollo.
# Todos los comandos de PHP se ejecutan dentro del contenedor `php`.

DC := docker compose
EXEC := $(DC) exec -T php

.DEFAULT_GOAL := help

.PHONY: help
help: ## Muestra esta ayuda
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------- Infraestructura

.PHONY: up
up: ## Levanta el entorno de desarrollo
	$(DC) up --wait

.PHONY: down
down: ## Detiene el entorno
	$(DC) down

.PHONY: build
build: ## Reconstruye las imágenes
	$(DC) build

.PHONY: logs
logs: ## Sigue los logs
	$(DC) logs -f

.PHONY: sh
sh: ## Abre una shell en el contenedor php
	$(DC) exec php sh

# ---------------------------------------------------------------- Base de datos

.PHONY: db-create
db-create: ## Crea la base de datos
	$(EXEC) bin/console doctrine:database:create --if-not-exists

.PHONY: db-drop
db-drop: ## Elimina la base de datos
	$(EXEC) bin/console doctrine:database:drop --force --if-exists

.PHONY: db-reset
db-reset: ## Recrea la base de datos y aplica migraciones y fixtures
	$(EXEC) bin/console doctrine:database:drop --force --if-exists
	$(EXEC) bin/console doctrine:database:create
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction
	$(EXEC) bin/console doctrine:fixtures:load --no-interaction

.PHONY: migration
migration: ## Genera una migración a partir del mapeo actual
	$(EXEC) bin/console doctrine:migrations:diff --no-interaction

.PHONY: migrate
migrate: ## Aplica las migraciones pendientes
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction

.PHONY: schema-validate
schema-validate: ## Valida el mapeo de Doctrine contra el esquema
	$(EXEC) bin/console doctrine:schema:validate

.PHONY: db-test
db-test: ## Recrea la base de datos de test y aplica migraciones
	$(EXEC) bin/console doctrine:database:drop --force --if-exists --env=test
	$(EXEC) bin/console doctrine:database:create --env=test
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --env=test

# ---------------------------------------------------------------- Calidad

.PHONY: test
test: ## Ejecuta la suite de tests
	$(EXEC) bin/phpunit

.PHONY: test-unit
test-unit: ## Ejecuta solo los tests unitarios
	$(EXEC) bin/phpunit --testsuite Unit

.PHONY: test-functional
test-functional: ## Ejecuta solo los tests funcionales
	$(EXEC) bin/phpunit --testsuite Functional

.PHONY: phpstan
phpstan: ## Ejecuta el análisis estático
	$(EXEC) bin/console cache:warmup --env=dev
	$(EXEC) vendor/bin/phpstan analyse --no-progress

.PHONY: cs
cs: ## Corrige el estilo de código
	$(EXEC) vendor/bin/php-cs-fixer fix

.PHONY: cs-check
cs-check: ## Comprueba el estilo de código sin modificar
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: qa
qa: cs-check phpstan test ## Ejecuta todas las comprobaciones de calidad

# ---------------------------------------------------------------- Workers

.PHONY: worker
worker: ## Consume la cola de procesamiento de correo
	$(EXEC) bin/console messenger:consume mail_sync mail_processing ocr ai notifications \
		--time-limit=3600 --memory-limit=256M -vv

.PHONY: worker-ai
worker-ai: ## Consume únicamente la cola de IA
	$(EXEC) bin/console messenger:consume ai --time-limit=3600 --memory-limit=256M -vv

.PHONY: failed
failed: ## Lista los mensajes fallidos
	$(EXEC) bin/console messenger:failed:show

.PHONY: failed-retry
failed-retry: ## Reintenta los mensajes fallidos
	$(EXEC) bin/console messenger:failed:retry

# ---------------------------------------------------------------- Tareas programadas

.PHONY: sync
sync: ## Sincroniza los buzones activos
	$(EXEC) bin/console app:mail:sync -v

.PHONY: alerts
alerts: ## Recalcula los avisos de cobros, renovaciones y plazos
	$(EXEC) bin/console app:alerts:generate -v
