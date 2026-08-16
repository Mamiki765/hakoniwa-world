# ADR-0010: Product generations and the ver 2.x identity

- Status: Accepted
- Date: 2026-08-16
- Scope: product identity and roadmap boundaries

## Context

ver 1.x adapted and organized the principal gameplay inherited from 箱庭諸島2 and 箱庭諸島2＋ for one shared World. ver 1.7.0 closes that principal-original-feature phase. Later work needs a stable product identity without treating every possible future feature as approved scope.

## Decision

Product generations are defined as follows.

- ver 1.x is the generation that reproduces and reorganizes the principal 箱庭諸島2 / 箱庭諸島2＋ behavior for the shared-World architecture. ver 1.7.0 is its principal-original-feature milestone.
- ver 2.x is the 「箱庭諸島2S＋独自システム構築期」. Secretary, inheriting the lineage of the former 2S concept, is the central product identity. User-persistent gameplay, skills, items, equipment, PD, and blueprints may be built incrementally only through separately approved roadmap slices.
- ver 3.x and later may re-research historical derivative works and 箱庭 culture and develop new 2S＋-specific behavior only after checking applicable usage conditions.

A feature being named as a possible direction does not approve its design or implementation. Each roadmap slice still follows `docs/open-questions.md`, published-ruleset immutability, production-data migration, and third-party reference boundaries.

## Consequences

- ver 2.0.0 may implement the first vertical Secretary slice defined by ADR-0011.
- Accessory, equipment, PD, active skills, blueprints, generic modifier/proficiency frameworks, and other future candidates remain outside ver 2.0.0.
- This ADR records generation identity only. Secretary gameplay details belong to ADR-0011 and must not be inferred from this identity statement.
