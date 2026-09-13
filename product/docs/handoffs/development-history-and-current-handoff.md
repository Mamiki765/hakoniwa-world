# hakoniwa-world 開発経緯・現行引継ぎ

> 作成日：2026-09-10 JST。対象：`Mamiki765/hakoniwa-world`。
> **2026-09-13追記：旧本文の現在地と矛盾する部分は、直後の「3.9.3 hotfix候補」を最優先し、次にΔ0、Δ1〜Δ10の順で読む。本文反映のcommit・push状態は実際のGit情報で確認する。**
> Ownerの告知原文、実機ログ、MCPの本番観測、固定SHAの実装、過去チャットのレビュー報告を分けて記録する。
> この文書を作成しただけでrepositoryの編集・commit・push・deploy・追加配布を行ったことにはならない。

## 現在地：3.9.3 hotfix候補（2026-09-13 JST）

### Refとrelease境界

| 項目 | 状態 |
|---|---|
| 正本remote | Forgejo `https://git.pbwlove.com/Mamiki765/hakoniwa-world.git` |
| 基準main | `86bf561fd91de24f8b3c199ecf1de0cf201baa60`。作業開始時にfetchした`origin/main`と一致し、3.9.2 merge済みであることを確認 |
| 作業branch | `release/3.9.3` |
| 実装HEAD | `bc281658d2569ff94e9bc83e88ebd951a6e17698` |
| Application / Ruleset | candidate application `3.9.3`。production使用済みv24を変更せず、地上behavior changeを1世代だけ上げた`hakoniwa-2s-plus-v25`へ統合 |
| Underground combat | enemy単体target semantics変更のためcurrent identityを`secretary-underground-alpha-v4`へ更新。v3以前のsnapshotは変更しない |
| 未実施 | merge、production deploy、production DB操作、補填操作、repository-wide PHPUnit |

この節を3.9.3候補のcurrent authorityとして旧節より優先する。3.9.2以前の記録は当時のhistorical evidenceであり、現在の未決事項やrelease状態へ読み替えない。

### 状態語彙

- `OWNER-DECIDED`: Ownerが要求する意味を確定済み。実装済みかどうかとは分ける。
- `OWNER-DEFERRED`: Ownerが明示的に延期した項目だけに使う。方式未決や未実装だけでは該当しない。
- `OPEN`: Owner判断がまだ必要で、延期済みではない。
- `ASSISTANT-PROPOSAL`: Assistant/Codexの提案であり、Owner decisionやcurrent contractではない。
- `IMPLEMENTED`: 現在のcodeへ入った状態を示す。これ自体をOwner decisionの根拠にはしない。

`OPEN = OWNER-DEFERRED`ではない。Ownerが具体方式を決めていない項目を、Assistant判断で「今回は別件」「次versionへ延期済み」と扱わない。

### 3.9.3の判断と実装

| 対象 | 状態 | Current contract |
|---|---|---|
| PT敵単体target | `OWNER-DECIDED` / `IMPLEMENTED` | 生存中の有効な挑発sourceを優先し、なければ生存PT memberからexisting battle RNGで決定的に1人を選ぶ。実際に単体敵対targetが必要なactionだけ一度抽選し、damage・effect・logで共有する。self、AoE、明示target、round-endは通常target抽選を消費しない |
| 新規島候補探索 | `OWNER-DECIDED` / `IMPLEMENTED` | 既存の安定順を保ってbounded batchで全候補を順に評価し、安全なplanが既存World内にある限り拡張しない。固定候補数はgameplay contractにしない |
| 通常怪獣のいる候補 | `OWNER-DECIDED` / `IMPLEMENTED` | 初期島の変更対象cellに非退避怪獣がいる候補だけをskipし、次の候補を試す。通常怪獣を削除せず、明示的に退避可能な怪獣は最終採用planだけ従来どおり処理する |
| 敵AoEと覚醒ゲージ | `OPEN` / 未実装 | 現行party pathは、複数playerが被damageしてもbase target一人へだけ被damage由来ゲージを加算し得る。party AoEの受給者を定めたOwner decisionは確認できず、延期扱いにもしない |
| AoE覚醒ゲージ案 | `ASSISTANT-PROPOSAL` | 実際にdamageまたはbarrier damageを受けた各playerへ、そのenemy actionにつき各人最大1回を推奨する。Owner決定前にcontract化しない |

平地・森の候補化、World縮小・座標/chunk/indexの大規模再設計、generic AI target DSLは3.9.3の`OUT OF SCOPE`であり、今回の実装へ混ぜていない。これは将来の恒久禁止や`OWNER-DEFERRED`を意味しない。

原因は二点だった。party combatはenemy actorのaction種別を決める前に`firstAlivePartyTarget($players)`をbase targetとして固定していた。Nation登録は`CapitalPlacementService::candidates()`の既定上限3件だけを取得し、通常怪獣の拒否も候補選択後のapply段階だったため、4件目以降の安全候補へ継続できなかった。

v25 migrationはexact v24 shared-worldだけをforward upgradeし、queued command definition、alive monster definition、kill statをstable keyでrebindする。terminal command、request provenance、historical monster、ship、Secretary、Secretary Skill、Turn runを保護し、同じWorld mutation lockとtransactionを維持する。Nation作成、船退避、島生成も引き続き同じtransactionにあり、候補skipや失敗でpartial writeを残さない。

### Focused確認

- party combat: `AlphaV1PartyCombatTest` 13 tests / 111 assertions / `0.34s` PASS。同seedのtarget列一致、複数memberへの分散可能性、挑発、死亡source fallback、AoEを挑発者へ縮退しないことを代表確認。
- island continuation: 最初のbounded batchを越えて17件目のsafe candidateを採用するtest 1 test / 4 assertions / `10.15s` PASS。既存Worldを拡張しないことも確認。
- monster candidate: 退避可能怪獣、通常怪獣candidate skip、変更対象外怪獣の3 tests / 15 assertions / `14.585s` PASS。通常怪獣、kill stat、報酬auditを残さないことを確認。
- island failure/expansion: ship退避不能候補、generator失敗rollback、候補枯渇時の1回拡張を含む4 tests / 80 assertions / `65.270s` PASS。
- Ruleset v25 authoring/validation: 14 tests / 81 assertions / `46.596s` PASS。
- exact v22→v23→v24→v25 upgrade: 1 test / 84 assertions / `29.06s` PASS。fresh installとWorld initializationは6 tests / 89 assertions / `26.65s` PASS。
- static/style: PHPStan 430 filesでerrorなし。Pint 667 files PASS。open-question validatorは84 IDsを検証してPASS。`git diff --check` PASS。

repository-wide PHPUnitは小修正ごとに全件を繰り返さない方針に従い未実行。3.9.3 exact HEADのrelease checkpointはpush後のCIへ委譲する。scope内の差分確認で追加のP0/P1/P2相当は見つかっていない。

## Δ0. 3.9.2整理・テスト再設計（2026-09-12 JST）

### 現在地とref

| 項目 | 状態 |
|---|---|
| 正本remote | Forgejo `https://git.pbwlove.com/Mamiki765/hakoniwa-world.git` |
| 基準main | `e2578829a5090ff71399ddcd7ce1cce12e1eba5a`。作業開始時と2026-09-12の再fetch時に`origin/main`で一致を確認 |
| 作業branch | `release/3.9.2`。Forgejoへpush済み |
| 実装HEAD | `5e2a9238ec8393b65fe569a5724cf02382ee9d3c`（handoff更新commitを除く実装・review追従の先端） |
| Ruleset | production使用済みv23を変更せず、3.9.2のsemantic changeを1世代だけ上げたv24へ統合 |
| Forgejo PR | `https://git.pbwlove.com/Mamiki765/hakoniwa-world/pulls/1` |
| 未実施 | merge、production deploy、production DB操作、補填再実行 |

この節を3.9.2作業の現在地として旧本文より優先する。旧本文の3.9.0時点の状態・事故記録・本番観測はhistorical evidenceとして残すが、3.9.2の未実装一覧へ読み替えない。

### 有限なcommitと完了内容

| commit | 内容 |
|---|---|
| `eb1b6d8` | 低価値testと重複fixtureの削減、Docker buildとfrontend検証の分離、AGENTS.mdの恒久的test/review方針整理 |
| `81560c7` | 貸出固定装備、天断一閃表示、任意回数skip、地底PT AI対象、中央施設の集落上書き、v24 migrationとmanual更新 |
| `e632391` | 新規島の中立自然地形利用、船の安全退避、領土感化の安全面積上限、関連architecture/manual/test |
| `e27d57b` | release checkpointで判明したv24追従漏れfixture・expectationの修正 |
| `5e2a923` | reviewで判明した地底PT AIの条件対象修正、低価値なショップ総数固定の削除、manual節順の修正 |

完了したruntime変更は次のとおり。

- 貸出固定装備は貸出元の主能力を保持し、同IL装備も保持対象にした。borrowed projection cacheをschema 2へ更新した。
- 天断一閃の個別damage/effect行は、各行で実際に作用した対象を表示する。damage式・報酬は変更していない。
- 狩場・宝物庫は任意回数、試練は任意周回数を指定できる。既存`execution_count`、request ID、決算・retry経路を再利用する。
- 地底PT AIのHP条件と挑発条件を、実際のaction target選択へ結び付けた。専用の自由言語や汎用AI基盤は追加していない。
- 中央銀行・中央穀倉は、村・町・都市を初回建設で上書きできる。v24の既存settlement overbuild policyへ統合し、v23は不変とした。
- 新規島は、所有者・施設・人口のない中立の海・浅瀬・荒地・山を予約候補にできる。平地・森は広げず、全首都間の最低hex距離12と予約半径5を維持する。
- 初期島generatorも同じ条件で既存自然地形へ必要範囲だけ上書きし、領土外の中立地や世界外枠を削除しない。
- 初期島生成後に非海面となる船だけを、生成後も航行可能な重複しない空き海へtransaction内で退避する。退避不能候補は採用せず、燃料・漁獲・報酬・技能EXP・KARMAを発生させない。
- 領土感化は取得側の現在陸地面積と既存の地盤沈下上限resolverを使い、上限を超える取得をそのセルで拒否する。同一phase内の取得・喪失を逐次反映し、再抽選や既存領土の削除は行わない。
- 現在の安全面積は既存Ruleset値だが、判定側へ固定値を複製していない。resolverは島を受け取る境界のため、将来もし島ごとの上限突破を採用しても領土感化側の別算式を増やさず拡張できる。島別突破自体は今回実装していない。

### Test再設計と確認証拠

既存testの存在を保持理由にせず、Ownerが要求した意味とproductionでの故障影響から再評価した。偶然の入口総数、内部配列全体、同一invariantの多層重複を削除・縮小した。必要な保証は、データ・決算・権限・主要経路・migration・retry・idempotency・concurrencyと、今回変更したplayer-visible behaviorへ寄せた。

- Secretary Equipmentの該当4 casesは、fixture縮小により`21.259s`から`13.827s`（約35%短縮）。
- 共通guard subsetは、重複を整理して20 tests／`94.44s`から6 tests／`33.94s`（約64%短縮、約60.5秒削減）。
- First Productionの重複確認は2 tests／18 assertions／`12.75s`へ集約した。
- ゲーム側の先行focused確認は65 tests／1,793 assertions／`175.31s`。
- 追加した新規島・船・領土感化domainは27 tests／247 assertions／`135.645s`。v24 contractは12 tests／73 assertions／`47.519s`、migration・fresh install・world initializationは12 tests／164 assertions／`66.089s`。
- frontendは20 files／194 tests／`7.60s`、typecheck・lint・buildはPASS。PHPStanは429 filesでerrorなし。Pint、Ruleset validator、open-question validatorもPASS。
- Docker production image buildはPASS。production build stageは`npm run build`だけを実行し、test・lint・typecheckは独立した検証として残した。
- `5e2a923`のreview追従は、party combat 12 tests／104 assertionsと`App.test.ts` 44 testsをfocusedでPASS確認し、変更PHPのPintと変更TSのESLintもPASSした。

repository-wide PHPUnitは同条件の変更前baselineを取得していないため、suite全体の短縮幅は未確認。以下のshard時間は各workerの個別経過時間であり、その合計や最大値を利用者のwall-clock待ち時間とは扱わない。

`e632391`で116 filesを4 shardに分け、repository-wide PHPUnit 1,033 testsをcheckpoint実行した。shard 1は338 tests／4,554 assertions／`21:02.607`で3 failures、shard 2は258／8,824／`12:12.184`で1 failure、shard 3は188／1,884／`9:26.162`で1 failure、shard 4は249／5,018／`15:42.589`でPASSだった。失敗した5 testsはいずれもv24化後の古いversion・schema expectation、または新しい正規条件と矛盾するfixtureで、runtime defectではなかった。`e27d57b`で修正し、該当filesをfocusedでPASS確認した。

このfixture追従だけを理由にrepository-wide全件を最初から再実行していない。したがって、`e27d57b`のexact HEADについて「全PHPUnit PASS」とは記録しない。細かな修正ごとに全suiteを繰り返さず、focused確認とrelease checkpointを分ける方針を維持する。

### 残作業とOwner境界

- Forgejo PR #1のreview後、merge、production deploy、production DB操作はOwnerの明示指示が必要。
- 今回採用した仕様の実装上のOwner判断残りはない。将来の島別地盤沈下上限突破は今回のscope外であり、採用を確定していない。

## Δ1. 最初に読む現在地

**3.9.0は「まだ狩場3を実装していない候補」ではない。狩場3・宝物庫を含むコードがOCI offline/mainへ入り、本番DBはv23へ移行済み。石油補填と公開配布は倉庫登録まで実行済み。中央施設画像の配置と、サーバーでの手修正の共有状態確認が夜の残作業。**

| 項目 | 確認済みの状態／観測限界 |
|---|---|
| GitHub | Owner報告でアカウント凍結・利用不能が継続。復旧確認なし。GitHubのfetch・push・PR・Actionsを前提に進めない |
| 共有経路 | OCI bare repositoryと`oci-offline` remote、`offline/*` refs、read-only Hakoniwa MCP |
| `offline/main` | `539894d6896d1d40e48fbaaadb6576f7e76dec4b`。今回MCPで再確認 |
| `offline/release/3.9.0` | 同じ`539894d6896d1d40e48fbaaadb6576f7e76dec4b`。今回MCPで再確認 |
| merge工程 | 上記OCI refs間の差分なし。追加mergeやGitHub側への同期を未完了必須作業にしない |
| 本番checkout | MCP snapshotでは`539894d6896d1d40e48fbaaadb6576f7e76dec4b`。作業ツリーの2行修正はこのSHAに含まれない可能性がある |
| 本番観測日時 | snapshot生成 **2026-09-10 13:32:30 JST**（`2026-09-10T04:32:30Z`） |
| World／Turn | `shared-world`／**427**。snapshot時点で未解決Turnなし |
| 本番Ruleset | **`hakoniwa-2s-plus-v23`、ruleset_version_id=38** |
| migration | **pending_count=0 / current** |
| health | **Web healthy / DB healthy** |
| 稼働image | `sha256:b5f1b0b47bf27889dfdfe6268331b581d046148a49a62238be0758051b3c0269` |
| 本番のSHA binding | `deployed_sha=null`、`application_version=null`、deployment statusは`unknown`。checkout SHAと稼働imageの厳密一致を確認済みとは言わない |
| 実装のversion | 上記固定SHAの`product/config/hakoniwa.php`はapplication **3.9.0**／current Ruleset **v23** |
| 配布 | OwnerのSQL出力で**29 grantの登録済み**を確認。詳しくはΔ3。まだ「配布コマンドをこれから実行」と案内しない |
| 中央施設の画像 | **現時点で画像なしはOwner承知の仕様。夜に追加予定**。実装未完やDB破損扱いにしない |

