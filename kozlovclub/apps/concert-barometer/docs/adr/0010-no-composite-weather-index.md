# ADR 0010: No composite weather index; the model gets the raw components

Status: accepted. Date: 2026-09-21. Refines ADR 0004.

## Context

`config/sources.yaml` originally declared a series `weather.friction_index`, and
the `weather_friction` component of the barometer took it as its single input.
The adapter was to compute it from evening temperature, precipitation and wind.

Writing that adapter made the problem concrete. Any such index needs thresholds:
rain counts as bad above roughly 5 mm over an evening, wind above roughly
10 m/s, cold below roughly -15 C. Those numbers are meteorologically
conventional and entirely plausible. They are also coefficients, chosen by a
person, that would then propagate into the headline index.

Principle 2 says the barometer is calculated by reproducible statistical code
and principle 1 says nobody hand-assigns what matters. A composite built from
conventional constants satisfies neither: it is a weighting decision wearing the
costume of a measurement.

There is also a substantive objection. How much rain deters a Moscow audience
on a Tuesday in November is an empirical question about this market, and the
answer is not the same as it would be for an outdoor festival in July. Fixing
the trade-off in the adapter throws away the ability to learn it.

## Decision

No composite weather series is published. The `weather_friction` component takes
the three raw series directly:

- `weather.temp_evening.msk.daily`, as deviation from its seasonal norm
- `weather.precip_evening.msk.daily`, log1p transformed
- `weather.wind_evening.msk.daily`, raw

Their effect on demand is estimated by the quant layer along with every other
loading, and their direction is `mixed` rather than asserted as negative,
because a mild evening and a freezing one do not sit on one signed axis.

`min_sources_for_publication` rises from 1 to 2, so the component is not
published off a single weather variable.

## Consequences

The weather component now needs at least two of three variables present rather
than one derived number, which makes it slightly more fragile to a partial
Open-Meteo response. That is the correct trade: a component that quietly
publishes off one hand-weighted composite is worse than one that says it lacks
data.

A friction index may still be worth showing to a reader. If it returns, it
returns as a display-only derived series that no model consumes, and it says so.

## What would make us revisit this

A fitted weather sub-model whose coefficients come from the same backtest
machinery as everything else. That is a composite the system produced rather
than one a person supplied, and it belongs in the feature layer with a version.
