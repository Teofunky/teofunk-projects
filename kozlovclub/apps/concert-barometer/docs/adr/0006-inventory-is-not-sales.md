# ADR 0006: What a series measures, not what it comes from, bounds its claim

Status: accepted. Date: 2026-09-21.

## Context

Principle 5 forbids labelling competitor ticket availability as actual sales.
The first implementation enforced this by source: any series on a source marked
`is_proxy` could claim at most `estimate`.

That rule was wrong, and the validator caught it on first load. Yandex Wordstat
is a proxy for demand, but "Yandex reported N impressions for this phrase last
week" is an observation. Forcing it to `estimate` would have understated what
we actually know, and would have trained everyone to ignore the label.

## Decision

Each series declares `measures`:

- `direct`: the source published this number. May be a fact, on any source.
- `derived`: a deterministic function of direct observations. Never a fact.
- `inferred`: an inference about the concept a proxy stands in for. Never
  stronger than `estimate`.

The proxy flag stays on the source, where it drives the UI badge and requires
`proxy_for` and `methodology_notes`. What bounds the claim is `measures`.

So: `venue.events_listed` (counting what is listed) is `direct` and a fact.
`venue.median_price` (median of posted prices) is `derived`. And
`venue.inventory_pressure` (demand read out of inventory) is `inferred` and can
never be more than an estimate. There is deliberately no `tickets_sold` column
for panel venues anywhere in the schema, and `norm.event_observation` stores
`availability_label` verbatim from the page rather than an interpretation.

## Consequences

The epistemics are more precise and the labels stay meaningful. Enforcement sits
in three places: Pydantic at load time, a CHECK on `registry.series`, and a
trigger on observation insert, so a direct SQL write cannot bypass it.