旧handoff冒頭にある「production 3.7.3 / v21」「offline/main=368eadf」「3.8.0候補」「第三狩場は未実装」は、この時点の現在地ではない。過去の証拠として残すが、その版へreset・再deployしない。

## Δ2. 本番更新とサーバー手修正の経緯

### 更新時の停止はbuild段階だった

OwnerはOCI Ubuntu／Docker Compose環境から、`oci-offline`の`offline/main`を取り込む更新手順を実行した。GitHubは使用していない。

最初の更新は、`docker compose build hakoniwa-web`内のVitestで止まった。失敗箇所は`product/resources/js/App.test.ts`の既存2箇所で、地下入口ボタンが狩場・試練・宝物庫の3個になったのに、期待値が2個のままだった。

この最初の失敗ログには`stop web`／`migrate`工程が出ておらず、`ERROR: build failed. Production web was not stopped.`で終了した。その実行については、本番Web停止・migration実行によるDB変更は起きていない。

### OwnerがOCI checkoutで直した2行

対象：`/home/ubuntu/apps/hakoniwa-world/product/resources/js/App.test.ts`

```diff
- expect(wrapper.findAll('.underground-entries button')).toHaveLength(2);
+ expect(wrapper.findAll('.underground-entries button')).toHaveLength(3);

- expect(adventureButtons).toHaveLength(2);
+ expect(adventureButtons).toHaveLength(3);
```

OwnerのPython実行で置換成功。`git diff`で差分表示を確認した。ホストでの`npm test`は`vitest: not found`だったため、ホストへ依存を追加せずCompose build内で確認する案内に切り替えた。

**この2行のcommit・offline push完了ログは、このチャットにはない。** MCPで読める共有refsはまだ`539894d`。夜はサーバーの`git status`／該当`git diff`を読み、手修正を消さずに共有へ取り込む。新しいbuild成功ログ全文・deploy全工程ログは未提示だが、その後に補填コマンドが実行でき、最新snapshotでv23／pending 0／新image／healthyを確認している。

当初のdeployスクリプトを「migration失敗後も新imageを必ず起こせば安全」という一般runbookへ昇格させない。今回確認できた最初の失敗はbuild時であり、DB migration失敗時の挙動を実証したものではない。

### レビュー・検証証拠の扱い

前チャットでの5件の指摘は、宝物庫skipの鍵数上限、3.8.1→3.9のforward-upgrade保証、貴族の装備drop率、宝物庫base G、財宝Gの二重表示。`43bee1b`までに修正確認したというレビュー記録がある。`539894d`は地下鍵列の既存期待値追従で、application／migrationを変えない差分だった。

最終4-shardはWindows再起動で中断した経緯があり、`539894d`全4-shard完了を示すログはこの会話では未提示。直前のshard 1／2／4はPASS、shard 3の鍵列期待値1件はfocused PASSというOwner提示報告。MCPの同SHA CI evidenceは今回も`UNKNOWN / record_unavailable_or_invalid`。これらをexact-SHA全項目PASSへ読み替えない。

この引継ぎではテスト変更一覧・件数を再掲しない。確認不足を理由にserial fullを自動再実行したり、GitHub Actionsを起動したりしない。追加確認は影響範囲とOwner判断で決める。

## Δ3. 石油補填・配布倉庫――登録済み、再配布禁止

### 対象incidentは石油

**今回処理したのは、石油在庫上限超過時の売却漏れである。船・漁獲・`ship.moved`・3.5.2の魚overflowとは別件。古いhandoffの漁船incidentへすり替えない。**

3.8.1の`enforceCapacities()`では、stockpile以外の方針で上限を超えた在庫について、容量処理時の売却を試さず破棄するケースがあった。3.9.0では売却可能な資源なら売却方針にかかわらず超過分の売却を試み、資金の収容上限を守る。

Ownerは12:42 JST以降に本番auditを再集計した。採用した基準は、同ターンの資金使用量（escrow込み）と資金上限を用い、「本来売却できた石油分だけ」を補填するもの。石油1万バレル＝2億円。

```text
money_headroom = max(0, money_capacity - money_in_use)
oil_that_should_have_sold = min(discarded_oil, floor(money_headroom / 2))
compensation_money = oil_that_should_have_sold * 2
```

これはOwnerが提示・採用した本番SQL結果の記録。ここで全auditを独立再取得した、あるいはSQLの省略部分まで確認したという意味ではない。

| Turn | nation_id | 島 | 破棄石油（万バレル） | 当時の資金空き（億円） | 採用補填（億円） |
|---:|---:|---|---:|---:|---:|
| 408 | 1 | ナム孤島 | 232 | 9,919 | 464 |
| 410 | 24 | アペイロン島 | 498 | 11,432 | 996 |
| 411 | 24 | アペイロン島 | 498 | 11,509 | 996 |
| 412 | 24 | アペイロン島 | 498 | 11,584 | 996 |
| 413 | 1 | ナム孤島 | 2,045 | 10,319 | 4,090 |
| 413 | 24 | アペイロン島 | 498 | 11,655 | 996 |
| 414 | 24 | アペイロン島 | 498 | 11,724 | 996 |
| 416 | 1 | ナム孤島 | 324 | 13,119 | 648 |
| 418 | 1 | ナム孤島 | 1,467 | 13,120 | 2,934 |
| 420 | 1 | ナム孤島 | 1,363 | 13,319 | 2,726 |
| 426 | 24 | アペイロン島 | 392 | 0 | 0 |
| 427 | 24 | アペイロン島 | 498 | 0 | 0 |

採用額は**ナム孤島10,862億円、アペイロン島4,980億円、合計15,842億円**。前回のナム8,136億円からTurn 420分2,726億円が増えている。426・427のアペイロンは当時の資金上限が満杯だったため、今回基準では追加0円。

### 実行済みのgrant

| grant ID | 対象 | 資産・量 | 固定grant key |
|---:|---|---|---|
| 1 | ナム孤島（N1） | `money=10862` | `oil-overflow-compensation-through-turn-427-n1` |
| 2 | ナム孤島（N1） | `skip_ticket=1000` | `v3.9.0-skip-ticket-1000-n1` |
| 3 | ナム孤島（N1） | `paradox=100` | `v3.9.0-paradox-100-n1` |
| 4 | アペイロン島（N24） | `money=4980` | `oil-overflow-compensation-through-turn-427-n24` |
| 5〜29 | N2〜N26の各島 | 各`paradox=100` | `v3.9.0-paradox-100-n{nation_id}` |

配布時にowner membershipを持つ26島すべてへ100Pd、これとは別に石油補填2件とナムのチケット1件。**合計29 grant**。全島分のPd合計は2,600Pd。

Ownerの最終SQLでは全29件が`pending`、各`claimed_amount=0`。これはその照会時点の状態で、後の受取状況までは確認していない。**「倉庫登録済み」と「プレイヤーの手持ちへ受取済み」は区別する。**

最初の全島配布は、パイプのwhile内で`docker compose exec`が標準入力を消費した可能性が高く、N1で止まった。内側へ`</dev/null`を追加して再実行し、N1は`already_exists`、N2〜N26は`created`となった。DB一覧で26島分を確認したため、配布漏れは解消済み。Ownerの「自分にしか配っていない管理人みたい」という発言は、この初回配布ミスのことだった。

再実行しなくてよい。固有keyを新しくして同じ金額を配り直さない。同じkeyでも対象・operator・reason・資産が違えば衝突するため、理由文を整えて無条件に再送もしない。追加損失が判明した場合は、427までの登録済み範囲と分離して差額だけを別途承認する。

## Δ4. 3.8.1を飛ばして古い状態へ戻さないための補足

旧repository handoffは3.8.0候補で止まっているため、3.8.1の到達点も短く保持する。基準SHAは`8de7774f72c2977d8fc0a9f7126f864d2d144919`。

3.8.1では秘書Owner用「設定」タブへ名前・愛称・画像6枠・立ち絵優先を集約し、表示名優先順位を統一。旧main画像UIを廃止、full_body未登録時の旧画像移行とbust fallbackに対応した。旧画像creditのNULLは勝手に補完せず保持する。

PT設定の収納・貸出toggle化、スマホPT立ち絵4列とHP／MP／覚醒bar・画像ⓘ、狩場／試練の区分、まとめskipと50%／100%操作、HexMap tooltip形式の変更も3.8.1側の到達点。船舶運用EXPの100・200・300…と、貸出参加10回の端数日跨ぎ維持、Ruleset v22を含む。これらを3.9.0の未実装や再実装TODOに戻さない。

## Δ5. 3.9.0の実装到達点

以下は主に固定SHA `539894d` のソース確認に基づく。告知文の表現と異なる点はΔ6へ分離した。

### 地上：輝石・日次報酬・高速建設

輝石の単位は**Pd（Paradox、ペリドットではない）**。ユーザー単位で保持する専用通貨で、地下の「輝石の欠片G」や島間取引資源とは別。他人への売却・譲渡用途はない。「課金石のようなポジション」はOwnerの説明であり、現金購入や課金決済の実装を意味しない。

ログイン報酬は日本時間の日付ごとに1回、**10Pd＋スキップチケット50枚**。デイリーは「開発画面を開く」「地下で10戦する」「コマンドを登録する」の3種で各5Pd。すべて達成すればログイン分込み25Pd／日。地下戦数には通常探索・試練とskipを含み、試練1周skipは10戦分。達成時に自動付与し、トースト通知する。

コマンド一覧に「通常／輝石」タブ、Pd残高・必要量・不足量を追加。高速農場は100億＋20Pd、高速工場は300億＋20Pd、高速採掘場は1,000億＋20Pd。通常の施設の建設・整備で、実行1回ごとに費用が必要。コマンド登録時に即建設する機能ではなく、既存ターン処理内でターン消費なしとして連続処理できる。通常建設に対応する秘書技能EXPも付く。

### 地上：中央銀行・中央穀倉

両方とも平地に建設し、建設・1回の整備は9,999億円、1ターン。初期Lv1、整備ごとLv+1、最大90。同種は1島1個、銀行と穀倉はそれぞれ持てる。

銀行は1Lvにつき資金の基礎上限+1,000億円、穀倉は食料の基礎上限+100,000トン。基礎上限への加算後に既存の秘書・アイテム補正を適用する。利息収入や食料生産を追加する施設ではない。

他島には森に偽装され、初回建設の公開ログも植林扱い。所有者には施設名とLvを表示する。画像参照名は`central-bank.gif`と`central-granary.gif`。Ownerの画像を夜に配置する予定で、画像未配置は既知。

自然発生怪獣のHPは**銀行Lv＋穀倉Lvの合計に対し1Lvあたり+1%**。両方Lv90なら合計+180%。端数HPは確率丸め。中央施設マスへ怪獣は移動しない。自然発生HP補正を派遣怪獣や既存怪獣の一律強化へ拡大解釈しない。

中央施設は周囲の火災・台風に森相当の保護を与える。地震による被害なし。通常／PP／SPPミサイルは無効、地形破壊弾はLv-1。津波-1、隕石-5、巨大隕石は中心-20／距離1-5／距離2-1、噴火は中心-5／周囲-1、地盤沈下-5。Lv0で施設は消失し、マスは中立の浅瀬になる。

### 地下：狩場3「輝きの王国」

試練2の初回クリアで解禁。**非レア14種＝一般12＋強敵2、レア1種**。強敵は近衛の決闘士・宮廷の大魔術師。1〜4人PTに対して1〜4敵、非レア戦は枠ごとに抽選する。ソロでも敵4体に固定する仕様ではない。

レアは**輝衣の宮廷貴族**。先に1%でレア戦を判定し、当たったら敵全枠が貴族。非レアと混ぜない。HP25,000で防御主体、1%の激怒抽選で「無礼者！」の強攻撃。EXP4,200、狩場でのG400。レアだから装備が確定drop・高レア率になるわけではない。

王都／宮廷／近衛の装備シリーズを追加。狩場3の生成ILは**91〜120**、貴族由来は120。既存の能力・売価曲線を120まで延長する。体防具は現在「王都の胸当て」で、精神系ローブとの分離はこのSHAには実装されていない。

狩場3の装備dropはレギュラー約22%、HQ約3%、AF約1%、Relic約0.1%（既存の整数抽選に変換しているため近似値）。通常戦勝利で6.7%、貴族戦勝利で確定1個の「輝きの王国の鍵」。4体の貴族でも勝利報酬の鍵は4個ではなく1個。鍵は既存狩場1・2には追加していない。

### 地下：輝きの王国の宝物庫

試練2初回クリア後の別入口。入場1回につき王国の鍵1個を消費し、敗北・撤退で返却しない。敵は王国と同じ、1%で貴族全枠のレア戦もある。PT時は出現敵からランダム1体を報酬元に選び、敵数分の報酬へ増やさない。

勝利時にHQ以上の装備を確定抽選。HQ／AF／Relicの比率は3:1:0.1（整数化後73.17%／24.39%／2.44%）。基本222G、AF以上なら財宝が確定し20倍の4,440Gになる。「基本Gにさらに20倍分を加算」ではなく、合計が20倍。財宝表示は「財宝を見つけた！ ×20」。宝物庫から鍵を再dropしない。

狩場3と宝物庫はそれぞれの実戦50勝でskipを解禁。宝物庫skipは1回につきチケット1枚＋鍵1個。通常狩場の50勝だけで宝物庫skipが解禁するわけではない。

### 地下：skip・PT・会話

まとめskipは1操作最大1,000回、試練は1,000周。50%／100%は、その回に処理できる上限を基準とする。宝物庫ではチケットと鍵の双方を見て最大数を決める。通信結果不明時は元の内容・回数・request IDを保持して結果を再確認し、別内容のskipを重ねない。

通常探索とskipの狩場選択を分離。PT選択はユーザー別にブラウザへ保持する。試練のソロ専用は維持であり、試練PT化は行っていない。

案内人の部屋の「少しお話をする」は、DBの有効・解禁済み話題からランダム1件を選び、1〜3選択肢から返答する形式へ変更した。常時または各試練初回クリアの解禁条件を付けられる。話題の繰り返しあり、回想へ自動登録しない。げんこつは選択肢時／返答後に利用でき、反応12種、繰り返し可能。話題開始・返答・げんこつの秘書別累計は内部記録で、現時点で新たな報酬・実績を付ける仕様ではない。

