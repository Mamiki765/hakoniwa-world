# 基盤・モデル・入力検証の補完監査

固定SHA: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。2026-10-31。製品変更、DB操作、CI、認証情報閲覧、production/OCI操作なし。`AGENTS.md`を確認し、archiveは読んでいない。`.agents/skills`は親調査で存在しないことを確認済み。既存主経路レポートの補完であり、全入力の動的性能保証ではない。

## カバレッジ

[foundation-coverage.csv](foundation-coverage.csv) は対象 **276ファイル / 1,145 named methods** を1ファイル1行で記録する。内訳は Domain 155、Models 72、HTTP Requests 42、Middleware 4、Rules 2、Providers 1。全対象についてmethod・loop・直接DB/IOの構造を確認し、markerのあるhelperを本文とcallerで照合した。DTO/enum/例外/宣言的relationと、実際にread/writeするhelperを分けた。

評価は追加候補なし265、既修正確認2、他担当既報7、共通基盤の追加候補2。これは **静的構造の確認率** であり、276ファイルの全分岐を実行した率ではない。戦闘/Turnの巨大runtime helperは battle.md / turn.md の詳細調査へ接続し、二重に測定や効果計上をしない。CSVの `unverified` には全行で動的未検証範囲を明記した。

- モデルは属性/cast/relation/scopesが主体。relationを宣言しただけではSQLを実行しない。`Secretary::created` のsurfaceState INSERT、`lockSurfaceState` のSELECT FOR UPDATE、`UndergroundProfile::skillAllocationMap` の未読relation fallback GETを個別に確認した。呼出側のeager loadingやJSON castの反復は、API/command/turn/battleの経路台帳を正本にする。
- RequestはID/UUID・文字列・配列shape・最大件数の入力検証が主体。`UpdateNationProfileRequest::authorize` のmembership EXISTSは1回の認可であり、消す候補にはしない。地底のadmissionとprofile lock後の再確認も維持する。
- `WebImageMime` はupload1件につきfinfoとgetimagesizeで署名/寸法を確認する。受理条件を弱める速度改善は除外。画像全体をdecodeする重い変更を加える必要もない。
- `AppServiceProvider` はlock/resolver singletonとcanonical Turn 12phase、combat interface bind、CLI guard/OAuth listenerの登録。独立したShouldQueue JobやLaravel schedule登録はここにはない。cron wrapperとconsole起点はAPI/DB担当台帳を参照する。
- `RulesetAuthoringValidator` 3,636行は `RulesetPublisher` / `hakoniwa:ruleset:validate` のcold path。`CurrentRulesetGuard` は既読World/Ruleset identity比較だけで追加SELECTを行わない。全Ruleset validatorを一般player操作ごとに実行しているとは認定しない。

## 共通候補（静的。実測なし）

### FND-01: 船のparsed catalogを同じrequest内で再利用

根拠: `product/app/Domain/Ship/SurfaceShipCatalog.php:20` の `definitions()` は呼ぶたびに全D定義を検証しDTO化、sortし、selector重複検証をする。`definition():143` もこれを呼ぶ。`options():95` はruleset IDごとにmemo済みだが、direct `definitions()` はそのcacheを通らない。`product/app/Services/MapCellPresenter.php:260` の `ship()` が表示セル上の船ごとにdirect callするため、同じ版のS隻なら **O(S × D log D)** のcatalog構築を繰り返す。`UnderseaConstructionPolicy` のdirect callも同様だが、工作船SQL候補に既に含まれるので重複計上しない。

`SurfaceShipController::index:39` は **distinct rulesetごとのlocal memoが既にある**。このAPIを1隻1catalogとして数えない。Turnの`SurfaceShipTurnService:79`も定義群をbatch開始時に作るため、主な新規対象はMapCellPresenter。

