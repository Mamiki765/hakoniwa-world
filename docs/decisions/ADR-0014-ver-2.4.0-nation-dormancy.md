# ADR-0014 ver 2.4.0 Nation休止・冬・自動破棄

- 状態: 採用
- 日付: 2026-08-23

> `recovery`を語彙だけに留めた判断は、後続のOwner decision
> `docs/decisions/ADR-0015-ver-2.4.0-karma-recovery.md`により置き換えられた。
> v12休眠契約とそのprovenanceは本ADRに残す。
- 対象: Nation lifecycle、official Turn、首都周辺保護、手動休止、冬表示、破棄
- Supersedes: ADR-0004

## 文脈

30日 / 180日 / 365日の実時間と`dormant_frozen` / `dormant_contestable` / `sunken_archived`を使う旧案は、現行のturn基準idle counterと既存のmanual abandonmentに一致しない。休止を既存のofficial Turn、finance、damage、monster、territory、abandonmentへ統合し、公開済みv11を変更せずproduction dataをforward migrationする必要がある。

## Decision

Nation stateは`active`（通常）、`dormant`（放置）、`recovery`（終戦）、`abandoned`（破棄）の4語彙とする。ver 2.4.0で実装する遷移は`active ↔ dormant`、`dormant → abandoned`、既存の手動`active → abandoned`である。`recovery`は後続PRの語彙だけを予約し、entry pathは作らずofficial Turnでfail closedする。

Ruleset v12は次を固定する。

- 新規Nationの`idle_counter`は2000、休止は360、自動破棄は2160。
- 1日は12 turn、手動休止は1〜7日。`World.current_turn = C`でN日を選ぶと`resume_at_turn = C + N * 12 + 1`であり、`C+1`から`C+N*12`まで実行しない。
- target Turn開始時のstateをfreezeし、自動遷移は同じtarget Turnの終端で確定する。
- 非manual休止はqueued non-finance commandがあればTurn開始時にactiveへ戻す。manual休止は期限前に戻さない。
- dormantはproduction、food/population処理、通常command実行、自然monster spawnを行わない。queueは保持し、canonical finance + Secretary Ring + capacity上限で10億円をcreditし、idle counterを1回だけ増やす。
- 休止開始時にfood合計を初期値まで一度だけ補い、生産能力0なら首都distance 2以内から`distance, y, x`順・乱数なしで最小の緊急農場を1つ作る。
- dormant Capitalからhex distance 2以内はmissile、disaster、monster、territory mutationを無効化する。範囲外は通常契約を維持する。missile、disaster、territoryは最終候補決定後・mutation直前に判定する。monsterは開始cellが範囲内なら即座に`stayed`とし、範囲外から範囲内cellを引いた場合はmonument等と同じ進入不可candidateとして1 attemptを消費して次候補へ進む。3 attemptsがすべて失敗した場合だけ通常の`no_candidate`とする。`monster_dispatch`はdormant Nationもtargetとして選べるが、spawn candidateからこの保護範囲だけを除外する。
- 2160へ到達したheartbeatの終端で、manual abandonmentと同じ内部cleanup operationをsystem actorで実行する。Secretaryと歴史recordは削除しない。

presentationはgame stateを書き換えず、distance 2以内のallowlist対象だけを`snow`同名assetへ解決し、欠落時は通常assetへfallbackする。画像はGit・containerへ含めず`/srv/hakoniwa-assets/tiles/snow`からread-only配信する。

ownerの手動休止は既存profileの「危険な操作」に、破棄より上の独立したneutral blockとして置く。期間selectと期限前解除不可の明示を確認とし、島名入力やred danger styleは使わない。既存の破棄modal、島名完全一致、red danger styleは変更しない。

## Persistenceと互換性

v11はimmutableなhistorical payloadとして保持し、standalone v12をcurrentにする。exact v11だけをsourceとするforward migrationはWorld、queued definition、alive monster、kill statをv12へ対応付け、idle counter、request fingerprint/provenance、Secretary、履歴を保持する。unresolved non-dry TurnRunまたは異なるsourceは部分更新せず拒否する。

## 結果

休止差分はcanonical subsystemへ局所化され、parallel Turn/damage/cleanup engineを増やさない。保護範囲外では共有Worldの通常相互作用が続くため、休止は全領土凍結ではない。Karma、`recovery`の84-turn終戦保護、防壁・Capital operational damageは別Decisionを要する。
