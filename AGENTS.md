# AGENTS.md

## このファイルの目的

`hakoniwa-world`で作業する実装・調査Agentの恒久的な行動原則を定める。本文は日本語を正本とし、識別子・コマンド・パスは英語表記でよい。

ここには「何を守るか」と「いつ止まってOwnerへ聞くか」だけを書く。個別仕様、特定release・PR・過去事故、手順、数値は、code・test・architecture・operations・release文書・Git履歴を正本とする。

---

## 1. Ownerの指示と止まる条件

- Ownerが明示した目的、制約、作業範囲、停止条件を優先し、都合のよい別の意味へ読み替えない。既存testやcommentからOwner intentを逆算し、恒久contractと扱わない。
- Ownerが決定したcontract、終了させた互換性や非目標を、Agent・subagent・reviewerが再解釈したり復活させたりしない。
- 依頼されていない機能、cleanup、再設計、将来対応を足さない。Deferred項目は必要最小限のextension boundaryだけを残す。例外として、依頼範囲の変更で不要・不適切になったtestの置換・削除は依頼範囲に含む（§7）。
- 明示された制約を、同等の仕組みを別の場所・別authorityとして作ることで回避しない（§5）。

次のときは実装を止め、衝突点・選択肢・影響をOwnerへ報告して明示決定を得る。

- Ownerの要求と既存設計・accepted ADRが衝突する、または文書とcurrent code・schemaが矛盾する。
- 依頼範囲に関係する`docs/open-questions.md`のOpen項目がRequired beforeへ到達している。
- 必要な変更が当初の小さな境界を超える。
- Rulesetの世代を`N+1`より増やす必要がある、またはproduction使用済みsnapshotを変える必要がある（§5）。

---

## 2. 権限と作業範囲

- runtime、schema、frontend、testsは原則として`product/`配下で扱う。rootの設定・文書・CIは依頼に直接必要な場合だけ変更する。
- 一つのPRへ無関係な機能、広範なrefactor、別systemの仕様変更を混ぜない。
- Ownerが使用を認めたrepositoryでは、作業branchの作成・commit・push、初回PR作成、PR title/body更新、review comment投稿、review修正の再pushを通常の開発作業として進める。review可能な状態までの反復更新に個別のOwner確認は不要であり、使用可能なForgejo・GitHubで権限を分けない。migration fileを作業branchで作成・commit・pushすることも同様に扱う。
- `release/*` branchは、Ownerが対象releaseの作成を明示したときだけ作る。通常の作業branch作成権限からrelease作成を推定しない。自動同期先でも別途作成せず、指定された開発正本と同期方向を守る。
- mainへの直接commit/push、PR merge、production deploy、production DB操作・migration適用、OCI上のproduction変更、production user dataへの補填・変更はOwnerの明示許可なしに行わない。

---

## 3. 正本と文書

作業開始時は`product/docs/handoffs/current-status.md`と`docs/README.md`を読み、依頼に関係する`docs/open-questions.md`のgate、現行contract、code・schema・migration・testsへ進む。旧統合handoffや全docsを一括で読まない。

- 現行仕様・設計・運用、Owner採用済みの未完作業、未決の構想、完了履歴を分ける。実装済みでも現役の仕様書・runbookはarchiveへ送らない。未処理の監査候補は予定側で保持する。
- historical文書、audit文書、roadmap、future proposalをcurrent authorityとして扱わない。ファイル名や更新日時だけで正本を判断しない。
- `archive/`配下はOwnerの明示指示がある対象だけ閲覧・検索する。古いリンクの追跡、一括読込、Git履歴で間接的に戻さない。
- handoffはOwnerの明示指示なしに編集、再生成、整形、commitしない。

---

## 4. Production dataとmigration

- production dataを削除、reset、または黙って別の意味へ再解釈しない。
- 既存migrationはappend-onlyを原則とする。Ownerがproduction baselineを明示していない限り、既存migrationを変更、削除、統合、rebaselineしない。
- repositoryの状態、application version、schema dump、Git履歴だけをproduction適用状態の証拠にしない。
- 未適用migrationを整理する場合は、Owner確認済みproduction baselineと、fresh install・supported upgradeの検証を根拠にする。
- persisted dataやcanonical identityを変更する場合、既存データの変換、retry、idempotency、auditへの影響を確認する。canonical identityをnullable化・代替identity追加する場合は、そのidentityを読む全経路を横断確認する。
- releaseを跨ぐ未解決Turnを自動retryしない。既存のmanual retry契約とaudit記録を維持する。

詳細なdeploy・backup・restore・migration手順はoperations文書を正本とする。

---

## 5. Ruleset

詳細は`product/docs/architecture/ruleset-authoring.md`を正本とする。分類の要点:

- Behavior: 処理経路、identity、selector、順序、timing、state transition、RNG、解釈方法
- Data: 既存Behaviorへ渡す数値や入力値
- Flavor: gameplayや永続状態を変えない名称、説明、表示文言

次を守る。

- productionで実際に使用されたimmutable snapshotを上書きしない。source名、変数名、`published`表記だけでfreeze状態を推測しない。
- 未release設定の変更可否は、Owner確認済みproduction baselineと現在のrelease境界から判断する。
- release開始時のOwner確認済みproduction Rulesetを`N`とする。semantic changeが不要なら`N`を維持する。新Rulesetは1 releaseで`N+1`の1世代までとし、release中の追加機能・PR・stabilizationは同じ`N+1` draftへ統合する。feature・PR・migration単位で`N+2`以降を作らない。Ownerの明示承認なしに2世代以上増やさない（§1で止まる）。
- Ownerから依頼された実装範囲内で、確認済みproduction baselineとrelease境界に照らしてproduction未使用の同じ`N+1` draftを作成・変更する場合、Ruleset変更自体を理由に追加確認を求めない。§1の他の停止条件と§2の許可境界は守る。変更項目とBehavior・Data・Flavorの分類をPRに書く。
- versioning上の制約を避けるため、Ruleset外に第二のdefinition authority・catalog・設定体系を作らない。UIやtarget contextの分離をversion authorityの分離と解釈しない。

