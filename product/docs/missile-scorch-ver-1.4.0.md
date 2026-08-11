# ver 1.4.0 ミサイル着弾跡（焦土化）

## Release boundary

本変更はver 1.4.0の最後の小規模runtime behavior patchとする。application versionは`1.4.0`のまま維持し、新しいruleset version、ruleset setting、ruleset migrationは追加しない。published ruleset payload、Worldの`ruleset_version_id`、historical TurnRun snapshotも変更しない。

production deployはこのrepository mergeに含めない。deploy時は既存のrelease preflightに従い、次回non-dry TurnRunの`pending`、`running`、`failed`、`blocked`を解消してからrelease境界を越える。releaseをまたぐ自動retryは行わない。

## Runtime behavior

通常ミサイル、PPミサイル、SPPミサイルが有効着弾したcellのterrainが`wasteland`の場合だけ、`scorched`へ変更する。owner、facility、facility state/scale/experience、population、MapCell identityは保持し、terrain stateとMapCell versionだけを更新する。変更したcellのMapChunkをturn-local changed setへ登録し、既存のaggregate phaseでchunk versionを1回更新する。

怪獣occupancyがある場合は、同じ着弾が`MonsterDamageResult.status = killed`を返したときだけ下地を確認する。下地が`wasteland`なら同じimpactの追加結果として`scorched`へ変更する。非致死damage、硬化block、既に解決済みのmonster、別のterrain、ミサイル以外のmonster removalでは焦土化しない。

陸地破壊弾の既存terrain destruction、通常ミサイルがplain/forest等へ与える既存のland impact、水上施設、Capital、dormant/sunken protection、miss/out-of-boundsの挙動は変更しない。本patchが追加するterrain transition predicateだけを`wasteland -> scorched`へ限定する。

## Impact and visibility

実際の`wasteland -> scorched`はmeaningful impactとする。怪獣撃破と焦土化は1着弾の1 impactであり、meaningful impact、idle counter、command resultを二重計上しない。

荒地への直接着弾は既存のpublic `missile.impact`を`land_scorched`として記録する。怪獣撃破時は既存の`monster.killed`と1件の`missile.impact`を維持し、新しいpublic eventを増やさない。焦土化の詳細はpublic safe-metadata allowlistへ追加せず、既存のprivate `missile.launch_detail`だけへ含める。

random seed/stream、phase順、retry semantics、transaction境界、TurnRun snapshot、monster reward、kill statisticsは変更しない。同じseedのfailed attemptをrollback後にretryすると、同じ着弾座標とterrain結果になる。
