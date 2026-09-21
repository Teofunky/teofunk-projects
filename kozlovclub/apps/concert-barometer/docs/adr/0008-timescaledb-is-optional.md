# ADR 0008: TimescaleDB is an optimisation, not a dependency

Status: accepted. Date: 2026-09-21.

## Context

The specification asks for PostgreSQL with TimescaleDB. TimescaleDB is not
available in every environment: CI images, some managed PostgreSQL offerings and
developer machines without Docker.

Discovered while building: the development container for this phase had
PostgreSQL 16 and no TimescaleDB extension, which is exactly the situation the
decision has to survive.

## Decision

The schema runs on plain PostgreSQL 14+ and uses TimescaleDB when present.
Migration 0001 checks `pg_available_extensions` and either creates hypertables
or creates BRIN indexes on the same time columns.

Every table that would be a hypertable has its time column leading the primary
key, which both satisfies TimescaleDB's requirement that unique constraints
include the partitioning column and gives good locality without it.

`BAROMETER_REQUIRE_TIMESCALEDB=true` makes its absence an error, for production.

## Consequences

The full test suite runs anywhere PostgreSQL runs. Query plans differ between
the two configurations, so performance work must state which it was measured on.
Compression and continuous aggregates are available only on the TimescaleDB
path, and nothing in the current design depends on them.
