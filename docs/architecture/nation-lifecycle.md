# Nation lifecycle

現行の正本は`docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`とRuleset `hakoniwa-2s-plus-v12`である。ADR-0004の30/180/365日案と旧state名はhistorical provenanceであり、runtimeへ使用しない。

## 状態と遷移

| state | 表示 | ver 2.4.0 entry / exit |
|---|---|---|
| `active` | 通常 | 通常処理。idle 360またはcollapseで`dormant`。既存owner操作で`abandoned`。 |
| `dormant` | 放置 | 自動または1〜7日のmanual休止。期限またはqueued non-finance commandで`active`、idle 2160で`abandoned`。 |
| `recovery` | 終戦 | 語彙のみ。entryなし、official Turnはfail closed。 |
| `abandoned` | 破棄 | 現在地図・membership・現役assetを終了。復帰せず新Nation登録を使う。 |

state contextは`state_reason`（`idle` / `collapse` / `manual`）、`state_started_turn`、manualだけの`resume_at_turn`に保存し、database constraintで組合せを固定する。

## Official Turn境界

`prepare_turn`はWorld/Nationをlockし、期限到達manualまたはnon-manualのqueued non-finance commandを先に復帰させ、そのtarget Turnの`active`/`dormant`とCapital座標を`TurnState`へfreezeする。途中のmutation判定はこのsnapshotを使用する。

activeだけがeconomy、resource sale、通常command、cell production/population、自然monster spawnを実行する。dormantはqueueを消費せず、`development_commands`で既存finance pathを使うheartbeatだけを実行する。target Turn終端でcounter確定後に休止開始または自動破棄を決めるため、開始stateの処理が同じTurn途中で切り替わらない。

manual休止は`resume_at_turn = C + days * 12 + 1`とし、queued commandがあっても期限前に復帰しない。非manual休止はqueued non-finance commandを復帰意思とみなし、次target Turn開始時にactiveへ戻してcanonical queueを実行する。

## 休止heartbeatと開始回復

dormant heartbeatはcanonical bounded financeでbase 10億円と装備中Ring bonusを処理し、idle counterを最大1だけ増やす。生産、食料消費、人口変化、queue実行、外向きterritory influenceは行わない。

休止開始時だけ、food合計がRuleset初期値より少なければcanonical food capacity内で不足を補う。farm scale合計0なら、首都distance 2以内のplain/wasteland/scorched/shallow/sea、owner self/null、facility/monsterなしから`distance, y, x`で最小の1 cellをplain + farmへ変える。候補なしは休止自体を失敗させない。heartbeatは農場喪失後にも同じ決定的修復を行う。

## 距離2 protection

開始snapshotでdormantだったCapitalからhex distance 2以内は、missile impact、disaster mutation、monster移動・破壊・spawn/dispatch、territory influence/expandをmutation直前にno-opとする。missileは費用と既に消費したRNGを戻さず、指定public logを残す。範囲外のdormant territoryは通常の相互作用を受ける。

themeはpresentationだけに適用する。chunk presentationでdormant Capitalを一括loadし、allowlistのterrain/facility/汎用monumentだけを`snow`へ解決する。sea、shallow、oil、Capital、seabed、custom monument、monster、overlayは通常assetのままである。

## Manual API / profile UI

manual APIはauthenticated owner、active Nation、integer 1..7、World lock、Nation row lock、current Ruleset、unresolved TurnRunなしを再検証する。二重申請は409で閉じる。profileの既存「危険な操作」は上からneutralな休止block、既存red破棄blockとし、新しいsettings pageは作らない。

休止中は同じ場所へstate/reason、指定期間、再開予定Turn、残りTurn/日数、自動破棄まで、冬theme、期限前解除不可を表示し、追加申請を隠す。manual abandonmentはactiveだけに限定し、既存modal・島名確認・red styleを維持する。

## Abandonmentと履歴

manualとautomaticは同じ`NationAbandonmentOperation`を使う。map/monster/queue/resource/sale policy/Capital/membershipを同じtransactionで終了し、Nation、User、Secretary、award、kill stat、message、audit historyは保持する。automatic public messageは`{nation_name}は放置され、忘れ去られる。`である。
