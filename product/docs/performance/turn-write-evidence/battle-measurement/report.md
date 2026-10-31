# BU-02 一応答内equipped rows共有の限定測定

**SQL重複は実測で確認したが、今回の3短pairで状態応答全体の安定した時間改善は確認できなかった。Turn保存の優先を維持し、BU-02の製品実装は今回見送る。**

## 対象と方法

- 基準main `2e93ec918774ad64d787aba99fc59a7bc3e54d16` と同じ装備resolver/状態返却経路に、既存Draft #356 `7aa89e1058416da5b7cc3b65b65a7cb3816deb83` のimmutable catalog再利用だけを適用した隔離runtime。
- PHP 8.5.8、current composer.lock vendor、専用PostgreSQL 18.4/internal network/tmpfs。製品app/testsはreadonly。branch/製品source/commit/CI/本番を変更していない。
- 実際の`UndergroundIntroService::state($user)`を測定。既存`UndergroundPlayerAccessTestCase`のsecretaryUser/openEquipmentProfile/tutorialBattle helperを使用した一つのsynthetic profile。generatedのweapon/armor/accessory3/resonanceを全6slotに装備し、resonanceはIL210/研磨+2。通常gearはIL30。fixture/初期補完・catalog・visitor/balance初回処理は測定窓外でwarmした。時刻は固定。
- resolver sourceの2つの同形equipped SELECTサイトをmemory-onlyで同じquery関数へ接続し、baselineは毎回読取、prototypeは**一回のstate応答内だけ**最初のrowsを共有。応答終了時に必ず共有contextを破棄。definition検証/研磨/aggregate/UI projectionは両modeとも既存bodyを実行し、最適化実装は製品へ適用していない。
- 測定順はbaseline→snapshot、snapshot→baseline、baseline→snapshotの3組のみ。rootがTurn/C1のCPU窓と調整した後に実行し、終了を通知した。

これは実serviceの状態返却時間であり、HTTP認証・middleware・transport・JSON送信・client描画を含むwhole-HTTP測定ではない。6slotが埋まった一例で、全プレイヤーの装備分布/アクセス頻度を代表しない。

## 実測

全3組でfull stateのserialized hashが一致し、equipped SELECT **3→1**、総SQL **40→38**。外部testは **1 test / 11 assertions成功**。準備のみの先行確認は別に1 test / 1 assertion成功。

| pair / 順番 | state service wall ms baseline→snapshot | baseline−snapshot | process CPU ms baseline→snapshot | 全SQL ms baseline→snapshot | equipped SQL ms baseline→snapshot |
|---|---:|---:|---:|---:|---:|
| 1 B→S | 21.5388→20.8853 | +0.6535 | 14.748→14.828 | 9.97→9.22 | 1.25→0.38 |
| 2 S→B | 20.0363→20.0367 | −0.0004 | 13.748→13.351 | 9.50→9.39 | 1.01→0.44 |
| 3 B→S | 22.0013→22.2445 | −0.2432 | 14.999→14.397 | 10.04→11.29 | 1.35→2.10 |

wallの**pair差の中央値は約−0.0004ms**で、改善は確立していない。各mode単独のwall中央値は21.5388/20.8853msだが、その差だけを安定した0.65ms改善と解釈しない。target SQLにも1回2.10msのsampleがあり、短いdriver/内部network時間は揺れている。pair差のCPU中央値は約0.397ms短縮、target SQL差中央値は約0.57ms短縮だが、3組・instrumentありの限定観測である。

## 方法内の時間と回数

下表は3sampleの中央値。**各methodのinclusive wall/CPUであり、親子呼出を重複して含むため足し合わせない。** CPUはPHP processのgetrusageであり、PostgreSQL server CPUではない。SQL msはQueryExecutedのdriver計測値で、method wallにはDB待ち/ORM hydration等も入る。wrapper/getrusage overheadを含み、両modeを同じ方法で測定した。

| resolver method | calls B→S | inclusive wall ms B→S | inclusive process CPU ms B→S |
|---|---:|---:|---:|
| definitionForRow | 18→18 | 1.509→1.294 | 1.513→1.296 |
| equippedDefinitions | 2→2 | 2.457→2.020 | 1.788→1.343 |
| combatLoadout | 2→2 | 2.699→2.234 | 2.030→1.553 |
| ratingRequirementBps | 1→1 | 1.334→0.582 | 1.020→0.582 |
| projectOwned | 6→6 | 1.126→1.106 | 1.127→1.106 |
| summary | 1→1 | 3.696→2.352 | 2.910→2.053 |

rows共有だけのprototypeなので、definitionForRowは18回、combatLoadoutは2回のまま。検証済みdefinition/aggregateまで共有する案は別で、今回の測定からその効果を追加主張しない。18回のdefinitionForRow全体でもこのfixtureの約1.5msであり、この全額が削減可能なわけではない。

## 意味保存と未検証

確認できたのは、この同一fixture・同一状態応答でqueryが2件減り、結果全体が不変であること。prototypeの共有範囲は一応答に固定し、別profile利用は失敗させ、終了後にcontextがnullであることをassertした。scopeへ登録した永続cacheではない。profile/装備へのwriteや画像lease処理はprototypeにない。

以下はこのprobeでは未検証:

- A応答→equip/unequip/polish/sell/market mutation→B応答のfreshness。
- 並行profile mutation、borrower/lender owner境界、prepared借用snapshotのlock/画像lease保持。
- public statusの返却field秘匿、貸出候補20件pageのSQL削減。
- HTTP latency、本番PHP8.5.12、本番DB/負荷・gear分布。

実装するとしても一応答の明示的snapshotに閉じ、上記の既存代表をfocusedで接続する必要がある。rootの実装判断前にmutable cacheを製品へ導入していない。

## 順位判断

1. **Turn保存の支配コスト改善を先行**する。BU-02には2 SELECTの確実な削減があるが、この最小prototypeで応答全体の時間改善は安定しなかったため、今は実装しない。
2. 将来、同じ装備集合の投影が多い画面/DB往復が支配する環境で具体的な寄与が観測されれば、rowsだけでなくvalidated definition/aggregateの共有を再評価する。今回の残差から大きな改善率を予測しない。
3. BU-03も従来どおり見送り。以前の48戦probeの回数/hash証拠を保全し、今回のBU-02測定でボス/覚醒/free-actionが確認済みになったとは扱わない。

## 証拠

- `EquippedSnapshotProbeTest.php`: 製品外のfixture・memory overlay・方法内instrument・最大3pair。
- `prepared.json` / `prepare.log`: fixture準備のみの成功。
- `measurement.json`: 3組raw値、方法回数/時間、query shape/bindings（synthetic profileのみ）、全state hash。
- `summary.json`: range/中央値の読取集計。
- `run.log`: 1 test / 11 assertions成功。

raw sourceの変更検出用resolver SHA-256は `54764d2bd3887317b43eb1afffe06dfe5dfb3af021fdb79e5e84f7992c674b9c`。古い監査・probe成果物は変更していない。追加反復/長期試験/fullsuiteは実行していない。

## English summary

Three bounded pairs confirmed equipped SELECTs falling from 3 to 1 and total SQL from 40 to 38, with identical complete state-response hashes. The prototype shared rows only within one real service response and never changed product code. Whole-service wall-time gains were not stable (paired differences +0.65, ~0, −0.24 ms); method CPU and SQL time are reported separately. Keep turn persistence ahead of BU-02 and defer implementation. Mutation freshness, lender/image boundaries, public-field restrictions and HTTP latency remain unverified by this probe.