管理者TOPに話題作成・編集・削除・表示停止・解禁条件の管理画面を追加。Ownerは告知原文で**話題0→7**と記載している。本番話題7件のDB確認は今回行っていないため、これはOwner記述として保持する。ソースのmigration自体は話題文をseedしない。

### 配布倉庫・不具合修正・表示

運営から届いた配布を開発画面のリンクからモーダルで受け取る。何も届いていなければリンクは出さない。資金・小麦・魚・怪獣肉・石油・Pd・スキップチケット・地下の手持ちGに対応。容量制限のある資産は入る分だけ受け取り、残りを倉庫へ保持する。受取済みと通信後の表示更新失敗を分け、再送で二重決済しない。

石油等の容量超過売却漏れを修正。資金の公開概算は10,000億以上を「約1兆円」「約1.3兆円」等へ変更。自島の正確な所持金そのものを丸める変更ではない。他島previewを開く際に画面上部へスクロールする処理も追加。

### Ruleset・migration

Surface Ruleset v22→v23。追加migrationは`product/database/migrations/`の次の4本で、適用済みmigrationを統合・削除しない。

```text
2026_09_09_030000_add_surface_paradox_and_daily_rewards.php
2026_09_09_040000_add_compensation_warehouse.php
2026_09_09_050000_add_guide_conversation_topics.php
2026_09_09_060000_add_shining_kingdom_key_balance.php
```

queued command等の現行definitionをstable keyで移行し、依頼時provenance・完了履歴・船・秘書等を維持するforward-only移行。本番snapshotはpending 0。再度fresh／seed／旧版復元を行う理由にはしない。

## Δ6. 告知原文と実装の照合メモ――勝手に修正しない

Ownerの告知文はΔ9に原文保存した。次の差は、原文を黙って書き換えずに引き継ぐ。

| 箇所 | Owner原文 | 固定SHAで読める実装／区別 |
|---|---|---|
| 中央穀倉の最大容量 | 9,999,999トン | 基礎値は`999900`、Lv90加算は9,000,000なので、補正前は**9,999,900トン**。99トンの差がある |
| 中央施設の「+10%」 | それぞれ上限+10% | 正確にはLvごと銀行+1,000億／穀倉+100,000tの固定加算。現上限へ毎回1.1倍を掛ける複利ではない |
| 王都シリーズIL | IL90〜120 | 新狩場3のdrop範囲は**IL91〜120**。従来範囲も含む装備生成器全体は1〜120 |
| 会話ボタン名 | 「少しお話がしたい」 | 新UI文字列は「**少しお話をする**」。原文の見出しはOwner表現として保存 |
| 会話0→7／酔っ払った案内人 | Owner告知の説明 | 話題数・具体的台詞の本番DB読出しは未実施。Owner記述でありsourceだけの確認ではない |
| 告知の掲載状態 | 原稿提示あり | ゲーム内お知らせへ投稿完了したという証拠は未提示。自動投稿しない |

数値差を見つけたことは、新たなRuleset変更・migration・テスト追加への承認ではない。必要な照合だけOwnerへ提示する。中央施設画像がない件は意図どおりで、夜の作業へ回す。

## Δ7. 夜に行う作業／次のチャットの開始点

1. このMDを古いrepository handoffより先に読む。必要な最新状態はMCPで確認し、GitHub利用再開を前提にしない。
2. サーバー手修正の`App.test.ts`2行が未commit／未共有か確認し、既存作業を消さずに記録・共有する。コード変更を増やす指示ではない。
3. Owner用意の中央銀行・中央穀倉画像を、現行の外部asset配置規約に従って置く。参照ファイル名は`central-bank.gif`／`central-granary.gif`。実際の配置先・公開URLを確認してから操作し、古い冬tileパスを推測流用しない。
4. 本差分を`product/docs/handoffs/development-history-and-current-handoff.md`へ反映する。添付patchは冒頭更新＋優先差分の追加方式。適用前に差分確認し、既に本文が変わっていれば機械適用を中止して内容を統合する。
5. Owner原稿の照合メモを確認し、告知はOwnerが作成・投稿する。ログイン報酬・デイリー・高速建設など、原稿にない実装もΔ5で保持する。

**石油補填・100Pd・ナム1,000チケットは再実行しない。** 受取状況の確認が必要ならread-onlyで見る。旧frontend期待値修正を理由に全テスト・serial・CIを無断で回し直さない。merge／deploy／追加補填も、このMDを読むだけで実行許可が出たとは扱わない。

handoffはOwnerとWeb版ChatGPTが管理し、Codexは通常read-only。今夜その反映をCodexへ任せる場合だけOwnerが個別に明示する。MCP自体はread-onlyなので、ここからremoteへ直接書き込んだとは主張しない。

## Δ8. 保持する将来案（今回の実装完了条件にしない）

試練3は先送り。王城に勇者が多数いる高難度領域というOwnerコンセプトは残すが、現時点で試練3は未実装。

地下G／輝石の欠片を数万から大量に投入できるコンテンツを次の相談候補として保持する。ILやエンチャントとは別軸の装備鍛錬、追加ステータス・攻撃力強化等はアイデアで、方式・倍率・料金・実装versionは未確定。「1,000万Gでリカちゃん像」は採用決定ではない。

体防具の胸当て／精神系ローブ分離、案内人によるエリア解説・箱庭豆知識も将来候補。現在の一般会話や既存装備を勝手に大規模改造しない。

## Δ9. Owner告知原文（投稿完了の記録ではない）

以下は2026-09-10のOwner原文。Δ6の指摘を反映した改稿ではない。告知として何を採用・省略するかはOwnerが決める。

````text
【地上】
○新資源『輝石』を追加しました。
　課金石のようなポジションです。
　単位はPd。ペリドットではなくパラドックス。
　強い施設やコマンドの使用に使うことができる貴重な資源です。
　他人に売ったり渡したりできません。

○『中央銀行』『中央穀倉』を追加しました。
　資金の保有上限を増やす施設です。それぞれ資金、食料の上限が+10%されます。最大90段階で99999億、9999999トンになります(秘書のレベルは乗算でかかります)
　首都に次ぐ最強の施設です。よく考えておきましょう。

    *  建設費はそれぞれ 9,999億円。
    * 初期Lv1、同じ施設への同コマンドで Lv+1、最大 Lv90。
    * 1島につき同種は1個だけ。
    * 中央銀行：1Lvにつき資金上限+1,000億円。
    * 中央穀倉：1Lvにつき食料上限+100,000トン。
    * 他島からは森に偽装される。初回建設時の公開イベントも植林として見える。所有者には「中央銀行 Lv○」「中央穀倉 Lv○」および Lv○/90 を表示。
    * 中央銀行・中央穀倉の合計Lvに応じて、その島へ自然発生する怪獣のHPが1Lvにつき+1%。銀行Lv20＋穀倉Lv30なら+50%。
    * 怪獣は中央施設のマスへ移動できない。
    * 周囲の火災・台風保護判定では森と同等の保護施設として扱う。
    * 地震の被害を受けない。
    * 災害によるLv減少は、津波-1、隕石-5、巨大隕石中心-20／1hex-5／2hex-1、噴火中心-5／周囲-1、地盤沈下-5。
    * Lvが0になると施設消滅、マスは浅瀬になる。
    * 通常ミサイル・PP・SPPは中央施設には無効。
    * 地形破壊弾は命中ごとにLv-1。

画像はないのは仕様です。夜にでも追加します

【地下】
●狩場3「輝きの王国」追加
「14人もここまで来るなんて、あなたたちも暇なんですね」

●王都シリーズ装備追加(IL90〜120)

●新エリア「宝物庫」を追加。
狩場で手に入るアイテム「鍵」を使用すると侵入できます。
ハイクオリティ以上が確定で入手できます。

●「少しお話がしたい」を正式実装(0 → 7)
酔っ払った案内人と話ができます。
むかついたらぶん殴れます。いくらでも。
今は世間話しかしませんが、将来はエリアの解説や箱庭豆知識を教えてくれるかもしれません……


【その他】
●「配布倉庫」の追加
運営から何か特別な配布があるときに使用する倉庫です。

●石油の周りの重篤なバグの修正
上限を超えた石油が売られず破棄されるバグを修正しました。
該当する2島には該当資金が配布倉庫に補填されます。
````

## Δ10. 根拠と、この差分の適用範囲

- **実機ログ**：本チャットにOwnerが貼付したbuild失敗、App.test.ts置換・git diff、石油補填SQL 12行、grant作成結果、最終29行の倉庫一覧。記録時点と後日の実際の受取を区別する。
- **今回のMCP再確認**：`get_status`、`repo_commit(offline/main)`、`repo_commit(offline/release/3.9.0)`、`production_status`、`ci_get_record(539894d...)`。本番snapshotの生成時刻はΔ1。MCP 1.3.0／13 tools／read-only。
- **元handoff**：`539894d...:product/docs/handoffs/development-history-and-current-handoff.md`、blob `04c793ce150f8ce9220bdea33886c05632e0c478`。冒頭の現在地は3.7.3／3.8.0候補のままなので、今回差分で上書きする。旧本文は履歴として保持。
- **ソース根拠**：以下はこの会話内で読み取った固定SHA `539894d6896d1d40e48fbaaadb6576f7e76dec4b` のファイル。新しい全域レビューや本番操作を実行したという意味ではない。

| 根拠ファイル（`product/`以下） | 主に裏付ける内容 |
|---|---|
| `config/hakoniwa.php` | application 3.9.0、Ruleset v23 |
| `app/Application/DailyLoginRewardService.php`、`DailyQuestService.php`、`ParadoxBalanceService.php` | 日次報酬、3クエスト、Pdの単位とユーザー残高 |
| `config/hakoniwa/rulesets/v23/commands-and-production.php`、`facilities.php`、`central-facilities.php`、`monsters-and-military.php` | 5コマンド、施設費用・Lv・容量・偽装・防護・怪獣HP |
| `config/hakoniwa/rulesets/current/economy-and-resources.php:157-158`、`app/Domain/Economy/NationCapacityResolver.php` | 基礎上限と施設加算→既存補正の順序 |
| `app/Application/CompleteTurnEngine.php:929-1069` | 売却可能資源の容量超過売却とaudit |
| `config/underground-alpha-v1.php`、`config/underground-equipment.php` | 狩場3、貴族、宝物庫、drop率、IL、装備名 |
| `app/Application/Underground/UndergroundRuntimeService.php`、`app/Domain/Underground/Combat/AlphaV1CombatModel.php` | 敵編成、鍵、skip、財宝、貴族の激怒行動 |
| `app/Application/Underground/GuideConversationService.php`、`GuideConversationUnlockCatalog.php` | 会話・解禁・げんこつ12種・累計 |
| `app/Application/CompensationWarehouseService.php`、`app/Console/Commands/CreateCompensationGrant.php` | 配布登録、重複判定、上限付き受取 |
| `resources/js/App.vue`、`components/UndergroundPanel.vue`、`components/CommandQueuePanel.vue`、`components/GuideConversationTopicAdmin.vue` | 配布倉庫、日次通知、PT保持、skip再確認、会話管理 |
| `app/Services/AssetManifestResolver.php` | 中央施設のGIF参照名 |
| `app/Support/MoneyFormatter.php`、`resources/js/formatters/money.ts` | 公開概算の兆円表示 |

この差分は古い記録をなかったことにはしない。**最新到達点と履歴を分離して、次のチャットが未merge・未deploy・未実装・未配布の状態へ巻き戻らないための更新**である。

---

> **以下の第0〜8章は2026-09-09版を保持した履歴。** 3.7.3本番／3.8.0候補という現在地・未配布・第三狩場未実装等の記載は、冒頭の2026-09-10差分で更新されている。古いcheckpointへのresetや未実施扱いをしない。個別仕様・過去の判断根拠が必要なときに参照する。

# 0. 読み方と、今回の重要な更新

最初に現在のrefをresolveし、以後のcode・diff・testsは同じ40桁SHAへ固定する。本書の過去SHAへ勝手にresetしない。Owner決定、実装済み、Codex報告、Web独立確認、未決案を区別し、古いcheckpointを現在の状態へ戻さない。

**直近の入口は1.1、1.14〜1.16、2.6、6.8〜6.10、7.4〜7.7、8章。** 3.6.0以前の詳細は必要時だけ[2026-09-05版](archive/development-history-and-current-handoff-2026-09-05.md)を参照する。

3.7.3で廃船対象Ship identityと強制退避の別自国港探索を修正し、本番反映済み。3.8.0候補は地上アイテム売却／怪獣回帰修正、最大4人の非同期PT、貸出と能力同期、スキップチケット、愛称・画像6枠、戦闘表示等を実装している。**「PT未実装」「専用覚醒画像未実装」「チケットの消費用途未定」は現在の候補には当てはまらない。**

案内人の雑談は`690c685…`時点でショップ欄へ表示されるだけで、部屋のボタンはplaceholderだった。**後続9b509680で「少しお話がしたい」へ接続し、押すたびにcatalogから選ぶよう修正済み**。Ownerが許可した会話ガチャであり、日単位固定に戻さない。マニュアル更新・新HEADの検証結果・本番反映とは別に記録する。

3.6.0で不採用とした技巧・会心・魔力昇華・自然成長の大改造は復活させない。地上の産業連鎖、地上用上位レアリティの道具、第三狩場、称号・Maria連携は別の相談候補。案があることを実装scopeへの承認に読み替えない。

# 1. 現在地

## 1.1 GitHub・CI・production

| 項目 | 状態・根拠 |
|---|---|
| production | **3.7.3 / `368eadf919b599f104e1cbc35d3d8604733f5284`**。MCPの保存済み本番snapshotで確認 |
| production観測 | 2026-09-09 10:16:34 JST生成、10:20:38 JST取得。Web/DB healthy、Turn 414、unresolvedなし |
| production Ruleset | `hakoniwa-2s-plus-v21` / ID 36 |
| production migration | pending 0。**3.7.3稼働環境についての値で、3.8.0のmigration適用済みを意味しない** |
| OCI `offline/main` | `368eadf919b599f104e1cbc35d3d8604733f5284` |
| OCI `offline/release/3.8.0` | **`9b509680cc1b5e4abcdd86392d87bb61abe031f1`** |
| 開発branch | `release/3.8.0`。PCの未push差分はMCPだけでは読めない |
| 3.8.0 source review | `690c685…`でPASS、新規P0〜P3なし。さらに`9b509680…`の雑談接続差分4ファイルもP0/P1/P2なし。限界は1.14参照 |
| 3.8.0 full CI | **690c685**について全suite PASSというCodex報告あり。9b509680の全suite成功へ読み替えない。1.15参照 |
| MCP CI evidence | exact SHA要求に対し`UNKNOWN / record_unavailable_or_invalid`。FAILとも独立確認済みPASSとも言わない |
| 最終仕上げ | 雑談接続は9b509680で共有済み。マニュアル変更は未共有、新HEADのfocused検証結果は未取得 |
| deployment gate | 最終仕上げの独立レビューでP0/P1/P2なしならdeployまで進めるOwner承認。完了記録はまだない |
| GitHub | `Mamiki765` suspendの手動審査という引継ぎ。復旧の確認なし |
| 共有経路 | `origin`は保持、第二remote `oci-offline` → `/home/ubuntu/git/hakoniwa-world.git` |

