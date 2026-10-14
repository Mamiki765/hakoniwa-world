# 海底工作船 backend・API 引継ぎ

5.2.0 向けの Ruleset v37 draft。application version はこの作業では変更しない。v36 の payload・既存 migration・schema dump は保持する。UI、画像、目的地航行、ダメージ表示は別担当。

## 船と対象分類

`surface_ships.definitions.undersea_construction` は海底工作船。建造費 10,000 億円（1兆円）、HP1、1国1隻、視界1、移動石油10。`heading_only` で、武装・新維持費・新しい buff はない。港の必要性、燃料不足時の損傷、航行経験値等は既存共通処理に従う。

他国視界外と匿名 viewer には地図の船・船名・船 asset を返さない。他国視界内では船名も返し、自国には常時返す。通常船の公開表示は維持する。

## 画像

既存の `asset_key: ship.undersea_construction` は `AssetManifestResolver` で `ship-undersea-workboat.gif` へ解決する。採用Bの元GIF（32×32・不透明・1フレーム）はGit外のread-only tile asset directoryで管理し、他の船と同じ `/assets/hakoniwa-tiles/ship-undersea-workboat.gif?v=<mtime>-<size>` 形式で配信する。画像バイナリ・ホスト固有パスをdefinitionやGitへ追加せず、既存の存在・形式検査と欠落時fallbackを使う。

## 建設対象

対象は Ruleset の建設 command metadata に `construction_context: undersea` と明示した施設建設。現在は海底基地・海底都市・海底消防署。runtime はこの分類を参照し、施設名・海タイル・秘密表示だけから対象を広げない。将来の海底施設も通常建設 command に同じ分類と parameter schema を付けて共通経路へ接続する。石油探索・掘削・油田生成は対象外。

## API と型

既存 `POST /api/v1/nations/{nation}/map-spaces/{mapSpace}/command-queue` の通常建設 command を使う。

```json
{
  "command_key": "build_seabed_base",
  "target_x": 12,
  "target_y": 8,
  "quantity": 1,
  "parameters": { "construction_ship_id": 123 },
  "expected_version": 4,
  "request_key": "ea209746-d2a0-4cf0-ae3d-1fca7dd1473c"
}
```

`parameters.construction_ship_id?: number` は正の整数。省略時は通常建設。`target_ship_id` は既存の船廃棄用 field なので流用しない。ID を付けた場合、登録と現在条件の予測では指定船が選択マスにいることを確認し、実行時には World・所有・船種・生存を lock 下で再確認して、その時点の船の現在マスの真下へ建設する。移動だけでは失敗しない。沈没・不存在・他所有・非工作船なら通常建設へ fallback せず失敗する。

公式Turnは `development_commands` → `process_cells` の順。工作船建設は前者でその時点の位置を解決し、船の通常移動は後者で行う。同じTurnの通常移動先へ先に建設する処理や、追加の移動処理はない。予約待ちの間に以前のTurnで移動した場合は、移動後の位置を使う。

工作船経由では建設先を実行時の船の真下へ解決し、距離条件を置換する。地形、施設占有、所有、首都からの人口移住、資金はその実行先で通常どおり再判定し、既存の建設処理で通常費用の3倍を支払う。船は消費しない。ID なしの計画は予約座標と通常費用を維持し、船が同じ場所にいても通常建設のまま。掘削・油田探索等の非対象 command へ ID を添えると登録は422になる。

既存 `GET .../command-definitions` には任意の query `construction_ship_id=123` を追加できる。対象 command の通常の `cost_money`・`shortfall_money`・`execution_preview_status` に工作船経由の予測を反映する。対象 command の `parameters` にも ID の schema を返す。

既存 `GET /api/v1/nations/{nation}/ships` の各 `data[]` に次を返す。既存 field と `meta.ship_types[]` は維持する。

```ts
key: string;
undersea_construction: boolean;
construction_cost_multiplier: number;
```

所有者向け地図の `ship` にも `undersea_construction: boolean` と `movement_mode: string` を返す。UIは船種名・keyではなく能力flagで建設操作を選ぶ。他国向けはそれぞれfalse・nullとし、視界外で工作船自体を返さない条件は維持する。

保存済み計画の `effective_cost_money` は工作船の倍率を含む。指定船が移動・沈没しても価格表示は保持する。参照船は計画全体でまとめて取得する。工作船指定の queue API の `target_x` / `target_y` は null とし、`parameters.construction_ship_id` を対象として「工作船の真下」と表示する。登録時の座標は request identity と既存台帳のため保存するが、固定建設先として表示・実行・後続計画のマス状態予測に使用しない。船の将来位置は予測しない。成功・失敗ログの座標は実行時に解決した建設先を使い、指定船が無効なら座標を返さない。

画面の操作名は「真下に建築」。旧「実行ターンにこのマスにいないと失敗」の注意文は使わない。画面担当は `construction_ship_id` 付き計画を固定座標や「島全体」として表示せず、確認・計画・履歴を「工作船の真下」に揃える。

`meta.ship_types[].capacity` は種類別の実上限。工作船は1、既存船は従来の上限。建造 command の selector は既存 `quantity_options` から取得し、UI に番号を固定しない。

## 移行と境界

前方 migration は v37 を新規公開し、v36 の World と queued な地上命令の definition 参照を更新する。既存 Ship の作成 Ruleset、元 request Ruleset、fingerprint、過去命令、資産は保持する。World lock と未解決の次回 production Turn の拒否を維持する。DB の船同居制約は変更せず、種類別上限の override だけを追加する。

工作船撃沈のカルマ加算は0。施設破壊のカルマは変更しない。通常船の撃沈ルールも維持する。本番 migration・deploy・merge はこの backend 作業の範囲外。
