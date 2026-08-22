# Ruleset authoring

Each PHP file here returns one complete authored snapshot. The `roadmap-pr*` files are
historical published MVP snapshots; `hakoniwa-2s-plus-v1.php` through `v11.php` are formal
published Rulesets. Pre-MVP prototypes live only in repository history, while test fixtures
live under `tests/` and must never be registered or published as gameplay Rulesets.

`config/hakoniwa.php` loads only the standalone current Ruleset. Historical authored files
remain explicit inputs to `RulesetUpgradeAuthoringCatalog`, which is installed only when a
database migration starts or by the test base class. Do not replace that list with a glob:
order and provenance must stay deterministic and reviewable. Historical authored source
bytes, resolved payloads, checksums, and published database snapshots are immutable. In
particular, normally do not edit, format, comment, rename, or remove an existing historical
file.

The ver 2.4.0 source-dependency rebaseline has one explicit exception: the current
`hakoniwa-2s-plus-v11` authored PHP source was mechanically rewritten from its inherited
representation into a standalone representation only after strict resolved-array equality
and the unchanged formal checksum were proven. This exception changes source representation
only. The v11 key/version, resolved payload, checksum, published database snapshot and
definitions, gameplay, and balance remain immutable. The previous v11 source representation
remains preserved in Git history. This exception is not precedent for changing gameplay or
balance under an existing Ruleset identity; any future semantic change requires a new unique
Ruleset version and publication.

The configured current identity is in `config/hakoniwa.php`. Verify its source with:

```text
php artisan hakoniwa:ruleset:validate --key=hakoniwa-2s-plus-v11
```

Validation does not publish, migrate, or update a World. A future gameplay/balance change
requires a new unique version, an explicitly registered complete payload, review, immutable
publication, and a separate World migration. Reusing a key succeeds only when the saved
snapshot and all definitions already match exactly; drift fails closed.

See `product/docs/archive/ruleset-history.md` for the Pre-MVP-to-v11 lineage and
`product/docs/architecture/ruleset-configuration-layers.md` for the Core / Balance / Flavor
responsibility map.