PR #150のmerge `382c838edc5c92c33302b2895eeb394e45cc58ec`、最終HEAD `5a32331ca9da2083c1ada2d353ac6990ee6f9ae0`、Quality `34012796576`成功は3.6.0の履歴。後続3.8.0の検証を代替しない。

productionが更新された後は、実際のdeployed SHA・version・binding・migration結果を追記する。mainを進めたこと、buildが成功したこと、handoffをcommitしたことだけではdeploy完了としない。

## 1.2 直近releaseの履歴

| PR   | 内容                                   | merge / anchor                             |
| ---- | ------------------------------------ | ------------------------------------------ |
| #140 | Surface water ownership限定修復、v20開始    | `cea7f39992c5885317b6102aa42469ea32fcaaba` |
| #141 | Ship persistence / projection、港      | `763a0993e5533eb9318048af925abb9f48206ade` |
| #142 | 船舶建造・任意廃船                            | `c2ed1e66ca50d93119ea4766fc718402dcdc3446` |
| #143 | 航行・燃料・報酬・秘書XP・forced displacement    | `dd76f7fbe0eb0bbcb07420a20c4b62f379d8899f` |
| #144 | Ship-first missile、visibility        | `22203ae9f7b06607bfa6a5a6821bb948e011f634` |
| #146 | 3.5.0 main昇格                         | `c79a5dac056a20609137477e6ac0c112fab4990d` |
| #147 | Owner確認済み未適用3.5.0 migrationの統一       | `146687a5d5f3f2703f405be6532fae562da3bd58` |
| #148 | Hotfix 3.5.1、command定義JSONのarray復旧   | `4e4d9b85524df5b31a874ec5083c9f74be5cedcd` |
| #149 | 3.5.2 UI / UX、漁獲overflow、レビュー修正      | `70871f9e3345ac183e3c2b97b3f499ad672ba6bb` |
| #150 | 3.6.0 Trial 2、選択式覚醒奥義、戦闘UI、release修正 | `382c838edc5c92c33302b2895eeb394e45cc58ec` |
| #152 | 3.6.1 古いIL／Lv固定上限のhotfix | `78e688c1e79a78ae938750629a64352e34f398e0`。当時のmerge確認報告 |
| #153 | 3.7.0 ランク2・ニョワミヤ・回想・UI | `codex/3.7.0`。最終merge SHAは今回未確認 |
| PR番号未確認 | 3.7.1 本番／bare初期化の基点 | `f718f548b0f9958f6488a1fbfd8d206ce76b14de`。実機報告 |
| GitHub外の本番反映 | 3.7.2 秘書ストーリーデータの分離等 | `daa31ca01d929ffa231241021c2bef9ad7bdb14d`。実機deploy報告 |
| GitHub外の本番反映 | 3.7.3 廃船identity・強制退避の全自国港fallback | `368eadf919b599f104e1cbc35d3d8604733f5284`。Owner deploy報告と後続production snapshot確認 |
| OCI release候補 | 3.8.0 バグ修正・PT／貸出・スキップ・画像／表示 | `690c6858125870982ec95578a11d11ccd3525e66`。source review＋full CI報告あり |
| OCI最終仕上げ | 3.8.0 案内人雑談ボタンの接続 | `9b509680cc1b5e4abcdd86392d87bb61abe031f1`。追加source review済み、manualは未反映 |

\#149は`5bad2cd`再レビュー後にhandoff commit `b30fee8`を追加し、同HEADのQuality成功を確認してmergeした経緯がある。当時の未merge記述を現在へ持ち越さない。

## 1.3 現在のidentity

```text
production application_version: 3.7.3
reviewed candidate application_version: 3.8.0
Surface Ruleset: hakoniwa-2s-plus-v21
Underground combat: secretary-underground-alpha-v3
awakening: secretary-underground-awakening-v2
solo presentation_log_version: 2
party presentation_log_version: 3
Trial 1: secretary-underground-trial-01-v2
Trial 2: secretary-underground-trial-02-v1
exploration: secretary-underground-exploration-alpha-v2
shallow_caves: secretary-underground-exploration-alpha-v1
black_crystal_cave: secretary-underground-black-crystal-cave-alpha-v1
exploration drop: secretary-underground-exploration-drop-alpha-v1
shop equipment: secretary-underground-shop-equipment-alpha-v2
generated equipment: secretary-underground-drop-equipment-alpha-v1
```

applicationとSurfaceは1.1の観測値。solo v2とparty v3の表示を区別する。Undergroundの他の継承identityは、作業に必要な場合に同一SHAのconfig・catalog・overrideから確認する。古い保存装備やbattleを現在定義で再生成する許可ではない。

3.6.0ではSurface v20を維持し、**3.7.0でv21へ移行した**。IL90までの装備拡張は既存入力の出力を保つdomain extensionとしてgenerator identityを維持した経緯がある。将来変更時もidentityが不要という一般規則にはしない。

## 1.4 Migrationと本番境界

3.6.0のforward-only migrationは、`product/database/migrations/2026_09_06_000000_add_underground_awakening_technique_selection.php`。nullableな`awakening_technique_key`を追加し、NULLは既存技へfallbackする。既存playerの能力再計算や旧battle／JSONBの書換えは行わない契約を継承する。

3.7.0の追加migrationとservice（Codex checkpoint報告）：

- `product/database/migrations/2026_09_06_010000_add_underground_recollections.php`
- `product/database/migrations/2026_09_06_020000_publish_v21_3_7_0_release.php`
- `product/app/Application/Ver370RulesetUpgrade.php`

**通常のappend-only forward migrationであり、rebaselineではない。** 自然出現`natural_spawn_tier`のDB制約を1～4へ拡張し、exact v20 checksumを検証してv21をpublishする。v20 payloadは不変。queued command、生存怪獣、討伐集計をstable keyで移行し、船のv20 provenance、履歴、request identityは変更しない。実行definitionの移行と、依頼時点のrequest provenanceの保持を区別する。

未解決の次TurnRunがあれば公開前にfail-closed。`down()`はforward-onlyとしてbackup restoreを要求する。fresh DBにはv19・v20・v21を保存し、currentをv21にするとの報告。

migration checkpoint時の成功報告は、fresh baseline 2 tests / 152 assertions、supported-upgrade 2 / 21、3.7.0 focused 18 / 440、および`migrate:fresh`成功。**途中の作業ツリーに対するcheckpointであり、これだけを最終release HEADのCI証明にしない。** 3.7.1→3.7.2はmigration・Ruleset変更なしとの報告。

3.5.0で#147の統一を認めたのは、Ownerがproduction 3.4.0 / v19と旧4本未適用を確認した時点の限定承認。以後の適用済みmigrationを統合・削除する許可ではない。migrationが27秒かかったという会話だけから、原因やrebaselineの必要性を断定しない。

本番操作前にはactual checkout / application / Ruleset / migration ledger / 未解決Turnを別途確認する。cronは未解決Turnを自動retryしない。新コードのmigrationは、実際に新コードを使う実行環境で行う。旧稼働コンテナへ`exec`しただけで新migrationが見えていると仮定しない。backup・manual retry・restoreはcurrent runbookとOwner gateに従う。

### 3.8.0候補のforward migration

`368eadf… → 690c685…`には次の7本がある。最終follow-upの「migration変更なし」は追加差分だけの説明であり、**3.8.0全体がmigration不要という意味ではない**。

- `2026_09_08_000000_preserve_completed_auction_item_history.php`
- `2026_09_08_010000_add_secretary_nickname_and_image_slots.php`
- `2026_09_08_100000_add_underground_party_lending_persistence.php`
- `2026_09_08_110000_add_underground_skip_consumption.php`
- `2026_09_08_120000_add_secretary_lending_build_cache.php`
- `2026_09_09_000000_extend_secretary_lending_build_cache.php`
- `2026_09_09_010000_add_underground_battle_image_references.php`

いずれも`product/database/migrations/`。今回の候補ではschema dumpの作り直しや新Surface Ruleset世代は行っていない。fresh installとsupported 3.7.3 upgradeのテストを持つ。productionへは実際のdeploy時に新コードのmigrationを適用する。

3.7.3への最初の更新時に`config/hakoniwa.php: Permission denied`で起動に失敗し、Ownerが3.7.2へ戻した経緯がある。その後、既存deploy手順をOCI offline mainへ向けた更新で稼働した。**最初の権限不良の根本原因はこのチャットでは確定していない**。3.7.3アプリロジックの欠陥やDB破損と断定しない。既存の動く手順を理由なく巨大化せず、build・権限・image・migration・Web復帰の実確認を行う。

## 1.5 既存Ship契約の要点

- ShipはNation-ownedのWorld actor。Monster、秘書itemとは別。1cellに最大1隻。
- 港建設は条件を満たす中立shallowをlandへ変更し、自国portを置く。港数で船の保有上限は増えない。
- 漁船・観光船・探索船は各最大3隻。spawnできなければ建造費・turnを消費しない。任意廃船は通常commandで返金なし。
- 通常航行はsea。canonical sea擬態facilityとの同居条件を維持し、見た目で判断しない。
- 漁船は航行ごと石油1万バレル→魚7,000t。観光船は石油2万バレル→20億円。探索船は石油1万バレル、直接報酬なし、visibility 3hex。
- randomized process\_cells内、Ship IDごとに一turn最大一回。自国port有無はphase開始時snapshot。最後の港を同turnに失った影響は次turnの通常航行から。
- heading NULLはrandom。指定進路不可時のbounded fallbackを維持する。進路変更自体はturn不要。
- 成功航行は秘書ship\_operationsへ基礎XP+1。既存modifierを適用。準備中の技能へ固有効果を勝手に追加しない。
- forced displacementは通常航行ではなく、燃料・通常報酬・秘書XPを発生させない。
- NPC海賊、船修理、destination pathfinding、船Lv／XP、汎用Ship AIは未実装の別scope。

細かな休眠・復帰、missile、visibility、port候補順はcurrent Ship codeと旧版1.5を必要なときだけ照合する。

## 1.6 漁獲overflow修正

\#149で航行報酬のfood overflowを既存FoodOverflowResolverへ接続した。食料庫満杯時は魚在庫ではなく標準売却収入へ反映され得る。航行距離・燃料・基礎報酬・秘書XP自体を変更したものではない。

## 1.7 既知残件・現在の扱い

| 項目 | 状態／次の扱い |
|---|---|
| 廃船対象のShip identity | 3.7.3で修正・本番反映済み。未解決P2へ戻さない |
| 強制退避の別自国港探索 | 3.7.3で修正・本番反映済み。隣接valid sea優先→既存順序の自国港を順に確認 |
| 交易履歴付き地上アイテムの直接売却 | 3.8.0候補で修正、productionへの反映は1.1に従う。INQの観測限界は1.16参照 |
| ニョワミヤの占有マスへの村発生 | 3.8.0候補で修正。踏み荒らされた施設の全損は正しい |
| 保護セルへ移動しない怪獣が船だけ沈める | 3.8.0候補で修正。保護判定を沈没より先に行う |
| 3.8.0既往レビュー指摘 | `690c685…`までの追加レビューで修正確認。古いR01〜R15や後続4件を無条件で再登録しない |
| 雑談ボタンとcatalogの接続 | **9b509680で修正・共有、追加独立source reviewでP0/P1/P2なし**。1.14・6.8参照 |
| プレイヤーマニュアル3.8.0追従 | 最終仕上げとして依頼済み。Owner指定の内容だけ既存の文体・粒度へ統合 |
| 漁獲overflow損失の実測・補填 | 支払い完了の新報告なし。バグ修正deployと補填を混同しない |
| MCP / Tunnel | 1.3.0、13 tools、4 repo読取allowlist。初期接続工事へ戻さない |
| MCPの3.8.0 CI記録 | UNKNOWN。既存のlocal証拠共有で補う課題であり、それだけで全suite再実行やMCP増築を始めない |
| ランク2・地底ヘッダー・回想 | 既存実装。現在の未実装候補へ戻さない |
| 地上HQ／アーティファクト、上位産業 | アイデア相談。個別仕様・数値・実装scopeは未承認 |
| 第三狩場・試練3・実PT Boss | 将来設計。試練3はかなり先に保留 |
| 素材制作／自前仲間育成／称号・Maria連携 | 別の将来候補。今回releaseの必須残件にしない |

## 1.8 PR #150のレビューと測定（3.6.0の履歴）

初期HEAD `64ddb238`での独立レビューは次を指摘した。

1. 夜宴の血侯のlifestealがplayer条件で無効。
2. 新攻撃奥義4技へ既存敏捷comboが未接続。
3. Git情報不明を明示SHAだけでdirty=falseにしていた。

最終PR body、後続code、更新された報告では修正されている。通常吸収は敵／player共通、修羅の追加だけplayer固有。新技は既存の一行動一combo抽選を使う。Gitを検証できない環境をclean認定しない。追加レビューのapplication 3.6.0表示、通常／敵吸収ログ、served manualのTrial 2 drop説明も後続release修正対象となった。最終HEADのCI成功が現在の検証状態であり、初期失敗を残件扱いしない。

測定の正本は`product/docs/underground-trial2-v1-200-seeds.md`。source `a644447f62bfb3a3749bd57916471c6a58d977b7`、manifest hash `069c03893ac7389e3c71917c2a5dbc332c17c14e06f7d4de174124bc3b66e487`。

- 16scenario各200seed。Lv150/180・SP60・**generated IL50 uncommon（ハイクオリティ）武器／防具／アクセサリ各1部位**。ノービス一式の結果ではない。
- 主比較の戦技clear率は**Lv150 16.0%、Lv180 71.5%**。修正前17.0% / 72.5%と区別する。
- 護身・祝福・自由の代表例は両Lvで200/200clear。ただし有限seed・特定buildの結果であり、全配分の安全性証明ではない。
- default AIでは護身と祝福は新奥義を使わなかった。覚醒優先AIの20seed比較で両技を実使用できることを別途確認した。
- 同条件の天断／修羅200seed比較ではclear率同じ、天断が平均1.01～2.99round短い。修羅を常時上位と証明したものではない。
- 途中のレベルアップと装備変更はsimulationでは固定。実playerの浅層育成・装備更新も攻略手段にする。

ここまでの測定値とレビュー経緯は提供された3.6.0版から保持した履歴。今回のhandoff更新で200seed、全テスト、当時のGitHub CIを再実行・再取得したとの主張ではない。

## 1.9 漁船incidentと補填境界

ナム孤島turn368～371の「船が中立」は、後のtooltipで船主は自国、下の海が中立と確認した経緯がある。船籍DB破損と断定しない。

実際のoverflow損失、修正deploy境界、資金上限、払い済みの有無は本番auditで確認する。正常完了・非dry-run turnのship.moved resource\_requested / applied / overflowを元event IDごとに調べる。建造数×経過turn、現在残る船だけ、過去Turn再実行で補填額を作らない。

