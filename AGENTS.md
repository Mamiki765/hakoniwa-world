# AGENTS.md

## このファイルの目的

このファイルは、`hakoniwa-world`で作業する実装・調査Agentの恒久的な行動原則を定める。

本文はOwnerが直接確認できる日本語を正本とする。
コード上の識別子、コマンド、ファイルパスなどは必要に応じて英語表記を使用してよい。

個別機能の仕様、特定releaseの事情、過去の事故、具体的なテスト手順はここへ書かない。
それらはcode、test、architecture、operations、release文書、Git履歴を正本とする。

---

## 1. Ownerの指示と解釈

- Ownerが明示した目的、制約、作業範囲、停止条件を優先する。
- Ownerの指示を、実装上都合のよい別の意味へ読み替えない。
- 明示された制約を回避するため、制約対象と実質的に同等の仕組みを別の場所や別authorityとして新設しない。
- Ownerの要求と既存設計が衝突する場合、独自解釈で迂回せず作業を止め、衝突点と選択肢を報告する。
- 依頼されていない機能、cleanup、再設計、将来対応を「ついでに」追加しない。
- Ownerが終了させた互換性や非目標を、Agent判断で復活させない。

---

## 2. 作業範囲

- アプリケーションのruntime、schema、frontend、testsは原則として`product/`配下で扱う。
- rootの設定・文書・CIは、依頼された作業に直接必要な場合だけ変更する。
- 一つのPRへ無関係な機能、広範なrefactor、別systemの仕様変更を混ぜない。
- 必要な変更が当初の小さな境界を超える場合、実装を続ける前にOwnerへ報告する。
- Ownerが使用を認めたrepositoryでは、作業branchの作成・commit・push、初回PR作成、PR title/body更新、review comment投稿、review修正のcommit・再pushを通常の開発作業として進める。review可能な状態までの反復更新に個別のOwner確認は不要であり、使用可能なForgejo・GitHubで権限を分けない。migration fileを作業branchで作成・commit・pushすることも同様に扱う。
- mainへの直接commit/push、PR merge、production deploy、production DB操作・migration適用、OCI上のproduction変更、production user dataへの補填・変更はOwnerの明示許可なしに行わない。

---

## 3. 正本と文書

作業開始時は、依頼内容に必要な範囲で次を確認する。

- `product/docs/handoffs/current-status.md`（存在する場合は先に読み、記載された適用範囲では旧統合handoffより優先する）
- `product/docs/handoffs/development-history-and-current-handoff.md`
- `docs/README.md`
- `docs/open-questions.md`
- 対象機能のcurrent architecture、ADR、operations、Ruleset文書
- 現在のcode、schema、migration、tests

次の扱いを守る。

- handoffはOwnerの明示指示なしに編集、再生成、整形、commitしない。
- historical文書、audit文書、roadmap、future proposalをcurrent authorityとして扱わない。
- ファイル名や更新日時だけで正本かどうかを判断しない。
- 文書とcurrent code・schema・accepted ADRが矛盾する場合、黙って片方へ合わせず矛盾を報告する。
- 詳細仕様をAGENTS.mdへ複製しない。

---

## 4. Production dataとmigration

- production dataを削除、reset、または黙って別の意味へ再解釈しない。
- 既存migrationはappend-onlyを原則とする。
- Ownerがproduction baselineを明示していない限り、既存migrationを変更、削除、統合、rebaselineしない。
- repositoryの状態、application version、schema dump、Git履歴だけをproduction適用状態の証拠にしない。
- 未適用migrationを整理する場合は、Owner確認済みproduction baselineと、fresh install・supported upgradeの検証を根拠にする。
- persisted dataやcanonical identityを変更する場合、既存データの変換、retry、idempotency、auditへの影響を確認する。
- releaseを跨ぐ未解決Turnを自動retryしない。既存のmanual retry契約とaudit記録を維持する。
- production操作をsubagentへ委譲しない。

詳細なdeploy・backup・restore・migration手順はoperations文書を正本とする。

---

## 5. Version管理された設定とRuleset

