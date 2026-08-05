# ADR-0008 初回production release境界

- 状態: 採用
- 日付: 2026-08-05
- 対象: PR23、go-live、ruleset、turn cron、認証障害、backup、event保持、moderation

## 文脈

PR23までは公開データのない開発期間であり、Worldをfresh resetして最新版へ揃えることができた。PR23はこの例外を終了し、箱庭諸島２S＋の最初のproduction baselineを作る。

## Go-live境界

次の3条件がすべて成立した時点をgo-liveとする。

1. production Worldを最後にfresh生成した。
2. 一般利用者のNation登録を開放した。
3. 初回の正式turn運用を開始した。

この時点までは開発用Worldと仮データをfresh resetできる。以後はWorld、Nation、cell、command queue、TurnRun、eventをproduction dataとして保持し、破壊・暗黙変換・resetを行わない。schemaまたはgameplay data変更にはforward migrationか、失敗時に停止する明示的変換を付ける。

公開済みruleset payloadは不変とする。新しいrulesetは既存Worldへの影響、runtime support、data conversion、rollback不能境界をPRへ記録する。deploy前に次回non-dry TurnRunのpending、running、failedがないことを確認し、存在すればdeploy前に解消する。releaseを跨いだautomatic retryは行わず、auditを保持する。

## Turn運用

production cronを有効化する。初期版はautomatic retryを行わない。失敗時は非ゼロ終了、application log、TurnRunをoperatorが確認し、same target turn、ruleset、seedの既存manual retryだけを実行する。stale-running自動回収、backoff、retry上限、外部通知は公開後に設計する。

30日休眠Jobは実装しない。TOPの連続資金繰り回数は表示情報であり、Nation stateを遷移させない。

## Authenticationと放棄

provider障害は一時障害と再試行を案内し、既存sessionを通常期限まで維持する。代替loginは事前link済みproviderだけを許可する。email一致統合、identity差替え、operator付替えは作らない。

player/operator向けNation放棄・削除機能は初期版へ入れない。放棄、取消、再認証、cooldown、復帰、削除は別roadmapとする。

## Ruleset承認

単独管理者承認とし、Git、pull request、ruleset validator、CI、merge履歴を承認記録とする。このためだけのapplication公開画面、二者承認、公開audit eventは作らない。in-app publishを将来導入するときにapplication auditを設計する。

## Backupと保持

暗号化off-host PostgreSQL backupを6時間ごとに取得し、日次backupを30日保持する。deploy前に追加取得し、正式公開前1回、その後月1回を目安にrestore rehearsalを行う。初期目標はRPO 6時間以内、RTO 12時間以内とする。continuous WAL、PITR、15分RPOは公開後改善とする。

player turn event、gameplay audit、moderation記録は自動削除しない。application/web server logは30日を目安に保持する。分析専用収集は作らず、event 100万件または性能問題をarchive・集約・削除の再判断gateとする。TOPニュースは保持方針とは独立し、`public` visibilityだけをpaginationする。

## Moderation

違法内容、個人情報、なりすまし、差別・嫌がらせ・脅迫、明らかな荒らしを禁止する。通報とappealは設定可能な外部窓口linkで受け、固定対応期限を設けない。

PR23では禁止行為の方針、設定可能な外部連絡窓口、状態を変更しないoperator-onlyの記録境界だけを用意する。server shell accessをoperator authorization boundaryとするArtisan commandは、事実概要とoperator identifierを必須にし、moderation記録とadmin auditを同じtransactionへ残すが、User、Nation、turn、profile、人口、地形へ変更を加えない。アカウント停止、Nation停止、ターン停止、島沈没、人口被害、強制地形変更、Nation状態変更は公開後の管理・天罰system PRで扱う。完全自動禁止語判定、通報管理画面、期限管理、高度appeal workflowも公開後とする。

## 公開後backlog

正本は`docs/future-systems/post-release-backlog.md`とする。賞、報復、石油stockpile、30日休眠、高度UI、tooltip、追加資源・施設をPR23へ先行実装しない。