当時の売却lotと資金上限まで復元するか、売却相当額の救済にするかは別Owner判断。以前の63億円は例で確定額ではない。燃料・船代・XPを自動的に重ねて返さない。dry-run→承認→既存lock / transaction / capacity / auditで支払い、満額入らない分を無言で破棄しない。

## 1.10 3.6.1：古い固定上限の除去

PR #152は3.6.0後のhotfix。宝物庫まとめ売り`item_level_max`の1～60固定を「1以上」へ変更し、将来のILを検索条件として拒否しない。戦闘シミュレーターのLv／IL1000、story benchmark Lv10000固定も除去し、正整数と計算可能な整数範囲を検証する。Lv1254 scenarioの回帰テストを追加したとの報告。

探索場所・敵drop metadataは、生成器の現在の対応IL上限へ連動させる。検索条件に高いILを指定できることと、生成器がそのILの装備を生成できることは別。確率・列挙値・DB/PHPの表現限界・処理件数による負荷制限・明示されたgameplay上限は維持する。Ruleset／migration変更なしのhotfixとしてmergeした経緯を保持する。

## 1.11 3.7.0：ランク2施設

次はOwner確定値と実装checkpoint報告に基づく。rank専用列や別の施設HPは増やさず、Rulesetと現在の`facility_scale`から導出する。保存単位と画面の人数を混同しない。関連経路は`FacilityRankPolicy`、`FacilityScaleDamageService`、建設executor、災害／missile／表示／出現条件。

| 施設 | ランク1上限 | 上限到達後の整備量 | ランク2最大 | 表示する耐性の要点 |
| --- | ---: | ---: | ---: | --- |
| 大農場 | 50,000人 | ＋1,000人 | 100,000人 | 森3個分の台風耐性 |
| 大工場 | 100,000人 | ＋5,000人 | 200,000人 | ある程度の火事・地震耐性 |
| 大採掘場 | 200,000人 | ＋2,000人 | 400,000人 | 陸地破壊等へ規模損失で耐える |

旧上限ちょうどはランク1。さらに同じ整備を行い、**上限を超えた規模がランク2**。被害で旧上限以下になれば即降格し、耐性・表示・自然出現資格へ同じ判定を使う。例：大農場51,000人へ3,000人の損害なら、48,000人の農場として残り、次の一発からランク1として扱う。

| 破壊の種類 | 大農場 | 大工場 | 大採掘場 |
| --- | ---: | ---: | ---: |
| 荒地・焦土にする通常破壊 | −1,000人 | −5,000人 | 元来対象外の破壊を新たに追加しない |
| 陸地をえぐる破壊 | −3,000人 | −15,000人 | −2,000人 |
| 火災・地震の工場被害 | 既存の対象条件 | −20,000人 | 既存の対象条件 |

広域災害は名前だけで分類せず、実際の地形破壊処理に対応させる。巨大隕石周囲1は陸地破壊、周囲2は通常破壊の扱いが基本だが、山など元来対象外の地形まで壊す仕様にしない。**巨大隕石の中心直撃は耐性無効。怪獣の踏み荒らしは従来どおり**とし、「足を止めてミサイル10発分の施設攻撃」は未採用。

大農場自身の台風判定は`max(0, 6−N)/12`から`max(0, 3−N)/12`へ変わる。抽選成立時の消滅は防がない。近隣の別農場へ本物の森3個として作用させず、伐採・火災防護などへも流用しない。

部分破壊のKARMAは加点対象なら基本＋1、三施設とも陸地破壊は＋3。大採掘場の規模損失を、それに合わせて6,000人へ増やさない。マス詳細はランク1・昇格条件、ランク2名・短い耐性説明を出す。

GIFは後半のPR確認報告で`Land702.gif`、`KLand47.gif`、`land19.gif`への接続とOwnerの外部配置済み報告があった。施設別対応・大文字小文字は現行asset resolverが正本。「3枚未提供」の古いcheckpointへ戻さない。ただし本更新では配信を再確認していない。

## 1.12 3.7.0：ニョワミヤ・零式・怪獣経験値

人口50万人以上の候補は**既存7種＋珍獣ニョワミヤ＋メカいのら零式の均等9枠**。後二種が当たり、ランク2施設がない場合だけ既存7種から一度再抽選する。キング固定へ戻さず、9枠の引き直しや無制限loopにしない。零式は既存の怪獣定義を自然出現候補へ追加するもので、新規MonsterDefinitionが必ず二種増えると決めつけない。

珍獣ニョワミヤは`monsnyowa.gif`、HP1、経験値20、キング相当の残骸。特性「先行移動」「ニョワミヤ」。通常怪獣の先頭へ並べるだけでなく、ミサイル基地等のマップ処理より前に移動する。通常処理で二度動かさず、移動後のoccupancyをmissile・弓・KARMAの適切な境界で観測する。

踏み荒らした場所・討伐後は平地。HPダメージと討伐を、不機嫌な顔／大量のチーズを置いて帰る専用ログで表現する。**チーズ資源は追加しない。** 残骸・怪獣肉の既存保管／分配経路を使う。その後の着弾で平地が焦土になることはある。

経験値は次の区別を維持する。これは前会話のcode調査結果を引き継ぐもので、今回のMCP実読出しによる再検証ではない。

- 発生単位は撃破一回ではなく、damage処理ごとの**実HP減少量×`experience_per_damage`**。無効damage・overkillの余剰は対象にしない。
- 地上／海底基地由来は当該基地EXPへ、地底ミサイル基地／秘書弓由来は秘書の地上「怪獣討伐」EXPへ。二重付与と解釈しない。
- 残骸資金、怪獣肉、kill stats、秘書Item dropは撃破時の別処理。**地底Combat EXPは別系統**。

## 1.13 3.7.1～3.7.2と、過去レビューの扱い

3.7.1の詳細全差分は本チャットに揃っていないため推測で補わない。3.7.2はOwner説明で「秘書ストーリーデータの置き場を分離する簡単なコミット」。検証／deploy報告は1.1のとおり。2.5の回想契約に対し、本文が実際に全文で再利用されているかは、必要時に該当SHAの初回表示・回想表示・storyデータだけを照合する。

PR #153の途中HEADでは、部分被害イベント`facility.partially_damaged`のOwner／公開島ログ接続漏れ、回想のための全試練戦闘履歴取得、現在のgrowth pathで導入回想の過去分岐が変わる点が指摘された。その後も複数の修正・レビューが進んだため、**古いHEADへの指摘を無条件に現在の未解決P2へ再登録しない**。最終的な修正確認を求められたときだけ現行経路を確認し、解消済みとも未解消とも根拠なく断定しない。

## 1.14 3.8.0の独立レビューと、残る最終仕上げ

本チャットでのレビュー履歴は次のとおり。690c685までの結果を継承し、handoff作成中にpushされた9b509680の4ファイルを追加確認した。過去の全source・全suiteを再検査した報告ではない。

| Reviewed HEAD | 比較基点・確認範囲 | 当時の結論 |
|---|---|---|
| `9f2449640f510b57130f8d144c0ef5a5b588b90b` | 3.7.3から95変更ファイル。途中の`f4925b9…`に1ファイルの追加差分を足して確認 | P2 13件、P3 2件。差分外にtestログ保持P3 |
| `7598d0a79d836e94e0cc1eb07647182486728e61` | `9f244964…`から55変更ファイル | 前回修正を確認。新たにP2 2件、P3 2件 |
| `690c6858125870982ec95578a11d11ccd3525e66` | `7598d0a…`から19変更ファイル | **source review PASS、新規P0/P1/P2/P3なし** |
| `9b509680cc1b5e4abcdd86392d87bb61abe031f1` | `690c685…`から4変更ファイル、157 diff行の可読変更部と関連source/test | **追加source review PASS、新規P0/P1/P2なし**。テスト実行・manual反映・deploy確認ではない |

最終4件は、ヒーラー本人を回復判定から除外していた問題、clean環境のevidence親directory未作成、cleanupより先のfinal PASS記録、投影成功件数に依存した貸出cursor。`690c685…`で修正確認済み。

R01〜R15では、戦闘状態の時点、実engineログとUIの接続、actor/target identity、標準ヒーラー、同期済みcache、候補一覧負荷、相互借用lock、画像保持・閲覧設定、無効メンバー解除、再送intent、ソロ大絵反復、スキップ結果、古い表示を扱った。古い指摘の名前だけから修正前のコードへ戻ったと判断しない。

レビューは固定SHAのsource・diff・関連testsが中心。部分redactionがあるファイルは不可視部分まで読んだことにしない。Web ChatGPT自身が本番操作やfull PHPUnit、実ブラウザの全操作を実行したという主張ではない。

**source review PASSと3.8.0の機能要件を全部満たしたことは同義ではない。** 690c685までの案内人会話は、catalog→ショップ欄には接続済みだが、部屋のボタンには未接続だった。この接続漏れを9b509680で修正した。今後も部品・関数が存在することだけで、実際のUI操作まで完成したと推測しない。

最後に依頼した作業は、雑談接続＋Ownerが編集・指定したマニュアル更新。現在の共有HEADは`9b509680…`で、雑談接続部分の独立sourceレビューは済んだ。**この差分にはmanual変更がなく、後続focused検証の結果もMCP記録からは取得できていない。** 既存の実行結果・残りのmanual差分を確認する。さらにcodeが進んだ場合は9b509680からの追加差分だけをレビューする。

**最終仕上げにP0/P1/P2がなければ既存手順でデプロイまで進めるOwner承認あり。** P3やUIの好みは分け、勝手に新しい必須gateを増やさない。雑談の接続済み判定を、manualも完了・新HEADのfull CIも完了・本番反映も完了という判定へ広げない。

`docs/operations/release-3.8.0-review-fix-checkpoint.md`は2026-09-09 08:15時点の`READY_FOR_SOL_VALIDATION`記録で、対象implementationは`785afc14…`。そこにある「full suite未実行」は当時のcheckpointであり、後述する`690c685…`の完了報告を無効にしない。

## 1.15 3.8.0の検証証拠

Codexはexact candidate `690c6858125870982ec95578a11d11ccd3525e66`について次を報告した。

| 検証 | Codex完了報告 |
|---|---|
| focused PHPUnit | 40 tests / 374 assertions PASS |
| related Vitest | 5 tests PASS |
| full PHPUnit | 1,037 tests / 20,345 assertions、failure/error 0 |
| shard | 16/16 PASS |
| local evidence token | `837e965c` |
| final evidence | `run passed / exit 0`、authoritativeなrun行は1行 |
| PHPStan | 408/408、No errors |
| full frontend Vitest | 19 files / 179 tests PASS |
| ESLint / Typecheck / Production build / Pint | PASS |

一方、MCPの`ci_get_record`およびworkspace summaryでは`690c685…`の記録は`UNKNOWN / record_unavailable_or_invalid`。後続`9b509680…`のworkspace summaryも同じUNKNOWN。期待先は`release-evidence/<SHA>/ci-result.json`。**上表はOwnerが共有したCodex報告であり、MCPでraw全証拠まで独立取得した結果ではない。** 要求SHAが応答のtested_sha欄に入っているだけでテスト実行の証明としない。

証拠が必要なら既存runのSHA・終了コード・JUnit・log・dependency情報を共有する。記録がMCPから読めないことだけを理由に1,037件をやり直したり、readerを増築したりしない。

後続9b509680にはPHP/TSの接続処理と、API候補一覧・ボタン連続押下・ショップ非表示のテスト変更がある。テストsourceは読んだが、その実行結果は今回未取得。既に実行していれば結果を共有し、なければ対象のcatalog/projection/frontend接続テストとlint/typecheckを必要範囲で確認する。**新HEAD全体のfull suiteを実行していない場合は、基点full PASS＋後続focused PASSとして正直に記録**し、旧SHAの全件結果を新SHAへ付け替えない。

## 1.16 INQ-000005 / INQ-000006：売却障害の調査と修正

player出品を落札した地上アイテムの直接売却で、終了済み`auction_listings`のFKが個体削除を拒否することをlocal再現した。`SQLSTATE[23001]`、制約`auction_listings_secretary_item_instance_id_foreign`。取得元が交易だったというOwner・報告者の情報が調査の手掛かりとなったが、NPC商品とplayer落札品は区別する。

3.8.0では現存個体FKを`ON DELETE SET NULL`にし、元個体IDを非FKの`original_secretary_item_instance_id`へ保存・backfillする。active player出品では現物必須、終了済み履歴のみNULLを許容。装備中・active escrow・古びた弓・資金上限等の正規拒否は維持する。指定個体のみ削除し、資金／個体／auditは同じtransaction、再送で二重入金しない。

対象限定診断の観測は2026-09-08 13:02〜13:04 JSTの過去snapshot。

- INQ-000005：Nation 19 → Secretary 19。item 61は`inora_bracelet` Lv1・未装備／非escrow・終了済み交易履歴あり。item 81は同品Lv1・slot 2装備中。item 61はFK不具合へ到達し、item 81の装備中拒否は正しい。
- INQ-000006：Nation 13 → Secretary 13。item 35は`elf_bow` Lv2・観測時active escrow。item 118は同品Lv2・未装備／非escrow・終了済み交易履歴あり。前者はescrow拒否、後者はFK不具合へ到達する状態だった。

当時実際にPOSTされた個体ID・HTTP応答・SQLSTATEは保持ログから確定できなかった。**「同名を装備すると別個体まで必ずロックされる」「外したら売れたという報告までFKだけで完全に説明できた」とは断定しない。** SQLSTATEはlocal再現値。診断snapshotは期限付きであり、現在も同じ装備／出品状態とは限らない。

case IDは`INQ-000005-20260908`、`INQ-000005-item-61`、`INQ-000005-item-81`、`INQ-000006-20260908`、`INQ-000006-item-35`、`INQ-000006-item-118`。失効時は必要な対象だけ再exportする。MCPからDB売却・装備変更・補填を行うものではない。

# 2. 地下RPGの継承契約と3.6.0

## 2.1 Trial 1

「地下に眠る古代遺跡」は10連戦。HP carry、1～9戦勝利後max HPの20%回復、MPは毎戦10,000。active run中に宿を使わない。勝利後は次戦へ、敗北／撤退でrun終了。画面更新でも進行を保持する。

初回clearのみSP40・物語・覚醒・地底layer1（4設備枠）。再clearで初回報酬は重複しない。合計800EXP / 205G。**Trial 1は通常装備dropなし、Trial 2はあり**と区別する。

## 2.2 覚醒共通仕様と選択技

gaugeは永続、内部最大1000。default AIは満タンかつHP20%以下で発動を試みる。custom AIでは明示した覚醒ruleを使う。一戦最大一回であり、試練全体で一回ではない。activationそのものは通常actionを消費せず、HP／MP全回復・装備込み五能力+30%を戦闘終了まで適用する。

3.6.0では各growth pathの既存技／追加技を戦闘間に一つ選ぶ。選択NULLの既存playerは既存技。再振り時はNULLへ戻す。取得に追加SP・新skill node・Trial 2clearは要求しない。覚醒本体は通常のdispellable buffではない。

