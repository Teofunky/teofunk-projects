.DEFAULT_GOAL := help
SHELL := /bin/bash
PY := .venv/bin/python
PIP := .venv/bin/pip
export PYTHONPATH := src

.PHONY: help
help:  ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

.venv:
	python3 -m venv .venv
	$(PIP) install -q --upgrade pip

.PHONY: install
install: .venv  ## Install the project and dev dependencies
	$(PIP) install -q -e '.[dev,quant,storage]'

.PHONY: up
up:  ## Start PostgreSQL and MinIO
	docker compose up -d --wait

.PHONY: down
down:  ## Stop the local stack
	docker compose down

.PHONY: migrate
migrate:  ## Apply database migrations
	.venv/bin/alembic upgrade head

.PHONY: rollback
rollback:  ## Roll back the last migration
	.venv/bin/alembic downgrade -1

.PHONY: sync
sync:  ## Project config/*.yaml into registry.* tables
	$(PY) -m barometer.cli db sync-registry

.PHONY: bootstrap
bootstrap: install up migrate sync  ## One command from nothing to a working stack
	$(PY) -m barometer.cli db status

.PHONY: ingest
ingest:  ## Collect every cleared, implemented source (needs network)
	$(PY) -m barometer.cli ingest all --days 14

.PHONY: demo
demo:  ## Run the pipeline on stored fixtures, no network needed
	$(PY) scripts/demo_offline.py --reset

.PHONY: validate
validate:  ## Validate every registry file
	$(PY) -m barometer.cli config validate

.PHONY: test
test:  ## Run the full test suite
	$(PY) -m pytest

.PHONY: test-fast
test-fast:  ## Run only tests that need no database
	$(PY) -m pytest -m 'not db'

.PHONY: lint
lint:  ## Lint and type-check
	.venv/bin/ruff check src tests
	.venv/bin/ruff format --check src tests

.PHONY: format
format:  ## Auto-format
	.venv/bin/ruff format src tests
	.venv/bin/ruff check --fix src tests

.PHONY: schema-dump
schema-dump:  ## Regenerate db/schema.sql from the migrated database
	./scripts/dump_schema.sh

.PHONY: check
check: validate lint test  ## Everything CI runs
