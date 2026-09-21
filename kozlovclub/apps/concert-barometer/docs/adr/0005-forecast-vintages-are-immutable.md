# ADR 0005: Forecast vintages are immutable, enforced by trigger

Status: accepted. Date: 2026-09-21.

## Context

Principles 7 and 8: store every historical forecast vintage so forecasts can be
audited against outcomes, and allow no look-ahead leakage in backtests. A
forecast accuracy page is worthless if a past forecast can be rewritten, and
nobody rewrites one on purpose: it happens through an upsert in a re-run.

## Decision

`model.forecast` rejects UPDATE and DELETE via trigger. The same applies to
`raw.snapshot`, `norm.observation`, `norm.event_observation`, `norm.event_diff`,
`norm.information_attention`, `feature.feature_value` and `model.index_value`.

Two CHECK constraints make leakage unstorable rather than merely discouraged:
`feature_vintage <= forecast_created_at` and
`target_date >= forecast_created_at::date`.

Point-in-time reads go through `norm.observation_as_of(t)` and
`feature.feature_as_of(t)`, which filter on both vintage and availability.

## Consequences

A re-run that would previously have overwritten yesterday's forecast now fails
loudly. Correcting a forecast means issuing a new vintage, which is what an
auditable record requires. Storage grows with every vintage; at the volumes
involved this is negligible against the value of an honest accuracy page.
