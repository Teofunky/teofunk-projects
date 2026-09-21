# ADR 0003: Provenance is a foreign key, not a convention

Status: accepted. Date: 2026-09-21.

## Context

Principle 4 requires every external observation to keep source, endpoint,
observation period, geography, unit, retrieval time, methodology notes and a
raw snapshot hash. Systems that treat this as a documentation requirement lose
it within months: one hurried backfill script writes numbers without evidence,
and from then on the drill-down is a half-truth.

## Decision

`norm.observation.snapshot_id` is NOT NULL and references `raw.snapshot`. There
is no code path that writes an observation without first writing a snapshot, and
both happen in one transaction. `ProvenanceWriter` is the only supported writer.

A payload that cannot be parsed is still stored, with the failure recorded in
`raw.snapshot_parse_error`, so a gap in a series always has a visible cause.

Unit mismatches between an adapter and the registry are refused rather than
coerced.

## Consequences

Backfills are slightly more work: a historical CSV must be registered as a
snapshot with `legal_basis = 'user_supplied'` before its rows can be written.
That is the intended friction. In exchange, `norm.observation_provenance` is
complete by construction, and the drill-down in the UI cannot be empty.
