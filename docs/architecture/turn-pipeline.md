# ターン処理パイプライン

> PR #7 note: 下記の推奨フェーズ表は初期設計案であり、実装順の正本ではない。
> 箱庭諸島2＋のsourceから確認した因果順と現在の安全なscaffoldは
> `docs/architecture/turn-runner-scaffold.md`を正本とする。未決定の同時解決規則を
> この初期案から暗黙に採用してはならない。

## 目的

箱庭諸島2＋が持つ共有世界の処理順と、やまにてぃが持つApplication Service・DB transactionの方向性を、再実行可能で監査できる独立設計へ置き換える。

## 実行入口

Schedulerは、next_turn_atを過ぎた稼働中worldごとにturn jobを投入する。Webリクエストはターンを直接進めない。管理者の手動実行も同じApplication Serviceを使い、理由と実行者を監査する。

開始時に次を保証する。

- world_idとturn_numberの一意なturn_runを作る。
- 世界単位の排他ロックを取る。
- ruleset_version、乱数seed、予定時刻、実行元を固定する。
- 既に完了した同一runは再適用せず結果を返す。

## 推奨フェーズ

| 順序 | フェーズ | 主な処理 | 主な出力 |
|---:|---|---|---|
| 1 | Freeze | 対象世界、命令締切、ruleset、seedを固定 | turn context |
| 2 | Validate commands | 所有、費用、対象、重複、期限を再検証 | accepted、rejected commands |
| 3 | Upkeep | 施設維持費、継続効果、期限切れ効果 | 国家資産差分 |
| 4 | Production | 農業、工業、採掘、人口に基づく生産 | 資源・資金差分 |
| 5 | Domestic commands | 整地、建設、研究等の国内命令 | セル・施設差分 |
| 6 | Inter-nation commands | ミサイル、援助、諜報等 | 攻撃・外交イベント |
| 7 | Territory | 国境影響、防壁抵抗、占領判定 | 所有権差分 |
| 8 | Disasters and actors | 災害、怪獣、自然変化 | 被害・移動イベント |
| 9 | Population and consumption | 人口増減、食料消費、飢餓、首都最低保証 | 人口・食料差分 |
| 10 | Aggregate | 国家統計、ランキング用投影、活動判定 | nation summaries |
| 11 | Lifecycle projection | 確定済みstate、保護policy、統計を投影 | nation summaries、state snapshot |
| 12 | Persist and publish | 状態、イベント、outbox、次回時刻を確定 | committed turn |
| 13 | Post-commit | 通知配送、キャッシュ温め、古い履歴整理 | 非同期job |

この順序は暫定である。特に領土を命令前後のどちらで解決するか、人口増加と災害の前後、攻撃と防御施設の同時性はゲーム仕様として決定し、ruleset版とテストで固定する。

## 箱庭諸島2＋との差

旧C版は国境判定を先に行い、国家単位の収支・先頭命令、全セル処理、難民、世界災害、集計を続ける。共有世界という性質は残すが、配列全走査、順位ID再割当、ファイル保存を前提にしない。

やまにてぃは維持・生産、国内計画、他島計画、災害、セル処理、集計という有用な分割を持つ。一方、全島・全セルを一括ロードし、実行seedや多重起動防止を記録しない点は採らない。

## 命令の状態遷移

commandはqueued、accepted、executed、failed、cancelled、expiredを持つ。登録時検証とターン時検証を分ける。登録後に所有権や資金が変わるため、ターン時の再検証が正本となる。

各commandはclient_request_idまたは同等の冪等キー、nation_id、scheduled_turn、type、target map_space/x/y、payload version、priorityを持つ。pixel座標をcommandへ保存しない。実行結果は構造化理由コードを記録し、表示文へ直接結合しない。

1国家1ターンに何件実行するか、順序変更・繰返し・予約を許すかはconfiguration-management.mdとcommands仕様で確定する。

## 乱数

T-01のDecisionとして、`turn_runs.random_seed`にprivateな256-bit master seedを保存し、固定version文字列を含むHMAC-SHA-256で用途labelごとのcounter-based streamを派生する。failedまたはblocked runのretryは同じseedを使い、新しいtarget turnだけが新しいseedを生成する。

bounded integerはrejection sampling、順列はstable inputに対するdeterministic Fisher-Yatesを使う。label間でstateを共有しないため、ある用途のdraw追加は別用途の結果を変えない。exact algorithm、予約label、Nation/cellのstable enumeration、固定test vectorは`docs/architecture/turn-randomness.md`を正本とする。

## transactionと再試行

A-07のDecisionとして、1世界1ターンのゲーム状態、domain event、outbox、`current_turn`を1つのPostgreSQL transactionで確定する。全phase成功時だけcommitし、途中例外ではゲーム状態をすべてrollbackする。`turn_runs`の開始・失敗監査はこのtransaction外へ残せるが、失敗したphaseのゲーム状態は残さない。

