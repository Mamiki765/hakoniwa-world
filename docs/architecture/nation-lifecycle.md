# Nation lifecycle

現行の正本は`docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md`、
`docs/decisions/ADR-0015-ver-2.4.0-karma-recovery.md`、Ruleset
`hakoniwa-2s-plus-v13`である。ADR-0004の30/180/365日案と旧state名は
historical provenanceであり、runtimeへ使用しない。

## 状態と遷移

| state | 表示 | ver 2.4.0 entry / exit |
|---|---|---|
| `active` | 通常 | 通常処理。idle 360またはcollapseで`dormant`。既存owner操作で`abandoned`。 |
| `dormant` | 放置 | 自動または1〜7日のmanual休止。期限またはqueued non-finance commandで`active`、idle 2160で`abandoned`。 |
| `recovery` | 休戦中：残りNターン | hostile player missileで総人口が100超から100へ低下したvolley完了後に開始。84 full Turn後のT+85開始時に`active`または`dormant`。 |
| `abandoned` | 破棄 | 現在地図・membership・現役assetを終了。復帰せず新Nation登録を使う。 |

state contextは`state_reason`（`idle` / `collapse` / `manual`）、
`state_started_turn`、manual dormantとrecoveryの`resume_at_turn`に保存し、database
constraintで組合せを固定する。

## Official Turn境界

`prepare_turn`はWorld/Nationをlockし、期限到達manual、non-manual dormantのqueued
non-finance command、T+85 recoveryを先に解決する。そのtarget Turnのstate、Capital、
KARMA、alive monster座標を`TurnState`へfreezeし、途中の判定はsnapshotを使用する。

activeとrecoveryはeconomy、resource sale、許可されたcommand、cell
production/populationを実行する。recoveryは自然monster spawn、敵対command、敵対
territory mutationだけを除外する。dormantはqueueを消費せず、
`development_commands`で既存finance pathを使うheartbeatだけを実行する。target Turn
終端でcounter確定後にdormant開始、自動破棄、recovery entryを決めるため、開始stateの
通常処理が同じTurn途中で切り替わらない。

manual休止は`resume_at_turn = C + days * 12 + 1`とし、queued commandがあっても期限前に復帰しない。非manual休止はqueued non-finance commandを復帰意思とみなし、次target Turn開始時にactiveへ戻してcanonical queueを実行する。

## Recovery / 休戦

hostile player missile sequence開始時の総人口が100超で、同sequenceがCapital minimum
100へ減らした場合だけ、current volley完了後のTurn終端でrecoveryへ入る。entry Turnを
Tとして`resume_at_turn = T + 85`、`T+1..T+84`は完全なrecovery Turnである。T+85開始時、
meaningful non-finance queueがあればactive、なければidle 360以上ならdormant、その他は
activeへ戻る。entry/exit自体でidle counterをresetしない。

recoveryを含む敵対player missileのincoming/outgoing、monster dispatch、monument、
敵対territory influence/expansionは登録時と実行時の費用前に拒否する。資金・食料援助、
内政、中立地拡張、生産、売却、災害、owner UIは継続する。entry時は自領のalive monsterを
報酬・経験・kill statなしで除去し、全recovery territoryをspawn/movement/dispatchから
除外する。recoveryは冬保護ではなく、途中でdormantへ遷移しない。

## 休止heartbeatと開始回復

dormant heartbeatはcanonical bounded financeでbase 10億円と装備中Ring bonusを処理し、idle counterを最大1だけ増やす。生産、食料消費、人口変化、queue実行、外向きterritory influenceは行わない。

休止開始時だけ、food合計がRuleset初期値より少なければcanonical food capacity内で不足を補う。farm scale合計0なら、首都distance 2以内のplain/wasteland/scorched/shallow/sea、owner self/null、facility/monsterなしから`distance, y, x`で最小の1 cellをplain + farmへ変える。候補なしは休止自体を失敗させない。heartbeatは農場喪失後にも同じ決定的修復を行う。

## 距離2 protection

開始snapshotでdormantだったCapitalからhex distance 2以内は、missile impact、disaster mutation、monster移動・破壊・spawn/dispatch、territory influence/expandを保護する。missile、disaster、territoryはmutation直前にno-opとし、missileは費用と既に消費したRNGを戻さず指定public logを残す。monsterは開始cellが範囲内なら即座に`stayed`、範囲外から範囲内cellを引いた場合はmonument等と同じ進入不可candidateとして1 attemptを消費し、`candidate_attempts_per_action = 3`の総枠内で残り候補を再抽選する。`monster_dispatch`はdormant Nationもtargetとして選択・再検証できるが、spawn candidateから保護範囲内cellだけを除外する。範囲外のdormant territoryは通常の相互作用を受ける。recoveryにはこのdistance 2冬保護を適用せず、前節の全領土ceasefireとmonster exclusionだけを適用するため、通常災害は継続する。

themeはpresentationだけに適用する。chunk presentationでdormant Capitalを一括loadし、allowlistのterrain/facility/汎用monumentだけを`snow`へ解決する。sea、shallow、oil、Capital、seabed、custom monument、monster、overlayは通常assetのままである。

## Manual API / profile UI

manual APIはauthenticated owner、active Nation、integer 1..7、World lock、Nation row lock、current Ruleset、unresolved TurnRunなしを再検証する。二重申請は409で閉じる。profileの既存「危険な操作」は上からneutralな休止block、既存red破棄blockとし、新しいsettings pageは作らない。

休止中は同じ場所へstate/reason、指定期間、再開予定Turn、残りTurn/日数、自動破棄まで、冬theme、期限前解除不可を表示し、追加申請を隠す。recovery中はowner画面へexact remaining TurnとKARMAを表示し、通常の装備・内政UIを維持する。manual abandonmentはactiveだけに限定し、既存modal・島名確認・red styleを維持する。

## Abandonmentと履歴

manualとautomaticは同じ`NationAbandonmentOperation`を使う。map/monster/queue/resource/sale policy/Capital/membershipを同じtransactionで終了し、Nation、User、Secretary、award、kill stat、message、audit historyは保持する。automatic public messageは`{nation_name}は放置され、忘れ去られる。`である。