詳細なRuleset authoringは
`product/docs/architecture/ruleset-authoring.md`
を正本とする。

分類の要点だけを次のように扱う。

- Behavior: 処理経路、identity、selector、順序、timing、state transition、RNG、解釈方法
- Data: 既存Behaviorへ渡す数値や入力値
- Flavor: gameplayや永続状態を変えない名称、説明、表示文言

運用上は次を守る。

- productionで実際に使用されたimmutable snapshotを上書きしない。
- source名、変数名、`published`という表記だけでfreeze状態を推測しない。
- 未release設定の変更可否は、Owner確認済みproduction baselineと現在のrelease境界から判断する。
- release開始時にOwner確認済みproduction Rulesetを`N`とする。semantic changeが不要なら、そのreleaseは`N`を維持する。
- 1 releaseで導入してよい新Rulesetは原則`N+1`の1世代までとする。Ownerの明示承認なしに2世代以上増やさない。
- release未完了中の追加機能、追加PR、stabilizationは同じ`N+1` draftへ統合し、feature、PR、migration単位で`N+2`以降を作らない。
- 2世代以上が必要だと判断した場合は実装を止め、理由と選択肢をOwnerへ報告して明示決定を得る。
- Ruleset version budgetはsubagentにも継承し、subagent判断で追加世代を作らせない。
- versioning上の制約を避けるため、Ruleset外に第二のdefinition authority、第二のcatalog、第二の設定体系を作らない。
- UIやtarget contextを分離する必要があっても、version authorityまで自動的に分離したと解釈しない。
- 判断が明確でない場合は、実装前にOwnerへ確認する。

特定のRuleset version、特定release、特定PRの事情はAGENTS.mdへ記載しない。

---

## 6. Design gate

- 実装前に、依頼範囲に関係する`docs/open-questions.md`の項目を確認する。
- Required beforeへ到達したOpen項目を、Agentが暗黙に決定したり迂回実装したりしない。
- Open項目に該当する場合、実装を止め、選択肢と影響をOwnerへ提示する。
- Deferred項目は早期実装せず、必要最小限のextension boundaryだけを残す。
- Ownerが既に決定したcontractをsubagentやreviewerが再解釈しない。

---

## 7. 実装の再利用

- 新しいvariantを実装するときは、まず既存のcanonical runtime pathを確認する。
- 既存pathを再利用し、固有の差分だけを局所化する。
- Item、怪獣、command、facilityなどが異なるという理由だけで、専用execution engineやparallel subsystemを作らない。
- 別pathが必要なのは、ordering、RNG、transaction、lock、persistence、eventなど実行契約そのものが異なる場合に限る。
- 小さな差分を理由に、将来用のgeneric frameworkを先行実装しない。
- 同じ責務の判定・definition解決・projectionを複数箇所へコピーしない。
- 共通contractが実在するときだけ、小さく明確なshared abstractionへ集約する。
- canonical identityをnullable化したり代替identityを追加したりする場合、局所的なschema変更として扱わず、そのidentityを読む全経路を横断確認する。

---

## 8. Testとreview

Testとreviewは、故障時の影響に比例して重点を置く。特に次を優先する。

- player data、資産、進行の破壊や回復不能な不整合
- 二重決算、経済破壊、transaction、retry、idempotency、concurrency、lock
- security、authorization、他人の資産操作
- persistence・migration・installの整合性
- 主要なproduction gameplayとoperator経路の停止
- 過去に実際に発生した重大なregression

次を守る。