| 系統 | 既存技                      | 追加技の採用内容                                                                               |
| -- | ------------------------ | -------------------------------------------------------------------------------------- |
| 戦技 | 天断一閃                     | 修羅の血脈：potency160%の物理初撃、武力80%／技巧20%／weapon100%。初撃含む3round、実HP damage15%吸収、既存吸収との合計上限25% |
| 護身 | 絶対護界：2round直接damage90%軽減 | 城塞撃：potency180%、生命75%／武力25%／weapon80%、攻撃後に次direct hitを既存guardで受ける                      |
| 祝福 | 生命讃歌：HP全回復、MP回復なし        | 裁きの天光：potency220%、精神85%／技巧15%／weapon100%、会心可、damage後に解除可能buffをkey順で一つ除去                |
| 自由 | 無窮再演：MP全回復・通常技CT解除、行動継続  | 無相の一撃：potency240%、技巧100%／weapon100%、会心可、実効物理／魔法防御の低い側、同値physical                       |

追加四技はactionを消費する。修羅の吸収はoverkill・障壁で吸収した分・反撃・継続damageを含めない。無相は一撃であり防御無視や二重攻撃ではない。新しい魔力昇華はない。無窮再演で覚醒奥義を再使用可能にしない。

## 2.3 Trial 2「黒曜石の魔窟」

Trial 1初回clearで解禁、level hard gateなし。比較／攻略の想定はLv150～180帯・SP60・IL50級。低Lv突破、育成での押し切り、浅層での装備更新を許容し、正解buildを当てるだけの試験にしない。

|  戦 | 敵名        | 種族      |    EXP / 欠片 | 装備IL  |
| -: | --------- | ------- | ----------: | ----- |
|  1 | 煤牙の斥候     | ゴブリン    |    250 / 65 | 55–66 |
|  2 | 嘲炎の道化     | インプ     |    260 / 68 | 56–67 |
|  3 | 鉄鎖の獄犬     | ヘルハウンド  |    270 / 70 | 58–69 |
|  4 | 不寝番の石翼    | ガーゴイル   |    280 / 72 | 60–72 |
|  5 | 赤角の破城兵    | ミノタウロス  |    290 / 75 | 62–74 |
|  6 | 弔鐘の司祭     | レイス     |    300 / 78 | 64–76 |
|  7 | 蠱惑の蛇姫     | ラミア     |    320 / 82 | 66–78 |
|  8 | 夜宴の血侯     | ヴァンパイア  |    350 / 88 | 68–82 |
|  9 | 誓約喰らいの黒騎士 | デーモンナイト |    400 / 95 | 72–86 |
| 10 | 首なき断罪卿    | デュラハン   | 2,000 / 540 | 76–90 |

10戦、HP carry、1～9勝利後20%回復、各戦MP reset。各勝利のEXP・欠片・dropを同一settlementで即時確定し、戦闘間帰還でも持ち帰る。新runの再勝利は通常報酬を再取得できるが、同一request再送／表示更新では重複付与しない。未勝利battleのwithdrawalと、勝利後のrun帰還は別。

1～9平均は約1.25倍、10戦完走平均はXP・欠片とも黒晶洞名目平均の約2倍。合計4,720EXP / 1,233G。平均倍率は勝利・抽選前提付きで実時間効率そのものではない。魔窟装備は既存のIL売値式を使い、専用の売値倍率は付けない。護符は主能力別に5名称。

初回clearは**SP+40（Trial 1後の60から合計100）、地底layer2／8設備枠、Owner提供の物語**。追加覚醒・特別item・称号なし。初回挑戦／初回clear物語を再挑戦へ自動再表示しない。`(秘書名)`を実際の秘書名へ置換する。

舞台は黒晶洞最奥の封印の地。案内人はサキュバスの元魔王であり、敵の誘惑枠はサキュバスではなくラミア。物語の詳細はOwnerの文章とcurrent実装を正本とし、以前の自由案で書き換えない。

## 2.4 地底施設と公開境界

Trial進行とlayer権利はSecretary-owned、施設はNation-owned。同じ秘書で島を作り直しても権利は残るが旧島の施設を持ち越さない。

1layer=4設備枠。地底都市は首都effective最大人口+10,000、地底農場は農業労働容量+10,000、地底工場は工業労働容量+30,000、地底ミサイル基地はcapacity+1。建設・撤去はofficial Turnを一つ使う。入口・梯子は設備枠ではない。Surface MapCellや3D Worldを新設しない。

現行の地底マップは自島画面、見出し「首都地下」、正方形tileの5列連続配置。選択時だけ赤枠と詳細を出す。他島からも地底mapと秘書戦闘Lvを閲覧できるが、操作は自国権限へ限定する。AI画像の閲覧consentを維持する。

## 2.5 3.7.0回想と秘書の出会い：Ownerの確定契約

案内人の部屋の「少しお話が～」と再振りの間に回想を追加する。既に見た導入・試練開始・初回クリア等を再閲覧する。Trial 2の**初回クリア後**に「過去について問う・1」を解禁し、1～5を順に読むと次が開く。5の読了で「案内人に真剣な話をする」へ進む。会話の再選択を認める。

**回想はイベント本文の全文再表示であり、あらすじの新規作文ではない。** 初回表示と回想は同じstory正本を再利用し、本文を別々に短縮管理しない。表示だけを行い、名前入力・名付けmutation・戦闘・報酬・初回解禁を再実行しない。

秘書との出会いは、未命名の秘書画面を初めて開いたときに見せる本文。回想も、その本文を**「私の名前は――」まで省略せず**表示する。ここで終わるのは正しい。初回はその直後に名前入力へ進むため、勝手に名付け後の会話を創作して付け足さない。「秘書画面を初めて開いた時～」という操作説明で物語本文を要約するものでもない。

初回名の履歴がない場合、必要な名前置換だけ現在名を使う既定fallbackは許容する。それを理由に回想全体を「現在の名前は○○です」だけへ置換しない。現在名のfallbackと、当時の選択分岐を捏造することは別問題。storyを直す場合も明白な誤字は通常校正し、意味・設定・ニュアンスの変更だけOwnerへ確認する。

「地底」ヘッダーは追加済みの扱い。未命名なら地下APIへ進まず、`？？？`の名付け導線へ誘導する。これは秘書の出会い本文を省く許可ではない。

案内人の本名分岐・抱き締める等はOwner原稿を正本とする。

夢の女王戦・魔剣グラムは将来候補のまま。**PT基盤自体は後続3.8.0で採用・実装したため、古い3.7.0時点の除外を継承しない。** 夢の女王等の構想は`docs/future-systems/guide-dream-queen-battle.md`に保存する作業報告があるが、実Bossの実装承認とは別。没話・提案・Owner原稿を区別する。

## 2.6 3.8.0：PT・貸出・画像・スキップのOwner契約

### 目的と編成

目的は**他プレイヤーの秘書を見せ合うこと、後発支援、自分の秘書を主役にすること**。Leaderは自分の秘書1人、同行は他人の公開秘書を最大3人、合計4人。開始者が編成し、保存AIで自動戦闘する非同期PT。貸出元Ownerの同時操作は要求しない。

貸出は非排他。**同じフルールを何人のプレイヤーが同時に借りてもよい。** 同一PT内の同じ秘書の重複だけ禁止する。`is_available`はOwnerの貸出許可で、busy／世界で1人限定の予約状態ではない。

既存狩場は人数と同数の敵（1〜4体）。敵数を増やすだけでEXP・G・dropを人数倍しない。Trial 1/2はソロ専用。PT Bossの`none`または人数別HP／攻撃倍率tableを受ける構造はあるが、実Boss・倍率は未追加。将来のリカ戦は人数補正なしというOwner方針。自前の仲間を育てる仕組みは次段階候補で、初期PTに実装済みとはしない。

### 能力同期と周回負荷

本体はLeaderのcombat Lv、装備は**Leaderの対応slotのIL**を上限にする。狩場の要求Lv／ILへ合わせるものではない。低い貸出元を上方同期せず、Leaderが空けているslotの装備性能は借りない。sourceの個体・装備・ビルドを保存データ上で書き換えない。

自然成長とSTPはeffective Lvへ調整し、STP配分の比率を保つ。Skill／使用枠／AI／Growth Path等の個性は保持する。generated装備の原本identity・quality・affix・seedを尊重し、戦闘値だけ同期。fixed装備の戦闘値は対応する既存definitionから得る。具体的な生成契約は同SHAのfactory/catalogを読む。

**10秒周回で毎戦装備を再生成しない。** `690c685…`までに貸出原本と同期済みprojectionのcacheを実装。source build、Leader Lv／slot IL、計算世代をキーに使い、愛称・現在HP・覚醒台詞だけで数値を作り直さない。重い数値計算と表示・行動設定の更新を混同しない。

開始時に各memberの戦闘用データ、AI、覚醒技・フレーバー、愛称、画像／creditを固定する。将来の台詞やスキル特殊演出も開始時の取得経路へ追加できるようにする。timer、詳細開閉、再表示、round進行で貸出元を再計算しない。戦闘結果のHP／MP／覚醒を貸出元本人へ書き戻さない。

### 回復と覚醒

タンク／アタッカー系の自己回復はself-only。祝福型の回復は本人を含む生存味方へ使用でき、単体味方回復はHP率の最も低い対象を選ぶ。標準AIも本人込みで判定する。custom AIの「自分のHP」条件を勝手に別の意味へ変えない。

生命讃歌はPT全員を対象に、生存者を全回復、戦闘不能者を**HP100%で蘇生**する。味方のMP回復や状態異常解除を無断で付け足さない。発動者自身の覚醒activationに伴うHP／MP全回復とは別の効果である。天断一閃は主対象の通常威力を維持し、他の敵へ50%の副対象倍率を使う。敵ごとの防御等を無視して最終damageが必ず半分になるという意味ではない。

### 貸出報酬とスキップ

貸出先の実戦参加10回ごとに持ち主へスキップチケット1枚、持ち主単位で1日最大100枚。同じ秘書の複数利用者分も正当に集計し、同一battle再送・再表示で二重加算しない。通常のEXP・drop・進行は開始者へ帰属する。

各狩場は実戦50勝でスキップ解禁、1回1枚。各試練は実戦5周完走で解禁、**10連戦1周をまとめて10枚**で完了する。戦闘・待ち時間なしで通常の反復報酬を得る。初回SP・物語・layer解放は再取得せず、スキップを実戦解禁数や貸出参加へ加算しない。装備dropと宝物庫満杯の取り逃しは結果に表示する。

### 愛称・画像・見せ方

正式名30文字は維持し、別に最大6文字の愛称を登録できる。短い表示は愛称優先、なければ正式名、長い場合は先頭5文字＋「…」。正式名そのものは変えない。

通常／覚醒それぞれにアイコン1:1、バストアップ3:4、全身3:4の計6枠。画像ごとに制作方法・作者／権利表記を管理する。全身優先を基本にバスト優先も選べ、覚醒画像がなければ通常側へfallbackする。画像切り抜きUIは後回し。

大絵は各人の開始・覚醒・終了を基本にし、通常roundへ同じ全身絵を反復しない。PARTYが上、ENEMYが下。名前とHP／MP／覚醒を小型カードで見比べ、ゲージへ文字を重ねる。ReadyとAwaken!を分け、外枠を勝手に発光させない。スマホの通常カードは2×2を意識する。

新しいbattle IDで上端へ移動し、再表示・詳細開閉で何度も飛ばさない。詳細を省略しても覚醒・蘇生・結果・報酬は確認できるようにする。保存画像はbattle logの保持期限まで参照を保護し、現在の閲覧者のAI画像設定も適用する。旧v1/v2 logを現在の能力から再計算しない。

# 3. 装備・敏捷・資源

## 3.1 敏捷は現行のまま

高い実効敏捷が先手、同値のみ既存tie-break。相対敏捷差による回避・comboと行動阻害抵抗を維持する。新しい自然敏捷成長は採用していない。

damage action一回につきcomboを一回抽選し、native多段でも同じ結果を使う。追加action、追加damage event、追加会心、追加status抽選、追加覚醒gainを生まない。軽減後damageへの倍率である。ワイバーン敏捷12を別contentの都合で変更しない。

## 3.2 装備

既存5枠はweapon1・armor1・accessory3。generated itemは保存したpayload / identityを使用し、旧装備を現在catalogで作り直さない。3.6.0で生成IL上限を60から90へ拡張し、60以下のanchor・既存入力を維持した。

shopのノービスとgenerated rarityのレギュラー／ハイクオリティ等を混同しない。Trial 2報告はuncommon3部位の限定比較である。Unique、強化、汎用enchant、marketは別の将来scope。

## 3.3 地上資源との区別

current Surface resource definitionsには小麦、魚、怪獣肉、工業品、鉱物、石油がある。石油内部単位は万バレル。地下EXP／欠片と地上資源を無断で統合しない。

今後の産業案は、この既存の農作物・工業品・鉱物・石油を活用する方針。基礎三施設のランク2は3.7.0範囲だが、電力や加工品のruntimeとは別。基礎資源を細分化するか、加工結果を新在庫にするかは未決。

# 4. 既存育成・倉庫操作

直接STP入力、有限SP、skill取得、active slot、複合再振り、倉庫bulk saleは既存仕様を維持する。再振りは自然成長・手動割当・skill・奥義選択の関連を既存経路で扱い、無料回復や初回報酬の再取得へ転用しない。

削除・売却などのpreviewとmutationは同じ対象identityを保つ。現在の選択や画面表示だけで過去requestを解釈し直さない。具体的なcost / cooldown / constraintはcurrent serviceとtestsを読む。詳細経緯は旧版4章を参照する。

# 5. AIと戦闘ログの境界

custom AIはplayer保存済み設定を使い、既定AIを新ダンジョン専用に無言で差し替えない。覚醒HP条件の変更だけでも攻略時間や発動率は変わるため、比較報告へ明記する。

一つのexecuteTurnに覚醒activation・奥義・通常行動が含まれる場合がある。技の宣言、MP cost、効果、反撃、継続効果、自然回復を混同しない。

PT基盤とparty presentation v3は3.8.0候補で実装済み。manual combat、万能action frameworkは今回scope外。partyログではaction_idとactor/target IDを使って宣言・MP cost・直接効果・反撃・吸収・蘇生を結び付ける。実engine出力をprojectorとfrontendへ通すfixtureを使い、双方で別々の架空shapeを作って成功扱いしない。

# 6. 表示の現在地と、次に相談するもの

## 6.1 3.6.0戦闘UI

冒頭で最終HPを見せず、遭遇→第1round開始状態→行動→次round開始状態→最後に決着状態・勝敗・報酬。次round開始カードは前roundの全終了処理後であり、次round開始処理後と偽らない。

途中の状態カードを折り畳まず、末尾の分析統計を「戦闘詳細」にまとめて初期closed。「末尾へ」を維持。覚醒gauge満タンと覚醒中を区別する。内部0～1000を生の数値として見せず、barを基本にし、アクセシビリティ上の値はpercentageで伝える。fill以外のcard背景／外枠を勝手に発光させない。

