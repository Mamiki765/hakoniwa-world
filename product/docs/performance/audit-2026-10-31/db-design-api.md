# DB再設計の比較補遺：履歴・read model・市場

対象固定SHA: **`2e93ec918774ad64d787aba99fc59a7bc3e54d16`**、5.8.3。2026-10-31。初回[API・DB調査](api-db.md)と[96テーブル索引台帳](api-db-indexes.csv)に対する設計比較である。採用・実装・migration・本番反映を決めた文書ではない。今回追加した作業はsource/schema/現役設計書の静的読取だけで、新しいDB、試験、benchmark、本番/OCI照会、認証情報の閲覧は行っていない。

最も見込みがある順は、①既存snapshotを正本にしたSQL検索列/索引、②canonical計算を再利用する同期read model、③履歴の小さいidentityと大きいpayloadの分離、④必要サイズに到達してからのpartitionである。全receipt統合・全面event sourcing・別DBへの即時分割は比較対象に含めるが、現状の静的証拠だけでは採用を支持しない。

## 1. 判断に使った現行の境界

以下のE番号は本文中の根拠参照。リンク先は全て固定SHA。

| ID | 現行source/制約 | 再設計で意味を保つための条件 |
|---|---|---|
| E01 | [audit schema:698](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L698)、[索引:6038](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L6038)、[管理再送:182](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/AdminOperationsService.php#L182) | auditは表示履歴だけではない。`admin.operation_completed`のJSON request_idにglobal unique部分索引があり、保存resultの再送を支える。単純な期限削除/移動で消せない |
| E02 | [TurnEventRecorder:70](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TurnEventRecorder.php#L70)、[PlayerIslandEventService:235](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PlayerIslandEventService.php#L235)、[公開帰属:661](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PlayerIslandEventService.php#L661) | World/turn/Nation/visibility等の列と、eventごとのmetadata/host attribution/legacy fallbackの両方を使う。単なるvisibility別tableでは公開先を決められない |
| E03 | [routine compact:292](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/RoutineAuditCompactor.php#L292)、[保持runbook:16](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/docs/operations/receipt-audit-retention.md#L16) | routine限定allowlist・同じTurnRun帰属・正確な削除数とsummary保存を維持。全audit削除/全期間再構築を既存契約と見なさない |
| E04 | [battle schema:2857](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L2857)、[unique:5501](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L5501)、[FK:7355](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L7355) | PK(id)、unique(profile,request_id)、unique(party_id)、result/XP/残高/seed/checkと、貸出参加のRESTRICT等を維持。IDを取り直すmigrationは不適切 |
| E05 | [log schema:2823](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L2823)、[log unique:5493](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L5493)、[snapshot縮約:41](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundBattleStorage.php#L41) | 大きいactions/presentationは既に別tableで1時間保持。battleの恒久compact snapshotと同一保持条件へ統合しない |
| E06 | [receipt stream:263](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundReceiptRollupService.php#L263)、[rollup schema:3292](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L3292)、[sequence:347](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundReceiptRollupService.php#L347)、[purge:32](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundReceiptPurgeService.php#L32) | battle/skip/bulk_skip/introの独立ID系列、profile lock、verified/deletedの連続prefix、30日、初回事実、trial/story/image pinを維持。source sequenceはCACHE1/ascending/noncyclingを検査している |
| E07 | [replay:3624](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundRuntimeService.php#L3624)、[cross-operation:4028](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundRuntimeService.php#L4028)、[受付:13](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/docs/architecture/market-synthesis-api.md#L13) | 同じrequest/fingerprintの保存結果を返す。受付期限後は既存結果の読取だけ。receiptをcoldへ移してmiss扱い→再決算することを許さない |
| E08 | [ランキング:149](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/PublicWorldService.php#L149)、[基礎集計:97](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/NationBasicStatusProjection.php#L97)、[land aggregate:33](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Domain/Map/NationLandAreaCalculator.php#L33) | population→territory→idのrank順、public money bucket、現行capacity resolver/地下bonus/land定義を共有する |
| E09 | [Turn集計:793](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CompleteTurnEngine.php#L793)、[最終処理:1155](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CompleteTurnEngine.php#L1155)、[monster snapshot:25](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/WorldMonsterCountSnapshot.php#L25)、[snapshot時点の契約](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/docs/architecture/public-monster-counts.md#L70) | monster countは明示的に最終Turn snapshot。その他ランキング値まで同じ遅延契約とは限らない。aggregate_nations後にも売却/資産/最終lifecycleがある |
| E10 | [chunk:30](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/MapChunkService.php#L30)、[視界:21](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SurfaceVisibilityService.php#L21)、[表現hash:137](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/MapChunkService.php#L137) | chunk cellだけでなく本人の周辺土地/船、秘書人口上限、地下bonus、宝、休眠首都/World/Rulesetに依存。versionは最終公開配列のhash |
| E11 | [図鑑登録:23](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CollectionRegistrar.php#L23)、[count/claim:115](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/SecretaryCollectionService.php#L115)、[schema:13](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/migrations/2026_10_21_000000_add_user_collections.php#L13) | 発見unique(user,kind,key)、bonus unique(user,milestone)、現在catalog membership、未発見秘匿、claimのPd ledger/pay-onceを維持。秘書でなくUser所有 |
| E12 | [地底市場schema:42](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/migrations/2026_10_18_000000_add_market_synthesis_and_warehouse_expansion.php#L42)、[一覧:30](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L30)、[出品:135](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L135)、[購入:150](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundMarketService.php#L150) | item_snapshot/seller_snapshotが履歴表示を支える。price/fee/proceeds/status/所有者はtyped列、金額checkとactive equipment uniqueあり。購入はlive品物定義/所有/残高/容量をprofile→listing→equipment lock下で再検証 |
| E13 | [地上競売schema:640](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L640)、[active索引:6017](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/database/schema/pgsql-schema.sql#L6017)、[一覧:36](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TradingPostService.php#L36)、[表示:554](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TradingPostService.php#L554) | Nation/World・資金/資源・bid/返金/公式Turn決済。品物key/level/provenanceを持ち、表示定義/effectは現在resolverから解決する。地底の出品snapshotとは契約が異なる |

## 2. 履歴tableの分離・統合・partition・archive

### H1 小改修：現在のtable境界でrow数・列数・索引を絞る

AP-09のJSON一度解読、AP-05のpin batch、AP-12のaggregate scan統合、battle履歴一覧の必要列選択から始める。battle一覧は既にlimit20/最大100だが`get()`は全列である（[RuntimeService:1220](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/Underground/UndergroundRuntimeService.php#L1220)）。projectionの実使用列を確認してからsnapshot未取得を選び、detail/replay pathは全必要payloadを読む。

期待する利益はnetwork/decode/roundtrip削減であり、table分割なし。auditのworld/turn/visibility・receiptの(profile,id)・battleの(profile,finished_at)は既にある。major-newsのtype/並び順、期限log、market filterに合うpartial/composite/expression indexはplanで行読取が多い場合に限る。indexを増やすほど全Turn INSERT、WAL、vacuum、backupの費用が増える。単に96tableを減らすことには性能上の根拠がない。

移行は新索引/必要列readだけで済み、現行ID・FK・JSONを変えない。索引作成/削除のlocking・失敗時invalid indexの扱いを別途測る。旧readerへ戻しても正本はそのままで、最も戻しやすい。

### H2 段階再設計：receipt identityをhot、snapshot/detailをcoldへ分離

比較案は、既存`underground_battles`を小さいcoreとして残し、頻繁にreadされないcompact snapshotまたは一部大payloadだけを`battle_payload(battle_id PK/FK, format_version, payload)`へ1:1分離する形。既存actions/log分離(E05)を消す案ではない。coreはprofile/request/fingerprint/runtime/result/seed/統計/資産before-after/既存IDとuniqueを保持し、replay時のみ必要payloadをjoinする。

期待はcore working setの縮小と`SELECT *`による不要転送の削減。ただしPostgreSQLは大きい属性を既に圧縮/外部格納するTOASTを持つため、JSONを別tableへ置くだけで同率のheap/I/O改善が出るとは限らない。narrow SELECTで避けられる費用を先に測る。[PostgreSQL 18 TOAST](https://www.postgresql.org/docs/18/storage-toast.html)

代償はdetail/replayのjoin、2行書込、FK/row有無検証、compactionの複数table transaction。JSON完全一致だけでなく、compact前後の必要事実・統計version・unknown/null・image pinを保つ。private seedの露出を増やさない。snapshotの正本は新旧同時に2つ作らず、切替段階に応じ一方だけがauthoritative、他方は完全な派生copyとして扱う。

移行は既存battle IDのままbackfillし、coreとpayloadの1:1件数/hash/必要fieldを検証。書込は同じprofile transaction内でcopyを更新し、そのfailureは決算ごとrollbackする。移行期間のreaderは明示的`payload_migrated_version`に応じ正本を一箇所に選ぶ。単純「新table missなら旧へfallback」は、欠損を正常な未移行と混同するので不可。旧snapshotを保持している間だけ旧readへ即時rollbackできる。旧列を削除した後のrollbackには逆copy/互換adapterが必要で、`down()`で消して戻す案にはしない。

### H3 段階〜抜本：auditをtyped identity・routingと大payloadへ分ける

E01のadmin receiptをまずtypedな`admin_operation_receipts(request_id UNIQUE, actor/world/operation, result, source_audit_id)`へ論理抽出する案を比較できる。audit eventは現在と同じ監査として残す。両方を同じtransactionで書き、再送判定のauthorityを一つに切り替える。これにより、その後のaudit物理分割がadmin再送の唯一性に直接依存しなくなる。今はE01のglobal uniqueがその責務を担っており、いきなり消してはいけない。

さらに`audit_event_core(id, world/turn/nation, event_type, subject, visibility, occurred_at, payload_ref)`と`audit_event_payload`へ分ける選択肢がある。軽いtimeline/major-newsのindex working setを小さくできる反面、player projectionはmetadataのhost/target/reward/名前fallbackが必要(E02)なので、結局多くのeventでjoinする。visibility別独立tableへ直接書分ける方式は、host帰属/本人public詳細/公開事実を別のrules authorityにしてしまう危険が高い。

安全な段階案は「raw event identityは一つ」「typed routing列は既存projectorが計算する派生列」「public/private read modelはrow-localの認可情報を必ず保持」。canonical eventを削って安全そうな公開文だけ残す案は、診断や本人表示を失うので採らない。raw+public両方を読むtimelineが二重表示しないようsource_event_idをuniqueにし、結果のorderは現在turn/idを保つ。

効果があるかはevent type別bytes、timelineが必要とするmetadata割合、audit INSERT indexes/WAL、join増分を測るまで不明。新header/receipt抽出とpartitionを同releaseで一括実装するのは切戻しが難しく、別段階に分ける。

### H4 大規模時のpartition：どのキーを選ぶか

| 対象/キー案 | 利点の条件 | 現行制約との衝突・反証 |
|---|---|---|
| audit range(occurred_at)月次 | 日付範囲の管理操作・保存先移動、hot index縮小。全partition保管なら履歴保持を変えない | player APIは主にworld+turn範囲で、occurred_at条件がないため自動pruneできない。auth/admin等turn/world NULLもある。PK(id)とadmin request global uniqueがpartition keyを含まない |
| audit range(turn)＋world | player turnページがpartitionを絞れる。World毎の巨大差に対応 | auth/profile等Turn外event、未設定/NULL、複数Worldのturn番号重複、同じidのglobal扱いに対応が必要。world×turnの細分化はpartition数を増やす |
| battle range(finished_at) | retained統計range/物理cold移動と合う | unique(profile,request_id)、party unique、PKid/多数FK(E04)が単純に維持できない。replayはprofile+requestで全時期を探す |
| receipt hash(profile_id) | 一人のjournal/lock/prefix検索を一partitionへ、unique(profile,request)と相性 | 現PKidのglobal FKやstream別IDを保つdirectory設計が要る。時間で古いpartitionをまとめて落とせない。現単一Worldの負荷には過剰の可能性 |
| battle log range(expires_at) | TTL詳細payloadの物理整理に最も合う | unique(battle_id)とPKidのscopeが変わる。lease延長でpartition移動、未期限切れ/pin混在。current log 1時間保持ならそもそも十分小さい可能性 |
| payloadのみrange(date/ID帯) | hot coreのglobal identity/unique/FKを残したままcold payload運用 | payload lookup用directoryとcutoffが必要。payload欠損時fail/restoreを設計。現在のreceipt purge境界と別の削除authorityを作らない |

PostgreSQL 18のpartitioned tableのPK/UNIQUEはpartition key全列を含める必要がある。現tableをそのまま月次化し、同じglobal uniqueが残るとは扱えない。普通のtableを直接partitionedへ変換する操作もなく、新構造への移行/attachを設計する必要がある。[PostgreSQL 18 partitioning](https://www.postgresql.org/docs/18/ddl-partitioning.html)

最もriskが低い抜本比較は**小さいglobal directoryを維持し、重いpayload側だけpartition**する案。audit directoryはrequest再送、battle coreは既存FK先を保つ。すべてのpartitionを通常queryで見える同じ論理relationに保つ段階なら、物理分割だけでゲーム上の保持を変えずに比較できる。partition数増大によるplanning/maintenanceの悪化も測る。

### H5 Archive/別storeと全面統合の比較

「別schema/DBへcold copy」と「現在のqueryから切り離して見えなくする」は別操作。後者は今も読める本人history/replay/管理証拠を減らす。runbook(E03/E06)の30日や1時間を、そのまま全table archive許可へ拡張しない。

物理cold化するなら、source identity・namespace・format/source version・行数・hash・元FK/provenanceをmanifest化し、copy検証後に原子的なread locationを切り替える。replay lookupはcoldにある事実を必ず検出し、結果が一時取得不能なら処理を停止する。missを新要求と扱わない。cold outage時の読取/管理照会のSLO、restore所要時間、backup/暗号化/検証、sourceとarchiveが同時に見える時のdedupが追加費用になる。二つのDBへの非原子的な「両方write」は不可。同じDB transactionでdirectoryとoutboxを確定しても、remote copy完了までは元payloadを保持する。

一方、battle/skip/bulk/intro/market/synthesisを全て巨大`operation_receipts`へ統合する案は、共通request検索・metrics UNIONを減らせるが、owner単位(User/Secretary/Profile/Nation)、request_id/request_key、collision禁止の組合せ、trial/party、終了条件、保持、FK、checkが異なる。nullable汎用列とpolymorphic ownerだけでは今のDB制約が弱くなる。共通の**検索用view/派生directory**から始め、各canonical tableを残すほうが段階的である。統合する場合もnamespaceと既存IDを保存し、E07のcollision matrixを明示的に移す。統計のSQLが4stream UNIONであるだけなら、table統合費用を正当化するほどの証拠はない。

全面event sourcingへ変えて現在stateを全部auditから作り直す案も比較上は可能だが、現auditは完全な再生journalではなく、許可されたroutine集約/欠損legacy/unknown統計を含む。現在stateを捨てて全履歴再生で移すことは証明できない。採るなら将来境界の完全state snapshot＋versioned command/event protocolが必要で、性能改善としてのrisk/複雑さは大きい。同じコード版・初期state・seedの再現性を保つことと、過去全eventからstateを復元できることを混同しない。

## 3. ranking/status/visibility/collectionの非正規化

共通の損益条件は、read回数Rと現行計算費Crに対し、`R×Cr`が新write回数Uと維持費Cu＋安価なlookup費の合計を十分上回ること。writerもreaderも小さい場合、materializationは速くしない。以下は独立した比較で、全部のread modelを一括導入する提案ではない。

### R1 ranking/status

| 段階 | 仕組み | 効果と反証 | 整合性・戻し方 |
|---|---|---|---|
| 小 | 既存canonical計算を使うSQL GROUP BY、必要列select、request-local World/Ruleset再利用（AP-04/07） | 所有cell二重hydrateを削減、SQL数/bytes/CPUを下げる。schema/継続同期費用なし | 旧queryへ即時戻せる。resolverの丸め/例外/地下bonusを共有 |
| 段階 | `nation_public_status(nation_id PK, source_revision, population/territory/land/capacity等)`を同transactionで置換/dirty化。rank index候補はWorld/population DESC/territory DESC/id、対象stateのpartial条件を比較 | 読取をO(cells)→O(nations)。更新側の集計費・extra writes・row contentionが増える。asset/private詳細は別projection。stateを並び順列の前に置くと複数state全体のrank順に使えない場合がある | source_revision一致なら使い、欠損/dirtyならcanonical計算。switchでlive readへ戻す。derived rowsは捨てて再構築可能 |
| 抜本 | read専用DB/cacheに投影、World/ユーザー単位でrevision protocol | read負荷を分離できるが配送/lag/障害/backup/可視性の運用が増える。現在の即時read契約のままasync lagを返すことは不可 | commit revisionをreadが指定し、追いつかなければprimary fallback/待機。同一DB同期案を先に比較する |

現在monster countだけは時点付きsnapshot(E09)が正式契約。これをrank全項目へ広げると、登録/手動放棄/休止、profile、bidでのmoney escrow、売却/援助/アイテム変更等のTurn外反映が遅れる。**world.current_turnだけをrevisionにしてはいけない**。aggregate_nationsの中間値も、後続最終処理を終えたpublic statusとは異なる。採るなら既存canonical mutationが同transactionでrevisionを進め、必要項目のprojection/dirty markerを更新する。

SQL materialized viewはSQL定義を一元化できるが、通常refreshは全内容の置換であり、行の変更ごとのincremental維持ではない。`CONCURRENTLY`は適切なunique index等の条件があり、refreshは同じviewで同時に一つ、毎HTTPでrefreshする案は費用を移すだけである。boundedな同transaction派生tableとの比較が必要。[CREATE MATERIALIZED VIEW](https://www.postgresql.org/docs/18/sql-creatematerializedview.html)、[REFRESH MATERIALIZED VIEW](https://www.postgresql.org/docs/18/sql-refreshmaterializedview.html)

基礎statusの発生源はmap_cells/terrain/facility、nation_resources、地下施設、Secretary skills/equipment、Nation lifecycle/World turn。post-release ruleset変更時は現在の同じresolverで新revisionを生成する。SQL側へ別のcapacity formulaやlabel catalogを埋め込まない。projection_schema_versionは保存形式のversionであり第二のRulesetではない。

### R2 visibility/chunk

小改修は近傍source絞込とdormant保護座標set（AP-03）。段階案はsparseな`viewer/mapspace/chunk→256bit可視性mask`か最終chunk表現cache。本人の全World×全Nation×全cellを事前計算するとO(viewers×cells)へ膨らむため、表示されたchunk/近傍sourceだけを保存するほうが現状に合いやすい。

ただしchunk.versionだけではE10の依存を覆えない。視界sourceが隣chunkの土地や遠い船でも、対象chunkの見え方が変わる。本人skills/装備/地下bonus、休眠Capital/theme、Ruleset、weather、宝の可視性もversion依存に含める必要がある。`viewerNationId=null`の公開版と本人版を混ぜない。現在のprivate no-store/public max-age30/SWR60のHTTP契約も維持する。

最小の同期設計はcanonical writersから`visibility_source_revision`や`presentation_revision`を同transactionで進め、cache keyにview identity・全必要revisionを入れる。どこかが一致しなければ今のpresenterで再生成する。最終配列のhash(E10)を安価なcell versionだけに置換してよいという意味ではない。revisionの漏れは速さより重大で、hidden施設/owner情報漏洩と表示停止が起こる。

抜本の空間read service/別storeは、大量reader・多数Worldがあるときの比較対象。DBとstoreの更新が非原子的なためrevision/fallbackが必須。まず一般的なB-treeで近傍候補を絞る案と、offset-hexをcube座標へ派生して空間indexする案を比べる。hex厳密距離はcanonical GridCoordinateのまま、地理SRIDの距離へ置換しない。generated座標は派生値で、原座標を第二authorityにしない。

### R3 collection/badge

小改修はAP-06のsummary-only生成。現tableは既に「全戦闘/取得履歴」ではなくuniqueな発見事実に縮約されている(E11)。`user_id`先頭uniqueがあり、行数はUser×catalog entryに制限される。まず新しい永久counterを作らず、現在catalog key setとentries/claimed bonusから同じsummaryを求める。

段階案の`collection_summary(user_id, registered, unclaimed_entries, reached_bonus...)`は、登録INSERTが実際に一行増えたときだけ、claimが未claim→claimへ変わったときだけ同transaction更新する。backfillは既存rowの日付だけを古くする場合もあるので、upsert呼出回数を発見数として加算しない。User所有のまま維持し、転生/再登録でresetしない。

catalogから除外されたentryを数えない現在の条件、total/bonus閾値、未発見秘匿がある。cacheにはcanonical configから導くkey-set fingerprintを付け、変更時は再計算する。catalogを別の独自永続tableへコピーして新authorityにしない。claim/Pd付与は常に既存entries/bonus unique/ledgerを使い、summary counterを権限/残高/報酬の正本にしない。

抜本のevent-driven summaryはreaderが非常に多い場合だけ比較する。遅延でbadgeが変わることを現在契約のまま許容せず、同transactionまたはrevision一致のfallbackが必要。原entriesとclaimsを残せるので、summaryを無効化して旧計算に戻すrollbackは容易である。

## 4. 地上交易・地底市場：snapshotと検索列

| 比較点 | 現在の地上競売(E13) | 現在の地底露店(E12) | 意味保存する設計 |
|---|---|---|---|
| owner/資産/決済 | Nation/World、money/resource、入札と返金、公式Turn決済 | Profile/User永続資産、G、即決、後から売主受取 | 共通listing canonical tableへ統合しない。owner FK/lock/財源を混ぜない |
| 品物の履歴 | key/level、original instance ID、現在resolver由来の表示。NPC商品も存在 | item_snapshot/seller_snapshotが売却後/品物削除後も残る | JSON snapshotを現在の品物から再生成して上書きしない。地上へ古い名称snapshotを捏造しない |
| 検索 | active/world/ends_turn/idのtyped索引、関連eager、全activeを返す現API | active全件をPHP filter/sort後page、snapshot JSONのIL/category等を使う | 地底はquery pushdownと検索projectionが有力。地上pagination追加は現API変更なので別判断 |
| 正当性 | 一件最高bid unique、active escrow item unique、bid/product/status/turn checks | active equipment unique、money check、receipt unique、profile→listing→equipment lock | read projectionはactive予約/購入可否/支払い/受取を確定しない。writeは常にcanonical rowを再検証 |

### M1 小改修：snapshot JSONへSQL filter/ORDER/LIMIT＋式index

地底の現在filterはcategory/weapon_style/IL/quality/purchasable-only。canonical `item_snapshot`の同じfieldから式を作り、active rowをpartial indexで支える。priceとidは既存typed列を使う。欠損weapon_style、quality default0、IL数値型、同額/同ILのid降順を現PHPと一致させる。page_size+1でhas_moreを求めればCOUNTは不要。全filter組合せにindexを作るとwrite費用が増えるため、query頻度/selectivityを測って2〜3本程度の候補から比較する。

これは既存snapshotの意味をそのまま検索へ使う案で、新たなcatalog/snapshot生成器は作らない。DB側の型castエラーが現在表示可能なold snapshotを読めなくする可能性があるので、まず固定schemaの全保存形式を静的に確認し、migration前previewで対象を分類する。欠損値を黙って別ILへ補正しない。

### M2 段階：GENERATED/保存query列でsnapshotと一方向に結ぶ

例は `item_level_query`、`category_query`、`weapon_style_query`、`quality_percent_query`。**既存snapshotが正本**で、列はそこからのdeterministic projection。generated stored columnまたは式indexなら二重writeの競合を避けられる。PostgreSQL 18 generated expressionは同じrowのimmutable計算に限られ、他tableや現在catalog参照はできない。stored/virtualを明示して比較する。[PostgreSQL 18 generated columns](https://www.postgresql.org/docs/18/ddl-generated-columns.html)

生成式を安全に定義できない混在formatなら、同じcanonical snapshot projectorでtyped列を作り、snapshot hash/format versionと照合する方式を比較する。typed列だけを書換える管理経路は作らない。backfillはsnapshotを変えず、保存済事実だけから作る。sourceが不明/欠損の行は推測して埋めず、旧readerへ明示routeするか採用を止める。

期待はJSONの反復抽出/DB sortとfilter、PHP hydrationの削減。stored列は追加disk/WALがあり、listing数が少なければ式indexだけで十分。generated column追加は既存行の計算/rewriteやlockingが生じる選択肢なので、online無料とみなさない。readerを旧JSON式へ戻すrollbackが可能な間、snapshotを保持する。

### M3 抜本：現在出品のskinny table＋terminal history/receipt

現在active取引と履歴の増加を物理的に分ける案。小さい`listing_identity`にID/owner/asset reservationを残し、active state、terminal immutable record、payloadを分ける。地底ならsold後も未受取proceeds/expired seenが更新されるため、terminal全部がimmutableではない。売主受取stateは別のcanonical claim recordへ一意に持たせる必要がある。

active→sold時は同じprofile lock/transactionで資産移動・balance・terminal record・active予約解除・receiptを確定する。二つのtableに同じactive equipmentが入らない制約をdirectory上に残す。別tableそれぞれのpartial uniqueだけではcross-table一意性を守れない。cancel後のrelist、同時purchase/cancel、sold品のNPC売却後の履歴、同じrequest再送、全件売上受取を旧挙動と照合する。

地上もactive/historical分離は比較できるが、auction_bids FK、auto-relist、ends_turn、escrow容量/返金、Nation破棄制約、event/backfill readersが広く参照する。現在はactive部分索引が既にあり、物理分離しなくてもhot indexは小さい。source tableの大部分がcoldでheap/TOAST/vacuumが支配する証拠を得て初めて、物理分離がpartial indexより有利と言える。

二つの市場で再利用できるのは、採用済みの整数手取り計算のように**同じcontractがある純粋計算**や、検索projectionの抽出方針に限る。資産/receipt/lock/所有者/決済engineまで統合すれば高速になるという根拠はない。

## 5. 安全な移行とrollbackの共通手順

1. **authorityと対象を固定**する。元ID、namespace、owner、World/Ruleset/runtime identity、seed/fingerprint、null/unknown、保持/認可を列挙する。現schema dump/baselineは改変せずappend-only migrationを用意する。[CurrentDatabaseBaseline::assertStructure:110](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CurrentDatabaseBaseline.php#L110)は既知のextension marker/structure manifestを検査するため、新構造に対応する正規のextension/validator更新も範囲へ入れ、検査を緩めて迂回しない。production適用済baselineは別のOwner確認を要する。今回は候補設計だけで、その確認/操作はしていない。
2. **sourceを残したadditive構造**を作る。新query列/read model/payload tableのmissingと未移行を明確に区別する。unique/check/FKを緩めて通す案にしない。新index/tableのDDL lock/サイズ/temp/WAL/backup所要を隔離cloneで測る必要がある。
3. **keysetでbounded backfill**する。固定high-watermarkだけでは同じIDのUPDATEを捕捉できない。旧snapshot compaction/claim/status変化を含む全writerを同transactionのmirror/dirty revisionへ配線し、移行中のUPDATE/DELETEも記録する。writer障害時「本体だけ成功、projection失敗を無視」は認めない。projectionをbest-effortにするなら不一致revisionはreaderが必ずfallbackする。
4. **shadow readは利用者へ返さず比較**する。row set、order、null、public秘匿、snapshot/fingerprint、statistics scope、asset conservation、source/delete boundary、例外を比較する。数だけ一致しても十分ではない。二つのreaderの結果を混ぜて返さない。
5. **一つのread/write authorityへ切替**する。source revision/formatでrouteし、切替前後のin-flight requestとmanual retryを扱う。未解決Turn跨ぎ自動retryを追加しない。運用観測期間中は元sourceを保全する。cache/read modelなら即時無効化、payload移行なら旧reader互換を保持して切戻せるようにする。
6. **destructive cleanupは独立した後段**にする。元payloadを削除/partition detachしても、既存に読めたhistory/replayを読めること、保全manifest/復元が検証済みであることが必要。削除後は単なるコードrollbackでは戻らない。逆copy/再attach/restoreの所要時間と途中停止checkpointを用意する。

同transaction mirrorは二つの独立正本を許す意味ではない。元から一方向に導かれるshadowであり、移行完了後は片側のauthorityを明示的に終了する。新旧実装が別々に式を変更する期間を作らない。eventやoutboxのdelivery重複はsource identityのuniqueとidempotent projectionで吸収し、報酬付与は再実行しない。

## 6. 採否前に必要な測定と反証

今回の追加比較は全て未実測。初回調査の小fixture/CPU結果を、partition/read-model後の性能として流用しない。

| 問い | 必要な根拠 | 採らない判断の例 |
|---|---|---|
| 履歴のどこが重いか | table/index/TOAST bytes、event type/format別row width、増加率、hot/cold参照率、heap fetch/buffers、WAL/vacuum | JSONが大きくてもnarrow readが十分ならpayload分割不要 |
| partitionで絞れるか | 実SQLのpredicateとpartition pruning、partition数に対するplanning、replay lookup、insert/attach/retention lock時間 | player queryにpartition keyがなく全partition走査なら利益薄い |
| read modelが元を取れるか | read/write回数、現行集計CPU、更新追加SQL/lock待ち、catalog変更/転生/登録/売買等writer一覧、rebuild時間 | readが少ない・writerごとの再集計が重いならSQL集計のまま |
| cacheキーが完全か | 同じchunk/turnで変わる全source、revision漏れ、本人/public、別World、Ruleset/label変更、cold起動 | invalidationを証明できないならrequest-local cacheまで |
| market indexが効くか | active件数、filter selectivity、price/IL sort、snapshot形、取得row数、index追加後write/WAL | activeが少数なら新列/複合索引を増やさずSQL pushdownだけ |
| 移行/復旧に耐えるか | 同時旧UPDATE、backfill停止/再開、mirror失敗、partial commit、replay競合、source/cold unavailable、backup restore | 旧結果を読めず再決算しかできない設計は不採用 |

全件長期試験や本番負荷試験を直ちに要求するものではない。候補が選ばれた段階で、採否を分ける少数の代表fixture/plan/故障境界に絞って検証する。現時点の推奨は、小さい正当性保持のquery改善を先に計測し、R1/M2をその次の設計比較、H2/H3/H4/M3をサイズ/運用の証拠待ちにすることである。
