# ver 1.3.0 怪獣討伐周期seed runbook

## 目的と境界

ver 1.3.0より前から稼働するWorldについて、現在の100 turn区間に限り、operatorが別途確認したNation attributed final blow数を明示登録する。commandは永久累積の`nation_monster_kill_stats`、賞、過去turn eventから値を推測せず、既存値を上書きしない。

migrationは`current_turn > 0`かつ100 turn境界の途中にあるWorldについて、その時点で存在する全Nationを`nation_monster_cycle_seed_requirements`へ固定する。要求行は明示seedが完了するまで未完了のまま残る。migration後に登録されたNationはpre-1.3.0期間を持たないため要求対象ではなく、境界直後のWorldにも要求行を作らない。未完了要求が1件でもあれば、non-dry turnはTurnRunを作る前に失敗し、周期順位の0件defaultも拒否する。

実行前にdatabase backupを取得し、deploy対象SHA、World key、現在turn、Nation DB ID、対象区間、確認済みcount、次target turnのnon-dry TurnRun状態を記録する。`pending`、`running`、`failed`、`blocked`があれば先に解消する。turn処理中は実行しない。

## 実行

たとえばWorld `shared-world`、Nation DB ID 42、現在区間1〜100の確認済みcount 7なら、確認tokenは次になる。

```text
SEED-shared-world-N42-1-100-7
```

container内で全座標を明示する。

```bash
php artisan hakoniwa:awards:seed-monster-cycle \
  --world=shared-world \
  --nation=42 \
  --kills=7 \
  --confirm=SEED-shared-world-N42-1-100-7
```

区間はWorldの`current_turn + 1`からcommandが厳密に計算する。tokenの区間が一致しなければ失敗するため、別区間へ読み替えない。要求行に列挙された全Nationについて、0件も`--kills=0`として明示する。Nationごとに個別実行し、全Nationを一括推測するoptionはない。要求行のないNationや区間へのseedは拒否する。

成功時は`monster_cycle_seeded`、World、Nation ID/name、区間、next target turn、count、`remaining_required_nations`を表示する。最後のNationで0になるまで次のnon-dry turnを開始しない。周期statの`seeded_at`付き作成と要求完了は同じtransactionで行い、DB triggerも対応statのない完了更新を拒否する。applicationは完了時刻だけがある不整合も未完了として扱う。失敗時は非ゼロ終了し、行も要求完了状態も残さない。既に完了済み、seed済み、runtime行ありの場合は重複としてfail closedになり、updateやdeleteで回避しない。

## 検証

実行後は対象行のWorld、Nation、`cycle_start_turn`、`cycle_end_turn`、`kill_count`、`seeded_at`をread-only queryで照合する。さらに同区間の`nation_monster_cycle_seed_requirements.completed_at IS NULL`が0件であることを確認する。要求行は完了後も削除・再完了できない監査履歴として残す。永久種類別討伐countと`nation_awards`が変化していないこと、TOPの怪獣markが永久種類別統計を引き続き表示することを確認する。

誤った確認値を登録した場合、公開後の永続audit stateをoperator判断で直接修正しない。対象、根拠、正しい値、既に境界turnを処理したかを記録し、review済みforward repairを作成する。
