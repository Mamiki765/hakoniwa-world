# Ruleset archive index

This archive is a human-readable index. The complete implementation of every past Ruleset is
preserved by Git, not by these Markdown summaries. The summaries are not intended to restore
or execute a historical Ruleset in the current runtime.

The canonical production Ruleset is currently:

```text
key: hakoniwa-2s-plus-v16
version: 16
checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
source baseline: 1f302fc9b38eb8a6b3f00d2c7ec8712f5eb755c9
```

Use the recorded commit and path when inspecting an earlier implementation. Do not infer a
past payload from the prose summary.

- [Formal v1-v15 history](formal-history.md) records immutable production Rulesets.
- [Roadmap history](roadmap-history.md) records development-stage artifacts that are not a
  formal production version chain.
- [Current authoring architecture](../../architecture/ruleset-authoring.md) explains the v16
  domain and behavior/data/flavor structure.

Historical formal/roadmap PHP, its upgrade catalog, and the obsolete upgrade runtime are
retired from the current tree. Git at the recorded commit/path is the authority for the
complete implementation. Markdown is not a restore source. Historical database rows remain
readable and are not deleted or rewritten by this retirement.
