# 5.1以降のDB問い合わせ調査

テストの固定期待値整理とは別の性能調査・差分。調査基準は5.0.1 `5affee61f180d6766ff7e59ce275c304c54002ad`、統合対象はrelease/5.2.0 `35c96749c078a20f855837544078372303ba6911`。main・本番・既存DBは変更していない。

全変更経路の静的台帳は[初回調査](post-5.0.1-nplus1-audit.md)と[PR262/267統合後の追記](post-5.0.1-nplus1-latest.md)。初回台帳の「未測定・未実装」はその時点の記録であり、以下を今回の最終状態とする。

## 実測して修正した箇所

`SurfaceShipTurnService::processCell()`で、目的地へ進めない軍艦は燃料確認前と攻撃前に同じactive Nationを`FOR UPDATE`で二度取得していた。成功してlockを取得したモデルだけを、その一回のprocessCell呼出内で攻撃へ引き渡す。別船・別Turnへ持ち越さず、nullの場合と燃料確認のない到着分岐は従来の攻撃用照会を行う。

間にある目的地解除・Ship保存・event記録はNationのstate/moneyを書き換えない。transaction、lock、船順、燃料、攻撃、本人向けevent、他人への秘匿は維持する。

| 確認 | 結果 | 条件・限界 |
|---|---|---|
| 既存blocked-last-step代表の前後SQL計測 | 対象Nationのactive/ID/`FOR UPDATE` SELECTが2→1 | 新規内部ネットワーク・tmpfs PostgreSQL18.4の隔離DBのみ。時間改善や全体SQL削減率を測定した結果ではない |
| 同じ計測代表の挙動保証 | before/after各1 test、11 assertions成功 | test追加なし。一時listenerはcommitしない |
| 最新release上の既存船目的地全件 | 8 tests、81 assertions成功 | PHP8.5.8、lockと同じsource dependency。資産・燃料・目的地・公開境界・schema型拒否を含む |
| source差分 | 1 runtimeファイル、5追加/4削除 | 設定・schema・migration・テスト本文は変更しない |

ローカル証拠は作業workspaceの`qa/nplus1-before-queries.json`、`nplus1-after-queries.json`、`performance-current-destination.log`。実在ユーザーデータは含まない。CIとCodex reviewはPRの現在headの結果を確認する。

## 残る候補

- PR262取り込み後の工作船queue予測/Turnにはdefinition.rulesetVersionの遅延ロードとShipの反復照会が残る。到達経路・件数増分・既読snapshotを安全に渡す最小案は追記台帳NQ-01/02に記載。今回は修正しない。readonly一覧の照会を共有すると同時変更の観測順が変わり得るため、mutation側のfresh lockを維持する設計を確認する。
- 陸地距離の同一target反復はPR260で改善済みの差分を確認した。未統合の同PRへ重複修正を加えない。
- queue圧縮の行ごとのUPDATEと探索船の宝箱再読取は既存由来。更新順・視界・途中取得状態を変える最適化は、実測と設計の判断待ち。

PR267の遷都追加経路に新しいSELECT N+1は見つからず、PR264/266はbackend差分0。これは対象変更経路の調査結果であり、全アプリの性能保証ではない。
