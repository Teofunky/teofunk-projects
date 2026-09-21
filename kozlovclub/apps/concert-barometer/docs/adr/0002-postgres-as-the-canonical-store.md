# ADR 0002: PostgreSQL with a layered schema is the canonical store

Status: accepted. Date: 2026-09-21. Supersedes nothing.

## Context

Data arrives from a dozen sources at different frequencies, with revisions, and
must be auditable years later. Candidates considered: a document store per
source, a data-lake-first design with files as the source of truth, and a
relational database with explicit layers.

## Decision

PostgreSQL is canonical, with six schemas forming an immutable pipeline:
`registry` (what may be observed), `raw` (evidence), `norm` (observations),
`feature` (model inputs), `model` (output), `ops` (operations).

Raw bytes live in object storage, addressed by SHA-256, with the hash and URI on
the `raw.snapshot` row. The database holds the provenance envelope; the object
store holds the payload.

## Why not the alternatives

A document store per source pushes normalisation into query time, so two
consumers of the same source can silently disagree about what a field means.

A data-lake-first design makes the file the source of truth, which is attractive
until a value needs a foreign key to a series definition and a check constraint
saying an inference cannot be a fact. Those guarantees need a relational engine.

## Consequences

Constraints and triggers can enforce the non-negotiable principles, which is the
main reason for the choice. The cost is that schema changes require migrations,
and that very high-volume snapshot data may later need ClickHouse alongside
(explicitly left open by the master specification).
