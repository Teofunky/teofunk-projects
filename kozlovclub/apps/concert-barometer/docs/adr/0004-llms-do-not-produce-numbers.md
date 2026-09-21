# ADR 0004: The LLM boundary is a database privilege

Status: accepted. Date: 2026-09-21.

## Context

Principle 1 says LLMs collect, classify, verify and explain, and do not invent
the numerical barometer. Stated as a prompt instruction, this holds until the
first time an agent is asked to "just estimate" a missing component and quietly
does so.

## Decision

Three independent layers:

1. `config/agents.yaml` cannot grant an agent `db.write_model` or `quant.write`;
   the Pydantic validator rejects the file with a reference to principle 1.
2. The database role `barometer_agent` has SELECT on schemas `model` and
   `feature` and no INSERT, UPDATE or DELETE. `barometer_quant` has the writes.
3. Quant Analyst and Forecast Analyst are typed as `role: explainer`: they read
   a finished model run and describe it. Neither has a write tool at all.

## Consequences

An agent that needs a number must ask the quant pipeline to produce it, which
means every published number has a model run, parameters and a backtest behind
it. Agents cannot paper over a missing component, so a missing component shows
up as reduced confidence and coverage, which is the honest outcome.