transaction内で外部HTTP通信、通知送信、長時間の外部I/Oを行わない。通知配送等はcommit後の別境界とする。phaseごとの所要時間を`turn_runs`へ記録し、実command、全cell処理、災害を追加後にadvisory lock保持時間とtransaction時間を計測する。

世界規模が大きくなり実運用で許容できない時間を超える場合は、次の順で対策する。

1. 対象集合とクエリを最適化し、全セル走査を除く。
2. bulk書込みと読取投影を分離する。
3. 世界を安全に分割できるフェーズだけを並列計算し、確定順を統一する。
4. 最後にcheckpoint型sagaを別ADRとして検討する。

現時点では部分commitやphase checkpointを実装しない。途中commitだけを追加すると、攻撃だけ成功して収支が戻るなどの半端状態を生む。分割を再検討するときはphase_runの入力hash、出力hash、再開条件、公開境界が必要である。

## 外部障害

Discord webhook、メール、分析基盤はturn transaction内で呼ばない。通知内容をoutbox_messagesへ保存してcommitし、別workerが指数backoff、最大試行、dead-letter状態で配送する。通知失敗は管理画面に表示するが、世界を停止しない。

## 国家休眠とターン対象

正式なstateはADR-0014/ADR-0015の`active`、`dormant`、`recovery`、
`abandoned`である。recoveryはRuleset v13でhostile player missile volley完了後に
entryし、84 full Turnを経てT+85開始時にexitする。

専用Lifecycle Jobや実時間判定は使わない。`prepare_turn`で期限到達manual、復帰意思の
あるnon-manual dormant、T+85 recovery exitを先に解決し、今回のstate、Capital、
KARMA、alive monster位置をfreezeする。activeとrecoveryは通常economy、生産・人口を
実行し、recoveryはceasefire actionとmonster侵入だけを除外する。dormantはqueueを保持し、
canonical finance + Ring + capacityで10億円を処理してidle counterを1だけ増やす。

開始snapshotでdormantのCapitalからdistance 2以内はmissile、disaster、territory mutationをno-opとする。monsterは開始cellが範囲内なら即`stayed`、移動先が範囲内なら既存の進入不可candidateと同じくattemptを消費して最大3回の候補抽選を続ける。recoveryは冬保護ではなく通常災害を受ける一方、敵対行動と全領土へのmonster侵入を登録・実行境界で拒否する。counter確定後の`finalize_turn`でidle 360またはcollapseをdormant、dormantの2160をabandoned、missile条件成立Nationをrecoveryへ遷移し、KARMA ledgerを指定順で確定する。自動破棄はmanual abandonmentと同じ単一transaction cleanupを使い、user、nation、Secretary、event、統計を物理deleteしない。

## 観測性

turn_runに各フェーズの所要時間、読取・更新セル数、受理・失敗命令数、イベント数、再試行数を記録する。ログにはseed、ruleset_version、world_id、turn_number、run_idを相関キーとして含める。プレイヤー向けイベントと運用ログは別に保持する。

### PR21 monster actor

PR21では`process_cells`の各cellでmonster actorを人口・facility処理より先に解決し、World単位にbatch loadしたoccupancyとmemory coordinate indexを使う。randomized sequential causalityにより、未処理cellへ移動したactorは同turn内に再行動できる。definition別上限はturn-localで、硬化はtarget turn parityで判定する。新規自然出現は`global_disasters`末尾なので同turnのcell passへ戻らない。

`process_cells` metricsはmonster loaded/actions/moves/trample/defense/max movesとcombat counterを、`global_disasters` metricsはNation eligibility/draw/spawn/no candidate/terrain removalを含む。exact順序とterrain event表は`docs/architecture/monster-system.md`および`product/docs/monster-audit-pr21.md`を正本とする。

## 必須テスト

- 同じ入力とseedの再現性。
- job二重投入時に1回だけ確定すること。
- フェーズ途中例外で状態・event・outboxが全て戻ること。
- 通知失敗でもターンが完了すること。
- 同時国家登録とターン開始の締切境界。
- manual 1/7日のexact resume Turnと期限前非解除、non-manual queue復帰。
- dormant heartbeatのqueue保持、finance/counter、通常economy停止。
- dormant Capital distance 2以内の全mutation保護と範囲外通常処理。
- idle 360の休止開始と2160のcanonical automatic abandonment。
- KARMA開始snapshot、impact内最高1category、monster A/B LaunchIntent分類、ledger確定順。
- hostile missileだけのrecovery entry、84 full Turn、T+85 exit、ceasefire行動表。
- 国境、首都、災害、ミサイルの順序を固定するシナリオ。
- 大量チャンクでの実行時間、lock時間、メモリ上限。
