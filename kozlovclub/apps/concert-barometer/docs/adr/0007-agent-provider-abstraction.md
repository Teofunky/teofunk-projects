# ADR 0007: Agents run behind a provider abstraction

Status: accepted. Date: 2026-09-21.

## Context

The specification requires Claude and OpenAI to be swappable. Both offer agents,
tools, structured outputs and tracing, with different names and different
handoff models.

## Decision

`config/agents.yaml` declares providers with an adapter path, a model env var
and an API key env var. Agent definitions are provider-neutral: prompt file,
allowed tools, input and output schema, retry policy, provenance requirements,
guardrails, evals.

The output contract is enforced by the orchestrator, not the provider: an agent
output asserting something about the world without provenance and an assertion
class is rejected and recorded in `ops.agent_invocation.contract_violations`.

## Consequences

Provider-specific features (OpenAI handoffs, Anthropic subagents) are available
to the adapter but cannot appear in an agent definition, so switching provider
never silently changes what an agent is allowed to do. The cost is that the
richest features of each provider are used through a narrower interface.