最小案: canonical source/validatorはそのまま、request内で正しいimmutable Ruleset identityとpayloadに結びついたparsed mapを渡す。複数historic rulesetを同一mapへ混ぜない。変更範囲はcatalogとcallerのrequest context。期待効果は同じ版のcatalog構築 **S回→1回**、SQL数は不変。Dは小さいので優先度は低め。snapshotのraw値が差し替わるテストや未release draftでstaleにならない設計が必要。検証は同版複数船・異版混在・visibility秘匿・最大HP/name等の同一出力、構築呼出回数、代表chunkのCPU profileを比較する。Ruleset世代更新は原則不要。

### FND-02: immutable SecretaryItemCatalogの再構築を止める

根拠: `product/app/Domain/Secretary/SecretaryItemCatalog.php:103` の `definition()` は `definitions():166` を毎回呼び、全item配列を構築して rarity/synthesis等の派生を全件展開する（`:447`）。`categoryLimits():505` も全件走査中、display_limitを持つ各itemごと `maximumEquipped():483` →全item再構築+走査を行う。item数I、display対象item数Vなら **O((1+V)I)**、最悪O(I²)。出力はcategoryで上書きするが、同じcategoryの前の計算を再利用しない。Vは全Iと同じとは限らず、accessory/ticketは`:510`でskipされる。

`SecretaryItemGrantService:206`、`SecretaryEquipmentService:371`、`SecretarySynthesisService:245`等のitem loopにも同じ`definition()`がある。T01のvalidatedEffectCatalog CPU候補の下位で発生する分と、独立した付与/装備/合成の分を分け、T01の2ms/call等に効果を加算しない。

最小案: このクラスのconstant由来のdefinitionsをinstance内で一度作り、category limitもdistinct category単位で一回算定する。所有item状態やresolved rarity/economicsはcacheへ混ぜず、既存`definitionWithResolvedEconomics`の上書きを維持する。新しいdefinition authorityは作らない。変更範囲は同クラス内が中心。期待効果はcatalog構築の反復除去、SQL/RNG/ゲーム効果不変。array copy-on-writeとsubclass/test injectionの挙動は確認が必要。検証は全既存keyのcanonical出力、resolved economicsをもつitem、装備上限/合成保持上限、unknown key例外、呼出回数と250item倉庫表示等のCPUで行う。共通immutable parsed catalog群の候補に統合する。

## 反証・優先提案からの除外

- `RulesetVersion::settings` は生JSON文字列に対応するdecoded cacheを既に持つ（5.8.2）。再提案しない。
- `SurfaceCommandProjectionMemo` は current ruleset / land distance / visible neighbor / occupancy のrequest内cacheを既に持つ。memo境界をmutation前後で混ぜる案に広げない。
- `MapCellStateService::transitionTerrain:26` はFacility::findを持つが、主要のDomesticCommandExecutor:1071、DisasterTurnService:1529、MonsterTurnService:392等は直前に`setFacility(null)`を実行しfindへ入らない。一般的な「地形変更1件1余分SELECT」とは報告しない。他の部分分岐は未実測。
- `GridCoordinate::ring/radius` は(2r+1)²のsquare候補を作り距離filterする。ring直接列挙やcube変換の共通化は可能だが、現行主用途はr=1/2等、順序・境界・negative row parityを守る必要があり、今回高優先の候補にはしない。radius範囲のshape変更は不可。
- `NationEconomyCalculator` のlargest remainder配分はfacility数Fに対してO(F log F)、余り配分loopは人口数でなくF未満の端数個数。人口に比例する巨大loopとは認定しない。allocation順やtie-breakの変更は仕様変更になり得る。
- `WorldMutationLock`/`UserMembershipMutationLock` は再入depth memoが既にあり、World `assertHeld` はPDO identity比較のみ。lock削除・別接続へ切替えて継続・retry簡略化は候補外。
- authoring/config読込のPHP opcache・Laravel config cacheや本番worker構成は本番未観測。source上のrequireの存在だけで毎access全config再実行と認定しない。適用状態を断定しない。

## 未検証

この担当はstatic補完のみ。SQL listener、PG plan、CPU microbench、全入力分岐test、本番のworker/volume/filesystem/opcacheの観測は行っていない。時間改善値や全appの性能保証はない。主レポートの隔離測定と区別する。blocked/denied/pending操作なし。
