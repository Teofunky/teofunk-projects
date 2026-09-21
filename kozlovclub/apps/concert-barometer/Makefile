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
	# Only what the current code actually imports. The quant and storage extras
	# belong to phases that have not been written yet, and lightgbm in
	# particular is a large dependency that can fail a first install on macOS
	# for no benefit today. Add them with `make install-quant` when Phase 5 lands.
	$(PIP) install -q -e '.[dev]'

.PHONY: install-quant
install-quant: .venv  ## Add the modelling dependencies (needed from Phase 5)
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
bootstrap: doctor install up migrate sync  ## One command from nothing to a working stack
	$(PY) -m barometer.cli db status
	@echo
	@echo "Ready. Next:"
	@echo "  make demo     run the pipeline on fixtures, no network needed"
	@echo "  make ingest   collect the four cleared sources (needs network)"
	@echo "  make test     run the test suite"

.PHONY: bootstrap-no-docker
bootstrap-no-docker: install migrate sync  ## Same, against a PostgreSQL you already run
	$(PY) -m barometer.cli db status

.PHONY: doctor
doctor:  ## Check the prerequisites before anything else fails confusingly
	@./scripts/doctor.sh

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
