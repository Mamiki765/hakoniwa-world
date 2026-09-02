# Ruleset authoring

The configured current Ruleset identity and entrypoint are defined by
`config/hakoniwa.php`. This README intentionally does not pin a concrete key or generation.

Normal application, tests, and operator validation load only that configured current
Ruleset. Historical source bytes, resolved payloads, checksums, and published database
snapshots remain immutable in their recorded Git/database authority. Markdown summaries do
not reproduce them, and test fixtures must never be registered or published as gameplay
Rulesets.

When a release begins from an Owner-confirmed production Ruleset `N`:

- semantic Ruleset changes may be authored as the single release draft `N+1`;
- unchanged fields are reused from the exact immutable predecessor and only bounded changed
  domains are composed into the new complete payload;
- the release does not create a generic historical-authoring catalog or inheritance
  framework; and
- later features, PRs, and stabilization in that same unreleased release continue to amend
  the same `N+1` draft rather than advancing another generation.

If no semantic Ruleset change is required, the release remains on `N`. One release may
introduce at most one new generation unless the Owner explicitly authorizes otherwise. If an
agent believes another generation is required, it must stop before implementation and ask the
Owner rather than creating it automatically.

Validation does not publish, migrate, or update a World. A source label such as `published`,
a PR, or repository state does not prove production freeze; use the Owner-confirmed
production baseline. Once a Ruleset is actually used by production, semantic changes require
a later generation and reviewed forward migration rather than rewriting the frozen identity.

Historical representation-only exceptions and retired Ruleset generations are preserved in
Git/history documents for provenance. They are not precedent for changing gameplay or balance
under an existing production identity.

See `product/docs/archive/rulesets/index.md` for the historical index and
`product/docs/architecture/ruleset-authoring.md` for current domain classification and
release-version discipline.