この節前半は3.6.0で導入した表示の履歴。3.8.0では2.6の画像6枠・履歴参照・compactなPT／ソロ表示へ拡張した。旧版の「専用覚醒portrait未実装」は解除する。詳細省略modeを追加しても、状態を別の時点へすり替えたり、保存済みlogを再計算したりしない。

## 6.2 旧ログを変えない

presentation v2は新battleのinitial\_stateとround boundary等の投影を追加した。旧v1／flat logは保存済み情報でfallback。旧battleを再戦・再計算して補完しない。不足状態を現在profileや0で埋めない。表示やdetails開閉で抽選・報酬を再実行しない。

## 6.3 次の主題：地上の産業連鎖

Ownerの意図は「箱庭がメインなので、地上の島経営を豊かにする」。限られた土地、とくに100マスを意識した島づくりの中で、一マスの生産能力・役割・防災を育て、空間を有効利用する。100マスをWorldの絶対面積上限へ読み替えない。

基礎の農場・工場・採掘場は人口と土地を基盤にし、追加の燃料等を必須にしない方向。将来の別系統の上位施設は、追加の労働人口ではなく基礎資源を利用する案。**今回の「大農場」等は基礎施設の規模拡張であり、人口不要の自動工場ではない。**

今回Ownerが改めて示した表現は**「人口は農場と工場、採掘場に使う」**。基礎三施設の生産と、基礎資源を消費する将来の上位産業を分ける方針として保持する。食品加工場・商業・発電・研究・物流・造船・観光等は候補が出ただけで、最初に何を入れるか、入出力・費用・数値は未決。ChatGPTの仮の消費量／売上をOwner確定値にしない。

**ターン数圧縮は作らない。** 成熟した施設が災害で全損せず規模を失って残る方向は採用し、ランク2として具体化した。PD（輝石／パラドックス）、電力、加工、第三次産業、複数マス合体などの案は将来候補。以前の「20PD＋3倍資金で整備を時短」や整備量増幅案を、そのまま次の確定仕様へ復活させない。

RAの調査対象としてOwnerが指定したのはGoogle Driveの「箱庭リファレンス / hako-r-a_FE」。
`https://drive.google.com/drive/folders/1hjeYuiMFwmXf1Qz7_WtvA7zcXKD4VJgI`

Owner提供のRA最新版として扱うが、世界中の公開版の最新版を確認した意味ではない。調査scopeを無断で他の兄弟folderへ広げず、原理の参照と第三者code／素材の利用条件を区別する。

## 6.4 素材制作・ドットSkill

箱庭用の地形下地（平地・荒地・海・浅瀬）へ、人とAIが描き足す制作環境を目指す。**地上チップは32×32を最小単位とし、一行おきに横16pxずれる配置を扱う**。行parityは対象の地上rendererを正本として確認する。地底の正方格子や、アイソメトリックな立体台座と混同しない。

単体チップ、選んだ複数の箱庭マスへまたがる施設、地形を残す透明overlayの三用途を想定する。占有マスと描画範囲は別に持つ。複数マスを単純な32×行列だけで切り出さず、stagger込みの隣接previewを考える。下地ロック、palette、layer、nearest-neighbor拡大、原寸検査、編集元データ、GIF出力が候補。

`pixel-art-authoring`／`hakoniwa-pixel-art-authoring`というSkill名で作成・利用を試した経緯があるが、**このチャットでSkill本文・同梱assetsを読んだうえで制作した成功例は未確認**。画像生成へ流れてペリドット／ペコリットの大判イラストが出た結果を、32×32ゲーム用GIF完成やSkill動作確認済みと扱わない。

Ownerは荒地・平地・海等の実画像をSkillの`assets/`へ同梱したい。更新ZIPの準備・再登録と、実際の画像参照確認が次のTODO。汎用Skillでサイズを固定しない方針と、箱庭専用の既知の32×32規格を省略することは別。Skillの名前だけでなく実体を確認する。素材の出所・利用条件を保持し、無条件の再配布可とは判断しない。

「地底」ヘッダーは実装済みの扱いへ移したため、今後の素材ツールの未実装項目には含めない。

## 6.5 ほかの将来候補

NPC海賊、船修理、第三狩場以降、Unique、enchant／装備強化、自前仲間育成、案内人再戦、実PT Boss、manual combat、地底装備marketは別scope。Trial 2、PT基盤、専用覚醒画像6枠、スキップ消費は候補側で実装済みのためこの未実装一覧へ戻さない。

地上の秘書itemと地底装備は別体系。地上用レギュラー／ハイクオリティ／アーティファクトを増やす案では、産業を強くする遺物や遊び方を変える便利道具を相談した。魔法の白旗、食品加工・発電と組み合わせる宝物、移築道具などの名前や効果案があるが、個別の採用・数値・入手方法・実装releaseは未確定。現行catalog／装備枠を確認してから相談する。

船舶運営技能の必要EXPは、**各LvUPごとに100、200、300、400…と増える形式**をOwnerが指定した。技能固有効果は未決定。これは今回会話での方針で、`690c685…`の技能定義から曲線を再確認した記録ではない。未確認のまま変更済みやdeploy済みと書かず、必要なときに`ship_operations`の定義を確認する。他技能に必要EXP表がないmanualへ、この技能だけ数値を長々追加しない。

## 6.6 技巧・成長研究の結論：今回不採用

旧版6.6の相対技巧、会心倍率、防具IL共通R、魔力昇華、魔法係数変更、自然敏捷成長、祝福武力+0を含む自然成長候補は、検討を経て**今回すべて採用しない**とOwnerが決定した。

「成長曲線」はこの会話ではLvごとの能力上昇を指しており、必要EXP曲線を変える依頼ではなかった。その能力成長も現状維持。既存の精神型、敏捷、ワイバーン等へ広範な影響を出さないことを優先する。

将来、自由へ**技量型のskill tree**を追加し、安定型とは違う上振れ／下振れの遊びを局所的に作る案はある。現行の技巧を全員向けに改造する計画ではなく、現在の実装scopeでもない。

研究結果は参考として残してよいが、candidate codeを新releaseへ自動的に混ぜない。旧版の「Owner確定」「昇華は撤回ではない」は当時の相談記録で、今回の最終判断に優先しない。

## 6.7 行動ログ形式：実装済みと未実装

solo presentation v2を維持し、3.8.0のPTはv3。initial／round boundary／finalを分離する。party action_id、actor_id、target_id、target_idsによって宣言・MP cost・結果を関連付け、反撃・吸収の本当の対象を一括上書きしない。

これをもって旧案の汎用parent_action_id／万能trace／全行動再生が完成したとはしない。旧v1/v2は保存済み情報の範囲で表示し、新しいデータがない部分を現在のprofileやゼロで埋めない。

## 6.8 プレイヤーマニュアル全面改稿

OwnerはMVP時代の未実装宣言・古い経験値説明・特定アイテムの過剰な特例説明を問題視し、3.7.0向けの全面改稿を依頼した。旧マニュアルは仕様の根拠にせず、説明順の参考や矛盾確認だけに使う。

前会話で`hakoniwa-2s-plus-manual-3.7.0.zip`、全章HTML、Codex反映指示を作成・共有済み。入口／はじめの一歩／土地と施設／人口と資源／災害／ミサイルと怪獣／港と船／交易場／地上秘書／地底探索／育成と戦闘／地底装備／困ったときの13章。最終的なserved manualへの反映状況は今回独立確認していない。ZIPが必要なら既存成果物を取得し、ゼロから作り直さない。

**プレイヤーが遊び方を調べられる説明書にする。内部仕様と全例外の百科事典にしない。** 主要施設や2S＋固有機能は個別に説明し、個別アイテムの細部はゲーム内効果欄へ委ねる。大枠と重要な単位・受取先・費用・解禁条件が現行と整合することを重視し、依頼のない25項目全監査や延々としたP2レビューを再開しない。明白な相違はその箇所だけ実装で確認する。

特に怪獣の実HP damage EXP／基地EXP／秘書討伐EXP／撃破報酬／地底Combat EXP、地上と地底の通貨・装備、STPとSP、試練途中帰還を分離する。futureの地上HQ・夢の女王・グラムを混ぜない。既存URL互換は保ちながら用途別の目次へ接続する方針。

### 3.8.0の最終マニュアル更新

`690c685…`には既存の`product/docs/manual/`13ファイルがあり、3.8.0のPT／貸出／スキップ追記はまだない。`690c685… → 9b509680…`にもmanual変更はない。主な更新候補は`underground.md`、`combat.md`、`secretary.md`。新ページや全面改稿は不要。

Ownerが検閲・編集したA〜Jの内容を、指定された範囲だけ既存節へ統合する。仕様の全転記ではなく、既存manualの文体と粒度を優先する。解禁条件・消費・上限・solo制限・同期など必要な情報は残し、内部class・DB・cache・lock・snapshotの解説は入れない。Owner未選択項目を勝手に全採用したり、書くために未実装機能を足したりしない。

船舶運営だけ100／200／300／400の必要EXP表を追加しない。他の技能と同程度の説明なら現状の「船の成功航行で育つ／固有効果は準備中」を維持してよい。正式名はフルネーム、愛称は可愛い呼び名という意図も、説明書から浮かない文章へ整える。

案内人の雑談は個々の話題を列挙せず、**休憩中で時に少し酔った案内人と他愛のない話ができ、豆知識が聞けることもある**程度。物語の答え、裏設定、台詞全文をmanualへ載せない。

#### 雑談接続漏れの履歴と、9b509680の修正

**修正前**の`690c685…`の具体的な状態：

- `product/config/underground-intro.php`の`guide_banter`には3件の短い台詞がある。
- `UndergroundIntroCatalog`が読み、`UndergroundIntroService`が日単位のstable selectionで1件返す。
- `UndergroundPanel.vue`の通常ショップ欄にその台詞を自動表示する。
- 部屋の「少しお話がしたい」は`guideMode='conversation'`へ切り替えるだけで、「話題が思い浮かんだら…」という固定placeholderを表示する。

**9b509680で共有・追加source review済みの修正**：`UndergroundIntroService`が`guide_banter_entries`でcatalogの一覧を渡し、`UndergroundPanel::startGuideConversation()`がボタン押下ごとにクライアント側で1件選ぶ。ショップ欄の自動表示と固定placeholderは削除した。新API・DB・戦闘乱数への接続はない。

既存の日単位`guide_banter`は後方互換fallbackとして残るが、現行APIの候補一覧を利用する会話を日単位には制限しない。Ownerは連打による会話ガチャを許可した。日単位固定や「全部を見せない制限」を独断で復活させない。既存の台詞3件自体はこのcommitで増えていない。

今後も既存catalogを正本とする。新DB、永続会話履歴、新しいstory解禁、戦闘乱数との共有は不要。台詞の追加採用はOwnerが決める。

`App.test.ts`では実ボタンを2回押し、異なるcatalog台詞を表示し、ショップへ自動表示しないことを確認するtestを追加。`UndergroundPlayerAccessTest.php`ではAPIの一覧とcatalogの一致を確認する。**source上の接続・回帰testの存在は確認済みだが、新HEADのfocused実行結果は未取得**。実際の完了報告へ結果を添える。

Web版ChatGPTは原稿・独立確認を担い、read-only MCPからrepositoryを書き換えない。OwnerがCodexへ渡して反映する方式。manualのみの差分を理由に全PHPUnitを再実行せず、雑談コード差分は該当テストと分けて検証する。

## 6.9 第三狩場「輝きの王国」・試練3の構想

第三層は黒い輝石の底の巨大空洞へつながり、魔王が滅ぼした人間の王国の城下町が美しく残る。中世〜近世ファンタジーの街が輝石に覆われ、埋もれている。Owner提示の画像は雰囲気の参考で、描かれた敵をそのまま第三層の確定編成にするものではない。

アンデッドは腐敗死体ではなく**輝石から再生された人間**で、操り人形のよう。王都を守る兵士、高位魔術師などを出したい。既存より敵種を倍程度へ増やす意向があるが、ChatGPTが作った敵16案は未採用の提案。

Ownerの編成案は通常敵をランダムに混成し、PTでヒーラー3体など偏った組合せでも仕様とすること。敵ヒーラーは人数を見た単純AIで、単独なら自己回復、2体以上なら威力半減の全体回復を行う構想。行動の細部・倍率全体・出現数とPT人数の厳密な関係は、実装着手時に詰める。

レアエネミーは遭遇の最初に判定し、当選した場合は全枠をレア編成にする意向。通常編成へ一部だけ混ぜ、倒し切れず100round撤退のEXP1/4になるような事故を避けたい。具体的な確率・敵定義・報酬は未決。

古い「Lv90想定」を達成条件として固定せず、Lv50台で突破した現playerのbuild／装備／AIも対象限定のread-only診断で確認し、第三層以降の強さを考えたいというOwner方針。過去コンテンツを無断で弱体／強化しない。**試練3「王城」はかなり先に保留**し、他コンテンツを十分遊んでようやく挑める高難度、勇者のアンデッドが多数いる場所を想定する。

世界観は、東の神・規律・祈りによって勇者が生まれる人の世界と、西の魔王・欲望・淘汰の魔の世界、大山脈、その世界の果てにある大いなる滝を背にした人間の王都というOwner原案。魔の王女が故郷を離れ、人間社会の腐敗や聖女の幽閉を見ながら居場所と和平を求めた裏設定がある。**人の王が王女へ何をしたかは未確定**で、ChatGPTの考察を正史へ採用した記録はない。東西表記など原稿内の未整理部分も、Agentが勝手に補完・訂正しない。

過去に渡した提案MDは`hakoniwa-kingdom-of-radiance-design.md`。チャット成果物であり、repositoryへ保存済みとは未確認。必要時はOwner提供の現物を読む。第三層の設定や画像をマニュアル更新・3.8.0最終仕上げへ混ぜない。

## 6.10 称号・実績とMariachang連携の構想

Ownerは、秘書名の上へ小さく「農業大好き」のような付け替え可能な称号を表示し、箱庭実績で称号を解禁する案を示した。箱庭で取った実績をMaria側へも追加し、Discordサーバー「雨宿り」にそのDiscord User IDが参加している場合に通知したい。

実績／称号の保存主体、条件、報酬、既存実績との対応、Discord連携・再送・通知の方式はまだ実装scopeとして確定していない。ChatGPTの署名付きeventやSecretary-owned称号案は設計提案であって、Owner採用済みの技術仕様ではない。

MCPの複数repo読取はこの連携調査に有用だが、DB共有・write権限追加・双方向同期を承認した話ではない。必要時に`hakoniwa-world`と`mariachang`の各refを個別SHAへ固定して既存実装を調べる。

# 7. Test / review / GitHub停止中の運用

## 7.1 Test・reviewの共通原則

