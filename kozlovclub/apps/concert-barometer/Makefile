.DEFAULT_GOAL := help
SHELL := /bin/bash
PY := .venv/bin/python
PIP := .venv/bin/pip
export PYTHONPATH := src

# Pick an interpreter the dependency stack is actually verified against.
# Python 3.14 changed typing internals in a way pydantic 2.13 does not handle,
# so a machine whose default python3 is 3.14 fails at import time with
# "_eval_type() got an unexpected keyword argument 'prefer_fwd_module'".
# Newest verified version first; an unverified one is used only when nothing
# else is installed, and `make doctor` says so rather than failing obscurely.
PYTHON_BIN ?= $(shell for p in python3.13 python3.12 python3.11 python3; do \
	command -v $$p >/dev/null 2>&1 && echo $$p && break; done)

# uv, when it is installed, creates the environment and installs faster, and
# copes with interpreters that ship without ensurepip (some standalone builds),
# where `python -m venv` produces an environment with no pip in it.
HAVE_UV := $(shell command -v uv >/dev/null 2>&1 && echo yes)

.PHONY: help
help:  ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

.venv:
	@echo "Creating .venv with $(PYTHON_BIN) ($$($(PYTHON_BIN) --version 2>&1))"
ifeq ($(HAVE_UV),yes)
	uv venv --python $(PYTHON_BIN) .venv
else
	$(PYTHON_BIN) -m venv .venv
	$(PIP) install -q --upgrade pip
endif

.PHONY: install
install: .venv  ## Install the project and dev dependencies
	# Only what the current code actually imports. The quant, api and storage
	# extras belong to phases that have not been written yet, and installing
	# them now can fail somebody's setup for no benefit today. Add them with
	# `make install-quant` when Phase 5 lands.
ifeq ($(HAVE_UV),yes)
	uv pip install -q --python .venv/bin/python -e '.[dev]'
else
	$(PIP) install -q -e '.[dev]'
endif

.PHONY: install-quant
install-quant: .venv  ## Add the modelling dependencies (needed from Phase 5)
ifeq ($(HAVE_UV),yes)
	uv pip install -q --python .venv/bin/python -e '.[dev,quant,api,storage]'
else
	$(PIP) install -q -e '.[dev,quant,api,storage]'
endif

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