---

## 6. 実装

- 新しいvariantは既存のcanonical runtime pathを再利用し、固有の差分だけを局所化する。種類が違うという理由だけで専用engineやparallel subsystemを作らない。
- 別pathが必要なのは、ordering、RNG、transaction、lock、persistence、eventなど実行契約そのものが異なる場合に限る。
- 同じ責務の判定・definition解決を複数箇所へコピーしない。表示用の整形や見せ方は画面ごとに変えてよい。
- 共通contractが実在するときだけ小さなshared abstractionへ集約し、将来用のgeneric frameworkを先行実装しない。

---

## 7. Testとreview

### 守るもの

故障時の影響に比例して重点を置く。

- player data・資産・進行の破壊や回復不能な不整合
- 二重決算、経済破壊、transaction、retry、idempotency、concurrency、lock
- security、authorization、他人の資産操作
- persistence・migration・installの整合性、productionで使用されたRuleset snapshotの不変性
- 主要なproduction gameplayとoperator経路の停止

### 物差し

- testは、正当なData・Flavorの変更では赤にならず、不正な設定値とBehaviorの故障では落ちるように書く。
- 設定値・表示文言・種類数をtest側へ写しただけの固定一致は作らない。設定を入力として参照し、境界・状態遷移・失敗時の資産と進行の不変条件など、設定から導かれる挙動を検証する。期待値へ実装ロジックを丸写しして同じbugを再現しない。
- 固定値の期待は、その値自体が守る契約である場合だけ許す（DB型・制約、権限・秘匿、永続化形式、外部API契約、明示された性能contract、production使用済みsnapshotの一致など）。根拠をtestまたはPRへ書く。
- UIは意味で要素を選び、ラベルと値の対応・表示配線をfixtureで検証する。実catalogの名称はcanonical resolverを参照する。説明文・レイアウト・DOM構造・class・装飾の変更で赤にしない。順序は仕様である場合だけ見る。
- 写経の固定一致が正当な変更で赤になったら、期待値だけを直さず、設定を入力とする挙動検証へ置き換える。保証自体が不要、または既存testで検出できる場合は削除してよい。同じPRで行ってよい。

### 追加・削除の条件

- testの新設・拡張には、到達可能な操作またはsupported upgrade、具体的な故障、既存testで検出できない理由が要る。不安だけで足さない。まず既存代表の最小限の拡張・置換を検討し、独立した失敗条件だけを別caseにする。同じ故障しか検出しない組合せや全列挙は代表境界に絞る。
- 到達可能性の判断: UIを迂回できる外部入力は、データ破壊・他人への影響・不正な利益につながる場合に扱う。DB直書きだけの状態、unsupported history、理論上の整数限界のためにtestを増やさない。
- 仕様変更で不要になった保証は削除・置換する。件数の増減を品質の根拠や作業目的にしない。

### 実行とCI

- 変更のリスクと影響範囲に必要なtestとstatic checkをfocusedで選ぶ。必要な確認が通ったら終了し、新たな変更・失敗・具体的な懸念がない限り拡大・反復しない。未実施の確認は報告する。
- repository-wide回帰はexact-head CIを最終authorityとし、全件PHPUnitは原則CIへ委譲する。localの補完はmigration、concurrency、環境差、CI failureなど具体的な不足に限る。
- 高コストCIがpushごとに動く環境では、細切れpushや小修正ごとのrepository-wide CI要求を避ける。自動起動しない環境では、push節約のためにreviewの共有・更新を止めない。

### Review

findingは、supported production pathからの到達経路と、上の「守るもの」またはcurrent player・operatorへの具体的な回帰を示す。好み、unsupported history、DBやrequestで拒否される状態だけをP1/P2としない。test追加の要求も同じ条件で示す。

具体的なtest command、suite構成、CI shard構成はComposer設定とtesting文書を正本とする。

---

## 8. Subagent

- boundedで低riskな調査、機械的編集、focused failure調査、文書整合確認、独立regression確認は委譲してよい。利用可能ならLunaを既定に使ってよい。
- architecture、Owner intent、Ruleset・migration境界、production安全、cross-cutting integration、最終review判断はmain agentが保持する。
- production操作・mutation、破壊的DB操作、未決のOwner判断は委譲しない。
- subagentにもこのファイルの制約（Ruleset世代上限を含む）を継承させ、Owner contractを再解釈・拡張・迂回させない。成果は確認してから採用する。

---

## 9. Referencesとencoding

- `_references/`配下はread-onlyとし、編集、整形、rename、削除、commitしない。
- third-party実装を直接翻訳・複製せず、必要な挙動だけを独立して実装する。third-partyの画像・文章・相当量のcodeを、出典・再配布条件を確認せず`product/`へコピーしない。
- 明示的に再配布可能で、出典・配布条件を記録した小規模なUI/theme素材は、frontend release assetとして`product/`へ収録してよい。
- 新規code、文書、DB text、API、user inputはUTF-8を使用する。

---

## 10. このファイルの保守

- 長期間使える原則だけを書く。特定version・release・PR番号・固有機能名・個別bug・一時的な回避策は書かない。
- 事故の教訓は、既存原則への統合、code・constraint・regression testで防ぐ。禁止文を足して防がない。
- 更新は追記ではなく置き換えで行い、同じ原則を複数の節に書かない。
