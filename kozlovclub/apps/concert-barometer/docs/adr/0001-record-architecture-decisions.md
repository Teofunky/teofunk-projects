# ADR 0001: Record architecture decisions

Status: accepted. Date: 2026-09-21.

## Context

The system makes claims about a market. Whoever reads a number six months from
now needs to know why it is computed the way it is, and which constraints were
deliberate rather than accidental.

## Decision

Every decision that is expensive to reverse gets an ADR: context, decision,
consequences, and what would make us revisit it. ADRs are immutable once
accepted; a change is a new ADR that supersedes the old one.

Rebasing the index, changing the weighting method, enabling a new category of
source, and relaxing any of the ten non-negotiable principles all require an ADR.

## Consequences

Slower to change course, which is the point for decisions of this kind.
