# ADR 0009: The YAML registries are the contract, the tables are a projection

Status: accepted. Date: 2026-09-21.

## Context

The source registry, segment layers, venue panel, index definition and agent
roster need to be reviewable in a pull request and queryable by foreign key at
runtime. Those are different jobs.

## Decision

`config/*.yaml` is the source of truth an engineer edits and a reviewer reads.
`barometer db sync-registry` projects it into `registry.*`, additively: rows are
inserted or updated, never deleted, because an observation may reference a
series that has since been retired from the YAML, and deleting it would orphan
real evidence. Retired entries are reported and left in place.

Each file's SHA-256 is recorded in `ops.schema_fingerprint` on every sync, so a
change to a registry between two pipeline runs is visible afterwards.

Cross-file validation happens at load: an index component referencing an
undeclared series, a series pointing at a missing segment, or an agent naming an
unknown source all fail with every problem listed at once.

## Consequences

Two representations to keep in step, handled by one idempotent command that CI
runs. In exchange, a change to what the system is allowed to observe is a diff a
human can review, and every observation still has referential integrity.
