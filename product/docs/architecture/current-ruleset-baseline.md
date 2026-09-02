# Current Ruleset baseline

## Scope

This document defines how to establish the current Ruleset baseline without hard-coding a
particular generation. Exact keys, versions, checksums, release numbers, and migration names
belong to current code, release-specific handoff material, migrations, tests, and Git history.

Do not copy a concrete generation into this document merely because it is current today.

## Repository baseline

For the repository state being reviewed, resolve the exact current Ruleset from:

1. `config/hakoniwa.php` for the configured application and Ruleset entrypoint;
2. that entrypoint and its bounded changed-domain fragments;
3. the current Ruleset validator and checksum/contract tests; and
4. the exact Git head under review.

Repository state proves what the code would author and validate. It does not by itself prove
what production is currently using.

## Production baseline

Production freeze and supported-upgrade decisions require Owner-confirmed production evidence.
Do not infer production state from `main`, a merged PR, an application version string, a schema
dump, a migration filename, or a source label such as `published`.

Let the Owner-confirmed production Ruleset at release start be `N`. Its key, version, full
payload, definitions, and checksum are immutable.

If the release needs a semantic Ruleset change, the only normal new generation for that
release is `N+1`. While the release remains unapplied to production, all of its Ruleset work
is integrated into that same `N+1` draft. See `ruleset-authoring.md` for the complete version
budget and classification rules.

## Dependency boundary

Normal application configuration loads one canonical current Ruleset entrypoint. A release
draft may explicitly reuse unchanged fields from the exact immutable predecessor and replace
only reviewed changed domains.

It must not introduce recursive inheritance, a dynamic historical catalog, a second runtime
Ruleset authority, or an unversioned gameplay definition source.

Historical source bytes, published database snapshots, and provenance remain available from
their recorded authorities. Unsupported historical PHP is not reconstructed from Markdown.

## Installation and forward migration

Fresh installation uses the repository's canonical schema/install path and publishes the
configured current Ruleset according to the current installer contract.

A supported production upgrade starts only from the exact Owner-confirmed source state. A
forward migration that introduces a new Ruleset must verify that source, publish one complete
new payload, preserve historical snapshots/provenance, and switch current live references by
the reviewed stable-key and transaction contract. It must not invent an unsupported upgrade
chain or reinterpret queued/historical work under a newer Ruleset.

The exact supported source, migration file, schema effects, preflight, and recovery procedure
are release-specific facts. Read them from the current migration, tests, handoff, and
operations runbook rather than accumulating them here release after release.

## Historical values

Exact historical Ruleset numbers, checksums, migration ledgers, retired source files, and
release transitions are valid historical evidence and should remain in versioned code,
migrations, tests, Git, archives, ADRs, and audit documents where they describe a specific
past boundary.

They are not permanent "current" policy and should not be promoted into this document.
