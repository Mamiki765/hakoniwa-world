# Secretaryの地上・地下ロック境界

4.4.0のロック隔離。User → Secretary → UndergroundProfileの所有関係とIDは維持する。

## 地上

`secretary_surface_states`はSecretaryと1:1で、`monster_experience`と
`equipment_version`の唯一の保存先。既存skills・itemsの所有FKは移動しない。
Secretary作成時に状態行を作成し、既存Secretaryは追加migrationで値を保持して移行する。
Nationの放棄・再作成によって状態行を削除しない。

経験値flush、item grant、怪獣drop、埋蔵宝回収、売却、装備変更、交易の出品・
配送・escrow返却は、item/skill更新前に地上状態行を排他取得する。
配送では移転元と移転先、flushでは対象Secretaryの状態行をID順で取得する。
既存のWorld／membership lockと未解決Turn guardは維持する。
APIの経験値・装備versionの意味は変えない。

## 地下と画像

認可はログインUserからSecretaryを解決し、そのSecretaryのprofileで直列化する。
profileの初回作成は既存unique FKと`firstOrCreate`の競合作成処理を使う。
既存profileに対するホーム、装備、starter補完、戦闘、貸出設定は親Secretaryを
排他の起点にしない。借用準備はprofileをSecretary ID順に取得した後、貸出設定・
装備・技能を採取し、戦闘transactionの前に借用元のlockを解放する。

画像には別の保護が必要なため、snapshot採取時のSecretary共有lockを残す。
これは画像差替え・削除と直列化し、地上状態更新やFKのKEY SHAREと共存する。
借用元は短い準備transactionで既存leaseを予約する。本人の画像は戦闘transaction内で
共有lockから既存lease登録まで保護する。戦闘確定時の昇格・期限後の削除契約は維持する。
発行済みvisitor codeはUserをlockせず読み、未発行時だけ既存の発行排他を使う。

日課・Pd・券残高は既存の進捗・残高rowの排他を維持する。Nation地下施設はNation所有のまま。

## 移行と確認境界

追加migrationは同じtransaction内で値をbackfillして旧2列を削除する。
旧runtimeと新schemaの混在運用は行わず、通常の停止を伴うrelease切替で適用する。
downは現在の地上状態値を旧列へ戻してから新テーブルを削除する。

既存の並行処理代表に前半item grantを接続し、地下profile保持中のflush完了、
地上lock保持中のPT FK登録、地上writer同士の直列化と加算保持を確認する。
追加の移行代表はidentity・地上資産・地下進行の保持を確認する。
Turn560のH1は原因候補であり、本番で確定した原因とは扱わない。Turnのretry policyは変更しない。