- 開発中はfocused tests。migrationはfreshとsupported upgradeを区別して必要な検証を行う。
- GitHub利用可能時はexact-head Qualityを確認する。**停止中は同じSHAのlocal CIと結果記録を代替の根拠とする**。GitHub CIがないことを理由に検証不能とも、検証不要とも言わない。
- 実調査報告ではPHPUnitは**16分割**。frontend・静的解析等も合わせ「約20CI」と呼んでいた。PCで16並列を必須にしない。既存`product/tests/scripts/run_parallel_tests.sh`は既定4並列・DB分離・全件割当検証に対応との報告。
- 同一source／dependency／設定で成功した全suiteを理由なく繰り返さず、変更箇所と最終candidateに応じて実行する。ローカル検証を本番DBへ向けない。
- worktree、source、vendor、Composer設定、test設定の世代を揃える。stale containerをruntimeの不具合と取り違えない。
- simulationはsource SHA・manifest hash・seed・再現command・集計を記録。未知のGit状態をcleanとしない。
- P0/P1/P2は実際に到達する条件・影響・対象行を示す。好み、過剰設計、非現実的な異常値だけの指摘は増やさない。必要な証拠が読めない場合は検証範囲不足を明記し、無条件PASSにしない。
- subagentは限定調査・機械作業・focused test。Owner意図、release境界、migration、統合、production判断は主担当が保持する。
- 一releaseのSurface追加は原則一世代。merge、deploy、OCI変更、本番DB、補填、handoff編集は許可を混同しない。過去の承認を将来作業へ自動拡張しない。
- Web版ChatGPTの利用回数とCodexの作業コストを意識する。今回のように実装整理／判断と重い検証を分ける運用を認め、成功済み全suiteを各小変更で回し直さない。実際に使っていないsubagent／modelで検証したと報告しない。
- test軽量化は、実engine→projector→UIの整合、軽い入力検証用fixtureの分離、supported upgrade準備の共有、shard時間と証拠保存を優先する。transaction rollback、個体identity、二重報酬、権限、DB分離の必要な回帰まで無差別に削らない。
- release差分の指摘、差分外の横断残件、UIの提案を分ける。今回Ownerのdeploy条件はP0/P1/P2なし。好みのP3だけで勝手にreleaseを止める基準を増やさない。
- 原稿・プロンプト・MDを求められたときに画像生成しない。同じ用途のファイルを別名で二重納品しない。

## 7.2 GitHub停止とSupport対応

`Mamiki765`の認証APIが`403 / Sorry. Your account was suspended`を返し、ブラウザではログイン後500が出た経緯がある。停止原因を20CI分割やCodex使用と断定する証拠はない。別のbotアカウントを停止対象の正規アカウントと混同しない。

Ownerが貼付したGitHub Support返信（2026-09-06 20:15 UTC＝2026-09-07 05:15 JST）は、「一部の活動が不正利用検知でflagされ、規約と抵触する可能性のためmanual review対象。今後の利用目的を説明してほしい」という内容。**規約違反確定や復旧承認ではない。**

Ownerは同じスレッドへ返信済み。個人のプログラミング学習・趣味のWebゲーム／Discord bot開発、Codexによる開発支援、Git履歴・PR・テスト管理という用途を説明した。重複CIを減らし、事前にlocal focused checksを行い、終了条件のない自動review／fix loopを避ける方針も伝えた。現時点は返答待ち。追加の重複申立てや新規アカウントによる迂回を行わない。復旧日数の過去の推測を確約として継承しない。

## 7.3 OCI bare Git：転送経路は構築済み

```text
PC / Codex repository
  origin      → 既存GitHub（保持）
  oci-offline → SSH → /home/ubuntu/git/hakoniwa-world.git
                              ↓ 明示fetch
                /home/ubuntu/apps/hakoniwa-world
                    本番checkout / 既存origin保持
```

PCと本番に第二remoteを追加し、3.7.1の同一SHAで転送・fetch・一致を実機確認したとの報告。その後Ownerの許可で3.7.2を本番反映した。SSH障害はPCの設定ファイル権限修正で解消済み。鍵の内容や認証ファイルを再表示しない。

bareは受取場所で、本番working treeへの直接push・push即deployは採用しない。`offline/main`の最新値は実読出しで確認する。bareのbranch進行と稼働中のproduction SHAは別であり、candidateやdocs-only commitを受け取ってもdeploy済みにはならない。

2026-09-07 JST、Codexが実機の`hooks/pre-receive`を読取確認した。許可範囲は`refs/heads/offline/*`と新規`refs/tags/offline/*`。ref削除と既存tagの更新を拒否する。将来も実際の許可refを確認し、force／mirror pushや保護設定の無断変更はしない。

その文書反映先の限定は2026-09-07時点の個別作業の記録。現在のapplication作業は`release/3.8.0` → OCI `offline/release/3.8.0`。最新handoffもまずその作業差分を保全して反映する。最終レビュー後、既存のoffline/main昇格・deploy手順を使う場合は、実際に進めたSHAを記録する。未レビュー差分を混ぜたmain昇格やforce更新はしない。

通常deployは現在の実Compose・image・mount・backup手順を確認し、GitHub不通を理由に全面変更しない。ローカルbuild＋image転送は比較案であって必須決定ではない。「本番をCI runnerにしない」は開発中の全テスト反復を移さないという意味で、既存deploy build中の検査を無条件に禁止したものではない。DB rollback可とは仮定せず、forward migration後の旧コード互換性を分ける。

OwnerはOCI週次backup・1か月保管を用意している。新規bare・MCP・秘密設定までその対象に含まれるか、off-host性・復元可能性は別途確認する。PCにもGit履歴がある。bundleは履歴保全に使えるが、未commitファイル・外部画像・秘密設定・hooksを含むバックアップとは区別する。

Owner経由のCodex報告で、追加bareの作成は完了している：`/home/ubuntu/git/Mariachang.git`（default master）、`maria-board.git`（main）、`pbw-love-memo.git`（master）。初回全ref mirror、fsck、non-FF／ref削除拒否、push object検査、source不変を確認したとの報告。`ubuntu:ubuntu`／0700。これは本更新でhost上の全設定を再監査した結果ではない。

Mariachangの既存未追跡`scripts/`はbareに含まれず、週次OCIバックアップだけが保護という報告。勝手にcommit・archiveしていない。GitHub復旧後は双方の履歴を比較し、同じcommit SHAを維持して通常の保護ルールで統合する。

## 7.4 Hakoniwa MCP：現在は1.3.0、13 tools、4 repo

2026-09-09の`get_status`／workspace応答で、runtime **1.3.0 / schema 4 / 13 tools**、build fingerprint `sha256:bf74f2f71b710a70d9230a928f0332a0c372e31a3cd96b51b252f844e920b002`を確認した。

allowlistは`hakoniwa-world`、`mariachang`、`maria-board`、`pbw-love-memo`。**toolへ渡すMariachangのrepo名は小文字`mariachang`**。host bare名の大文字と混同しない。

| tool群 | 用途 |
|---|---|
| `get_status`, `workspace_status` | runtime世代・能力、repo/application/production/CI/handoffの出典別要約 |
| `repo_refs`, `repo_resolve_ref`, `repo_commit` | branch/tag一覧、commit SHA固定、metadata |
| `repo_changed_files`, `repo_diff`, `repo_read_file` | 固定SHA差分と本文。行／文字継続、変更一覧cursorを利用 |
| `repo_list_directory`, `repo_search_text` | tracked directory一覧とliteral検索 |
| `production_status` | hostが生成したsanitizedな本番snapshotを読む |
| `production_diagnostic` | 保存済みの対象限定caseを固定profileで読む |
| `ci_get_record` | 要求exact SHAのsanitized CI記録を読む。別SHAへ代替しない |

返却capは1 MiB。大きなファイルを元sizeだけで諦めず、`has_more`と`next_start_line/column`、cursorを見て必要部分の続きを読む。秘密path・binary・symlink・submodule等の拒否、通常source内の秘密値部分redactionを維持する。伏字の中身を復元したり、filterを回避して取得したりしない。

**初期6 tool／64 KiBは過去のMCP Phase 1仕様**。それを現在値として接続工事をやり直さない。ChatGPTへ見えているschemaとruntimeのcapabilitiesに差がある場合は、使えるtool定義と実呼出しを確認する。runtimeが新しいという理由だけで、未取得のtoolを呼べたことにしない。

MCPはread-only。任意shell／SQL、Git write、checkout変更、deploy、DB write、Turn操作、Docker socket、DB credentialは渡さない。PC側CodexにOwnerが許可したOCI操作権限と、Web側MCPの権限は別である。MCP本体・Tunnelの構築は成立済み。

application release refとMCP用review refは別。MCPコード／設計資料が同じrepositoryの`tools/hakoniwa-mcp/`等へ入っていても、別review branch上のruntime SHAをapplicationのtested/deployed SHAと同一視しない。

## 7.5 本番snapshotと対象限定DB診断

MCP 1.2.1 infrastructureについて、Owner経由でDB role／schema／exporter／runtime適用完了の報告を受けた。**「Gate 2未適用」「秘密credential入力待ち」へ戻さない。** 現在のMCP表示は1.3.0で、複数repo対応が後続している。

診断は`production DB → hostの固定query exporter → sanitized case snapshot → MCPのread-only読取`。MCPからDBへ直接SELECTする仕組みではない。caseなし／期限切れはunknown・unavailableとして扱う。全player scanを既定にしない。

1.2.1完了報告では、`hakoniwa_diag_owner`はNOLOGIN・passwordなし、`hakoniwa_diag_exporter`は専用LOGIN。schema `hakoniwa_diagnostic`、内部view12、固定SECURITY DEFINER/STABLE function12、owner列SELECT96、exporter EXECUTE12、read-only settings5。exporterのbase table／内部view直接SELECTは拒否し、MCPにcredentialを渡していない。**これらのACL件数はCodex報告を継承したもので、本更新で全権限を再照会した結果ではない。**

診断profileは`item_sale`、`turn_audit`、`secretary`。itemの個体／装備／escrow／交易履歴、対象Turn/audit、対象秘書のLv・EXP・スキル・装備・地下profile等に使う。raw audit JSON、秘密値、メール/IP等を出さず、問い合わせ対象のゲーム内IDは必要な範囲で維持する。

production authorityはcheckout HEADだけでなく、canonical bindingのgeneration・deployed SHA・image・application version。snapshotの生成前後で一致を確認する設計。古いsnapshotやmixedを現在値と断定しない。MCPのpending migration 0は、そのsnapshotの稼働コードについての値。

INQ対象caseは1.16参照。今後のバランス検討も、Ownerが許可した対象の状態をread-onlyで確認するところから始める。診断できることはデータ補填・装備変更・balance変更の許可ではない。

## 7.6 独立レビューSkillと手動の復路

Ownerはレビュー結果をMarkdownで受け取り、手動でCodexへ渡す。**ChatGPTを外部から自動起動、MCPへreview.md保存、モデルAPI利用、無限review／fix loopは今回不要。** 復路が普遍的に不可能と断定するのではなく、必要ないため採用しない。

`hakoniwa-independent-review`はPCの`C:\Users\Hobby\.codex\skills\hakoniwa-independent-review\`に`SKILL.md`と`agents/openai.yaml`を作成し、quick validatorとYAML検証成功・実repo無変更との報告。OwnerはPCからアップロード成功とも報告したが、このチャットでSkill本文を読めた・Skillのend-to-end試験を完了したとは扱わない。

期待する手順は、base/headをSHA固定→変更一覧→diff→必要な関連runtime／tests→P0/P1/P2の実害評価→Markdown。実装・handoff変更・deployはレビューに含めない。全テストを読んだだけで実行済みと書かない。Skill不使用でも同じ手順を指示で適用できる。

レビュー出力は`Base`、`Reviewed commit`、`Release diff verdict: PASS / FINDINGS / NOT_REVIEWED`、findingの到達条件・影響・根拠・最小修正方向、coverageと取得不能／filtered範囲を記録する。全page未読など重要な確認不足を無条件PASSにしない。

`Cross-cutting findings`はrelease差分外の残件として別に出す。UI提案もseverity付きバグと分離する。修正後は固定した新SHAへの追加diffを確認し、前の全repositoryを理由なく再読しない。

## 7.7 handoffを次のChatへ渡す経路

```text
MCP対応Chatが固定SHAのcodeを読む
  → レビュー／Owner判断をMarkdownで出す
  → OwnerがCodexへ渡し、handoff編集を個別許可
  → Codexが既存変更を保全してhandoffを反映
  → 許可されたrefへcommit／oci-offline反映
  → 次のMCP対応Chatが新SHAのhandoffを読む
```

MCPへwrite権限を追加しなくてもよい。反映前でも、更新MDを新チャットへ添付すれば文脈を引き継げる。読めないチャットの中だけに最新情報を閉じ込めない。

更新案の作成自体はrepo編集・commit・push・deployではない。Codexは既存の新しい記述と作業差分を保全して反映する。docs-only commitで共有refが進んでも、**productionには実際にdeployしたapplication SHAを記録する**。handoff commitとruntime tested SHAを一致させるためだけの全CI再実行はしない。

旧Projectメモの「GitHub優先」「MCPは箱庭だけ」は、停止前・複数repo対応前の記録。現在はOwnerが用意したOCI bareとMCPを使う。復旧後の再同期は別作業とし、他repoの変更・deploy許可まで自動的に広げない。

# 8. 次のagentが最初に行うこと

1. `get_status`で実接続とruntime能力を確認する。最新観測はMCP 1.3.0／schema 4／13 toolsだが、現在応答を優先する。MCPはread-only。
2. `offline/main`と`offline/release/3.8.0`を別々にresolveする。記録上はmain=`368eadf…`、release=`9b509680…`。違えば進んだ内容を確認し、古いSHAへ戻さない。
3. 最新handoffがあるrefを選び、その**解決済み40桁SHA**から本書を読む。mainだけが古くreleaseに最新文書がある可能性を見落とさない。以後のcode／diff／testsも同じSHAへ固定する。
4. `workspace_status`または`production_status`で本番を別途確認する。最終観測は3.7.3だが、Ownerの条件付き承認により既に3.8.0へ更新されている可能性がある。deploy済みを未承認へ戻したり、ref更新だけでdeploy済みにしたりしない。
5. 最優先は最終仕上げの完了報告。`690c685…`のfull CI報告と、雑談接続`9b509680…`の追加source reviewでP0/P1/P2なしを基点にする。manualはこの共有差分に含まれていないため、反映状況と新HEADのfocused結果を確認する。さらに進んだ差分の独立レビューにもP0/P1/P2がなければ、承認範囲の既存deploy手順へ進める方針。
6. CIはreviewed／tested／deployed／handoffのSHAを分ける。MCP recordがUNKNOWNなら既存local evidenceの所在を確認し、FAILやPASSへ推測変換しない。後続差分が小さいことだけで旧full結果を新SHAのfull結果にしないが、証拠共有のためだけに全suiteを反復しない。
7. 次の相談は地上産業、船舶運営、地上用秘書item、第三層、称号・Maria連携など。Owner方針は6.3・6.5・6.9・6.10。提案を実装決定へ補完しない。足りない細かな案はOwnerに改めて説明してもらう。
8. 実装は主にPC側Codex、Web版ChatGPTは相談・原稿・独立レビュー。handoff編集はOwnerの個別許可時だけ。画像生成、MCP工事、補填、Turn操作、全履歴の再監査を依頼なしで始めない。
