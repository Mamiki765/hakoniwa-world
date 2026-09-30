# 現在地：main 4.5.1 / release/4.6.0 機能scope完了・release closure待ち

更新：2026-09-29。GitHub確認、Owner判断、実装済み、構想、production証拠を分離する。

## 開発の固定点

- 開発正本：`Mamiki765/hakoniwa-world` / GitHub。
- main：`8b65d320a97caa0ca99735fc20445592f95a9c84`（4.5.1）。production適用の証拠ではない。
- `release/4.6.0` はmainより先行し、4.6の装備倍率/rating基盤と狩場4「夕凪の帰港地」まで取り込み済み。
- PR #171「feat: 4.6装備倍率・ratingと共鳴結晶個別売却」は `release/4.6.0` へmerge済み。実装HEAD `c61553b0b5227cbf9cfc0c83f38c5431ba02cfe5` のQuality `36428096913` は16/16 PHPUnit shardを含め成功。
- PR #172「狩場4『夕凪の帰港地』と宝物庫を追加」は `release/4.6.0` へmerge済み。最終HEAD `10251c256feadf2d3aad3f706707784f08979ef0`。初回HEADのFull CIでは16/16 PHPUnit shard、backend static、documentationが成功し、frontendは旧固定表示額assertionだけで失敗。最終HEADではそのassertionと新規balance固定値assertionを除去し、focused確認を実施。Owner指示により最終HEADのFull CIはskipした。
- PR #173「Set application version to 4.6.0」は `release/4.6.0` へmerge済み（merge `158fb34ed1cf37b0dfd2b496e1cc6d33d5dffb48`）。`application_version` は4.6.0へ更新済み。4.6.0の機能scopeは閉じ、残るのは累積独立review、release最終HEADのFull CI、`release/4.6.0 → main` PRというrelease closure作業。
- handoff / current-status / Ownerの会話で決まった未実装案の整理はChat側が担当する。CodexはOwnerから個別に明示された場合を除き、これらの判断を推測して更新しない。

## Productionの証拠

今回の4.6作業ではproduction DB・health・稼働versionを再照会していない。repository main / releaseの状態をproduction適用済みと読み替えない。

このhandoffが引き継いだ最後のproduction証拠は、2026-09-27 06:31 JSTのOwner提示静止画の **ver 4.4.0 / T628 / 正常**（24島、総人口10,761,185人）。2026-09-23のOwner提示ログには4.4.0切替、統合migration batch43、Ruleset v27 / DB ID42、T588のcron完走、予定起点設定がある。詳細は[deployment status](../operations/current-deployment.md)。これらをやり直さない。

30日receipt purgeの運用開始は未確認。保全とOwnerの別承認が先で、詳細戦闘ログ1時間保持とは別。

## 4.5.xまでmainへ入っているもの

4.4.1の日課modal・初回輝石通知・スキップ選択・Bahamul初勝利/trophy・表示改善、4.5.0の試練3「天光の王城」・報酬・140SP技能・10Pd即時再振り、4.5.1のskill investment gate撤去までmainへ入っている。4.5.0資料の96SP gateをcurrent制約として再導入しない。

## 4.6.0 実装済みscope

### 装備倍率 / rating

現行仕様は[装備倍率とrating](../architecture/underground-equipment-scaling.md)、プレイヤー向けは[装備手引き](../manual/equipment.md)。

- IL200基準で超過10ILごとに1.1倍。IL210=1.10、IL220=1.21。
- 武器は攻撃・固定回復/障壁等の世代倍率、防具はHP倍率へ接続。
- rating対象affixは必要ratingに対して実効値を投影する。固定能力・固定割合のunique effectと区別する。
- IL210バハ武器は維持。IL210黒竜共鳴結晶も保存rollから共通rating倍率×1.1で再投影し、固定unique %は一律倍率化しない。
- 最大HP affixはそのitem自身のILで一度だけ加算し、防具倍率との二重scaleを避ける。
- 防御系ratingの実効上限90%。既存の別系統capを置換しない。
- 研磨は従来の固定能力+30%/段階、+5上限、費用を維持し、percentage系は1段階+0.1 percentage point相当をratingへ投影。
- 共鳴結晶の確認付き個別売却を既存単品売却APIへ接続。
- 借用snapshot、覚醒、戦闘、balance manifest、UIの武器力/HP倍率/rating表示へ接続済み。

防具の「高ILで物防型 / 魔防型へ分ける」案は、Ownerが2026-09-29時点で見送る方向。active TODOへ戻さない。研磨量の量的再設計とグラム表示整理は将来候補で、4.6.0 release blockerではない。

### 狩場4「夕凪の帰港地」

PR #172で実装済み。

- Trial3初回clear後に解禁。Lv700は戦闘設計の入口目安で、runtimeの強制入場Lv制限ではない。
- 通常11種 + エリート4種 + レア1種。
- レアは**クリスタル・ドルフィン**。過去の宝石系レアと同様、「出たらラッキー」の幸運枠。1%独立抽選。
- 通常weightは雑魚8,000 + エリート2,000。通常XP期待値3,135 / 戦。
- クリスタル・ドルフィン25,500XP込みの設計期待値は3,358.65XP / 戦。狩場3約844.30XP / 戦の約3.98倍。
- 装備dropは既存4.6 generatorを使用。通常敵IL181〜210、エリートIL205〜220、レアIL215〜220。
- 専用「夕凪の帰港地の鍵」と対応する宝物庫を追加。レアは歪んだ輝石1個と専用鍵1個を付与。
- 欠片の通常weight込み期待値816.41G / 勝、レア込み826.24G / 勝。宝物庫基本750G、財宝20倍。
- 敵画像は未追加。既存の画像なしfallbackを使う。
- Trial4、白い大井戸の進行、緑の粘液の村跡storyは未実装。

