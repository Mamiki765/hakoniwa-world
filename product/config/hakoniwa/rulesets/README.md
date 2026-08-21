# Ruleset authoring

Each PHP file here returns one complete authored snapshot. The `roadmap-pr*` files are
historical published MVP snapshots; `hakoniwa-2s-plus-v1.php` through `v11.php` are formal
published Rulesets. Pre-MVP prototypes live only in repository history, while test fixtures
live under `tests/` and must never be registered or published as gameplay Rulesets.

`config/hakoniwa.php` lists every authoring file explicitly. Do not replace the list with a
glob: order and provenance must stay deterministic and reviewable. Authored bytes, resolved
payloads, checksums, and published database snapshots are immutable. In particular, normally
do not edit, format, comment, rename, or remove any existing roadmap or v1-v11 file.

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