- 要求された意味と具体的な故障影響を検証する。既存testやcommentからOwner intentを逆算せず、恒久contractと扱わない。偶然の要素数、DOM構造、class順、内部名、catalog全件を独自に固定しない。
- testの新設・拡張には、到達可能な操作またはsupported upgrade、具体的な故障、既存の代表確認で検出できない理由が必要である。不安や「念のため」だけで必要性を広げない。新機能も同じ基準で判断し、過去事故の発生は必須条件にしない。
- その不足がなければtestを追加しない。不足がある場合は既存代表の最小限の拡張・置換を優先し、異なる責務または独立した失敗条件がある場合だけ別caseにする。
- この基準はfile・methodだけでなく、provider、loop、assertion、別layerでの再検証にも適用する。同じ故障しか検出しない組合せやvariantを増やさない。
- UIを迂回できる外部入力の認可・安全性、正常操作の境界値、supported upgradeの既存データを、到達不能な異常と混同しない。個々の追加には上記基準を適用し、DB直書きでのみ作る到達不能状態、unsupported history、理論上の整数限界だけのためにtestを増やさない。
- データ・資産・進行の破壊や重要な実regressionを検出する代表には必要なコストを認める。高影響の分野という理由だけで全異常系を必要扱いしない。sourceの横断確認も全経路へのtest追加義務と解釈しない。
- 依頼範囲の仕様変更で不要になった保証は削除・置換する。ゼロベース見直しでは既存保証の全維持を前提にしない。不要な保証を削るためだけの代替testは作らず、件数の維持・減少だけを品質の根拠にしない。
- focusedから変更の影響に必要なtestとstatic checkを選ぶ。必要な確認が通ったら終了し、新たな変更・失敗・具体的な未解消懸念がない限り拡大・反復しない。未実施の確認は報告する。
- push/PRの権限とCIコスト管理を分離する。pushごとに高コストCIが動く環境では無意味な細切れpushを避け、小修正ごとにrepository-wide CIを機械的に再要求しない。Owner管理のForgejoなど高コストCIが自動起動しない環境では、push回数を節約するためにreview用の共有・更新を止めない。
- repository-wide PHPUnitは、exact-head CIが同じtest identifier集合を全件実行する場合、原則としてCIへ委譲する。
- localでCIを補う確認は、migration、concurrency、環境差、CI failureなどの具体的な不足に限定する。一時調査を恒久testへ残す必要性は別に判断する。
- source、dependency、test設定が変わっていない場合、CIと同じ全PHPUnitをlocalで重複実行しない。
- repository-wide回帰はexact-head CIを最終authorityとして確認する。

Review findingは、supported production pathからの到達可能性と、上記の品質基準またはcurrent player・operatorへの具体的な回帰を示す。単なる好み、unsupported history、DBやrequestで拒否される不可能状態だけを理由にP1/P2としない。
test追加を求めるreviewも、到達経路・故障・既存確認の不足を示す。推測だけの不足を理由にtestを増やさない。

具体的なtest command、suite構成、CI shard構成はComposer設定とtesting文書を正本とする。

---

## 9. Subagent

- boundedで低riskな調査、機械的編集、focused failure調査、文書整合確認、独立regression確認はsubagentへ委譲してよい。
- 利用可能な場合、Lunaをこの種の作業の既定subagentとして使用してよい。
- architecture、Owner intent、Ruleset・migration境界、production安全、cross-cutting integration、最終review判断はmain agentが保持する。
- subagentはOwner contractを再解釈、拡張、迂回してはならない。
- main agentはsubagentの成果を確認してから採用する。
- production mutation、破壊的DB操作、未決のOwner判断は委譲しない。

---

## 10. Referencesとencoding

- `_references/`配下はread-onlyとする。
- `_references/`のファイルを編集、整形、rename、削除、commitしない。
- third-party実装を直接翻訳・複製せず、必要な挙動だけを独立して実装する。
- third-partyの画像、文章、相当量のcodeを`product/`へコピーしない。
- 新規code、文書、DB text、API、user inputはUTF-8を使用する。

---

## 11. AGENTS.mdの保守

- AGENTS.mdには、長期間再利用できる行動原則だけを書く。
- 特定version、特定release、PR番号、固有機能名、過去の個別bug、一時的な回避策を追加しない。
- 過去事故の教訓を残す場合、固有名詞や事件経緯ではなく汎用原則へ抽象化する。
- 数値、仕様、手順、コマンド一覧、テスト表は、それぞれのcode・test・architecture・operations文書へ置く。
- 新しい事故が起きるたびにお守り文言を追記しない。既存原則へ統合するか、code・constraint・regression testで防止する。
- 更新時は単純追記ではなく、重複や古い記述を削除して全体を短く保つ。
