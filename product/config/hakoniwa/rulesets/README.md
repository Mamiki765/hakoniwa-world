# Ruleset authoring

The thin `hakoniwa-2s-plus-v16.php` entrypoint explicitly composes the domain-first current
authoring under `current/`. Historical `roadmap-pr*` snapshots and formal
`hakoniwa-2s-plus-v1.php` through `v15.php` are preserved by Git and retired from the current
tree. Pre-MVP prototypes also live only in repository history. Test fixtures live under
`tests/` and must never be registered or published as gameplay Rulesets.

`config/hakoniwa.php`, tests, and the operator validator load only current v16. There is no
current historical-authoring catalog or migration bootstrap. Historical source bytes,
resolved payloads, checksums, and published database snapshots remain immutable in their
recorded Git/database authority; Markdown summaries do not reproduce them.

The ver 2.4.0 source-dependency rebaseline had one explicit exception: the then-current
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
php artisan hakoniwa:ruleset:validate --key=hakoniwa-2s-plus-v16
```

Validation does not publish, migrate, or update a World. A future gameplay/balance change
requires a new unique version, an explicitly registered complete payload, review, immutable
publication, and a separate World migration. Reusing a key succeeds only when the saved
snapshot and all definitions already match exactly; drift fails closed.

See `product/docs/archive/rulesets/index.md` for the historical index and
`product/docs/architecture/ruleset-authoring.md` for current domain and
behavior/data/flavor authoring rules.