戦闘測定の詳細は[夕凪の帰港地balance記録](../releases/4.6.0-yunagi-harbor-balance.md)。Lv700・IL180の現行weight抽選64 seedで49/64勝、Lv700・IL220で64/64勝。エリート4体同時は入口装備では壁、更新後に攻略可能という測定。クリスタル・ドルフィンは幸運枠なので罠敵化しない。

## Test方針

Ownerの現行方針：

- configやbalance値をtest側へ同じ数値で写し、「数値を変えたら期待値も変えてgreen」にするtestを増やさない。
- catalog値そのものではなく、到達可能なruntime operation、failure、settlement、ownership、idempotency、retry、migration、実データ破損防止などのcontractを守る。
- 表示全文、catalog件数、敵種類数等の偶然の固定を仕様testにしない。
- 全IL×全build×全seed matrixを恒久suiteへ持ち込まない。balance計測はrelease記録と必要な代表測定へ分ける。
- PR #172では、固定表示額assertionと新規のXP/weight/IL固定値写経assertionを削除し、catalog→runtimeの接続、鍵、duplicate retry、drop generation等の意味ある保証へ縮めた。

## 4.6.0 release closure

新機能はこれ以上追加しない。

残作業：

1. main...release/4.6.0の累積差分を独立review。
2. P0/P1/P2があれば必要最小限で修正。
3. release最終HEADで正式Full CIを1回実行し、exact-head greenを確認。
4. `release/4.6.0 → main` のrelease PRを作成。
5. Owner確認後にmerge判断。production deployは別操作。

Trial4、市場、転生、地上産業、Relic/合成、研磨量再設計等をclosureへ混ぜない。

## 持ち越し / 次の構想

| 項目 | 現在の扱い |
|---|---|
| 試練4 | 夕凪の帰港地の白い大井戸の先。緑の粘液に溶けた旧前線村跡と、案内人/スライム娘の過去へ接続する構想。案内人の反応・台詞はOwnerが決める。 |
| 装備売買 | 「剣が欲しいのに杖が出る」問題への対応。方式・通貨・条件・公開時期をUG-05のgateに沿って決める。鉱石/素材市場案は一旦保留。 |
| 転生 | 上限・成長力・必要XP、節目のアクセ枠/技枠拡張。値・既存高Lv者の扱い・公開時期は未決。 |
| 地上item | [地上アイテム拡張メモ](../plans/surface-item-expansion.md)。Relic、固定recipe合成、同系統育成、夢魔の三紋章、ticket圧縮等。 |
| 地上産業 | [地上発展拡張メモ](../plans/surface-development-expansion.md)。配置synergy、電力、二次/三次産業、属性魔力。地下攻略必須にはしない。 |
| UI / 秘書 | 貸出コメント、用途別AI/技保存set、PC/mobile header画像、小画面footer等。 |
| UI bug：首都地下の選択詳細 | **要修正**。dark themeで`.underground-map-detail`が淡い背景なのに文字色を明示せず継承し、「選択中 / 空き施設枠 / 階層 / 座標」がほぼ読めない。2026-09-30 Owner screenshotで確認。背景または前景色をtheme-awareにして十分なcontrastを確保する。値やDOM全文を固定するtestではなく、必要ならclass/style contractを最小限確認する。 |
| グラム表示 | 記念品の説明を武器力・固有効果風に整理する案。装備不可・売却不可の契約を勝手に変えない。 |
| 長期案 | 共有大討伐、深層探索、秘書になりきるchat等。採用済みTODOではない。 |
| Turn C2 | C1の同一起動内40P01/40001 retryは実装済み。failed/blocked runを後続処理で安全に自律復旧するC2は未実装。Owner不在時に一時deadlockだけでWorldが永久停止しないことが目的。対象失敗、same run/turn/ruleset/seed、backoff、上限、auditを決めてから実装する。 |
| idea memo MCP | Chatの長いcontextへ埋もれる案/TODOを外部にappend/search/list/status管理する軽量MCP案。product runtimeではなく開発補助。idea / decided / implemented / deferred / rejected等の状態を持ち、repo正本と混同しない。 |
| 運用別件 | T01 MCP exporter、30日receipt purge / cron等。4.6 closureへ混ぜない。 |

## 設計上の境界

地底攻略の地上還元は**首都地下の施設群**。地底農場・地底工場等が基礎産業を補助することはあるが、地上の新産業・電力・配置synergy・属性魔力を地底攻略必須へ変更しない。

地上の配置・電力・上位産業・魔力は未実装構想。実装時にOwner判断を取り、汎用engineやschemaを先行して作らない。

既存の[current-contracts](current-contracts.md)、[予定側](../plans/post-4.4.0-decisions-and-ideas.md)、[open-questions](../../../docs/open-questions.md)、[文書入口](../../../docs/README.md)から対象だけ読む。archiveは別のOwner指示なしに読込対象へ戻さない。
