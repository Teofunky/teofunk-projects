"""Alembic environment. The database URL always comes from barometer.settings."""

from __future__ import annotations

from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool

import barometer.db.models  # noqa: F401  (registers every table on Base.metadata)
from barometer.db.base import ALL_SCHEMAS, Base
from barometer.settings import get_settings

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

config.set_main_option("sqlalchemy.url", get_settings().database_url)
target_metadata = Base.metadata

#: Objects Alembic must not try to manage: TimescaleDB's internal catalogs and
#: anything inside a schema we did not create.
IGNORED_SCHEMAS = {"_timescaledb_internal", "_timescaledb_catalog", "timescaledb_information"}


def include_object(obj, name, type_, reflected, compare_to) -> bool:
    schema = getattr(obj, "schema", None)
    if schema in IGNORED_SCHEMAS:
        return False
    if type_ == "table" and schema is not None and schema not in ALL_SCHEMAS:
        return False
    if type_ == "table" and name == "alembic_version":
        return False
    # BRIN indexes are created conditionally by 0001_epilogue.sql only when
    # TimescaleDB is absent, so they are deliberately not in the ORM metadata.
    return not (type_ == "index" and name and name.startswith("brin_"))


def run_migrations_offline() -> None:
    context.configure(
        url=config.get_main_option("sqlalchemy.url"),
        target_metadata=target_metadata,
        literal_binds=True,
        include_schemas=True,
        include_object=include_object,
        version_table_schema="public",
        dialect_opts={"paramstyle": "named"},
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            include_schemas=True,
            include_object=include_object,
            version_table_schema="public",
            compare_type=True,
            compare_server_default=True,
        )
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
