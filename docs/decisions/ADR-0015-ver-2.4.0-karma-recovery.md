# ADR-0015 ver 2.4.0 KARMA・休戦

- 状態: 採用
- 日付: 2026-08-23

## 背景

ADR-0014は`recovery`を将来語彙として予約し、Ruleset v12ではentry pathを
持たせなかった。その後のOwner decisionにより、同じapplication version
`2.4.0`の正式gameplay拡張としてKARMA（悪人度）と84 full Turnの休戦を実装する。
application labelは正式な`2.4.0`とする。

公開済みv12 payloadと既存production dataは変更できない。missile、monster、
lifecycle、territory、economy、event、UIの既存transaction/RNG/orderを維持し、
同機能の第二engineや汎用reputation/state-machine frameworkを作らない。

## 決定

1. standalone immutable `hakoniwa-2s-plus-v13`を公開し、exact v12からだけ
   forward migrationする。既存NationのKARMAは0とし、lifecycle/idle、Secretary、
   Item、queue/request provenance、terminal history、audit、live monster/kill stateを
   保存する。
2. KARMAは`-10..100`で、正ほど悪人とする。official Turn開始値をfreezeし、
   犯罪、悪人対象、箱庭連合援助、難民bonusは同じsnapshotで判定する。
   player missileはcanonical meaningful impactごとに最高1categoryだけを加算する。
3. 通常・PP・SPPの対怪獣免罪はLaunchIntent単位とする。Turn開始snapshot Aと
   missile境界snapshot Bのalive monster座標を一度ずつ準備し、legal footprintと
   交差するbooleanを全shotへ固定する。陸地破壊弾とdeliberate SPP self-destruct
   setupは免罪しない。
4. KARMA ledgerはTurn-localに集約し、犯罪加算、100超過制裁、被弾減少、6 Turn
   decay、休戦開始減少、外国領怪獣kill減少の順で一度だけ確定する。制裁は専用
   RNG streamを使い、canonical迎撃/impactを再利用し、同TurnのKARMA・援助・難民・
   休戦判定へfeedbackしない。
5. hostile player missile sequence開始時の対象総人口が100超で、canonical impactにより
   初めてCapital minimum 100へ到達した時点で`recovery`資格をラッチする。同Turnの
   後続人口増加では取り消さず、現在のvolleyは完了させ、state遷移は既存lifecycle
   boundaryまで遅延する。entry TurnをTとして`T+1..T+84`を完全な休戦、`T+85`開始時を
   exitとする。
6. foreign Nationから休戦中Nationまたはその保護領域を明示対象とするplayer missile、
   休戦中Nationからforeign領土を明示対象とするplayer missile、monster dispatch、monument、
   敵対territory influence/expansionを登録時と実行時に費用前拒否する。self-ownedまたは
   neutral座標へのmissile launch、援助、内政、中立地拡張、生産、売却、災害、owner UIは
   継続する。
   許可されたmissileのcanonical impactで`crime_points > 0`が成立した場合はimpact後ただちに
   `active`へexitし、crime 0のanti-monster impactでは継続する。同Turn中に別のhostile
   player missileで再び資格を得た場合はlifecycle boundaryでの再entryを優先する。
   entry時の自領monsterは報酬なしで除去し、spawn/movement/dispatchの対象から全休戦領を
   除外する。
7. `T+85`はmeaningful non-finance queueがあれば`active`、なければidle 360以上で
   `dormant`、それ以外は`active`とする。休戦中は途中でdormantにせず、開始/終了
   だけではidle counterをresetしない。
8. TOP/UIは既存称号等、状態、KARMAの順とする。active badgeは出さず、正KARMAだけ
   nameと`KARMA:n`を赤く表示する。0以下はdetailへexact値だけを表示する。
   public/private/admin audit境界を維持する。

詳細な数値、event、migration、test契約は
`product/docs/ver-2.4.0-karma-recovery.md`を正本とする。

## 延期

Secretary防衛強化、防衛施設強化、地下施設、missile耐性施設その他の防衛側buffは
別のOwner decisionまで実装しない。v13には既存canonical pathへ局所差分を追加できる
境界だけを残す。

## 結果

- v12はhistorical immutable recordとして維持され、normal runtime/fresh installは
  v13を使用する。
- recoveryはdormant冬保護ではない。通常災害とeconomyが継続し、専用schedulerを
  作らずofficial Turn transactionへ統合される。
- retryは既存のsame target Turn / Ruleset / seed境界を維持し、KARMA snapshot、
  monster A/B snapshot、sanction streamを再現できる。
