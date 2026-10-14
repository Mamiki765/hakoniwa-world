# 海底工作船 backend・API 引継ぎ

5.2.0 向けの Ruleset v37 draft。application version はこの作業では変更しない。v36 の payload・既存 migration・schema dump は保持する。UI、画像、目的地航行、ダメージ表示は別担当。

## 船と対象分類

`surface_ships.definitions.undersea_construction` は海底工作船。建造費 10,000 億円（1兆円）、HP1、1国1隻、視界1、移動石油10。`heading_only` で、武装・新維持費・新しい buff はない。港の必要性、燃料不足時の損傷、航行経験値等は既存共通処理に従う。

他国視界外と匿名 viewer には地図の船・船名・船 asset を返さない。他国視界内では船名も返し、自国には常時返す。通常船の公開表示は維持する。

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

`parameters.construction_ship_id?: number` は正の整数。省略時は通常建設。`target_ship_id` は既存の船廃棄用 field なので流用しない。ID を付けた場合、登録と予測で対象船を確認し、実行時にも World・所有・船種・生存・固定した対象座標との一致を lock 下で再確認する。船の移動・撃沈後に通常建設へ fallback せず失敗する。

工作船経由では距離条件のみを置換する。地形、施設占有、所有、首都からの人口移住、資金、通常の建設処理は既存経路を共有し、通常費用の3倍を支払う。船は消費しない。ID なしの計画は船が同じ場所にいても通常費用。掘削等の非対象 command へ ID を添えると登録は422になる。

既存 `GET .../command-definitions` には任意の query `construction_ship_id=123` を追加できる。対象 command の通常の `cost_money`・`shortfall_money`・`execution_preview_status` に工作船経由の予測を反映する。対象 command の `parameters` にも ID の schema を返す。

既存 `GET /api/v1/nations/{nation}/ships` の各 `data[]` に次を返す。既存 field と `meta.ship_types[]` は維持する。

```ts
key: string;
undersea_construction: boolean;
construction_cost_multiplier: number;
```

`meta.ship_types[].capacity` は種類別の実上限。工作船は1、既存船は従来の上限。建造 command の selector は既存 `quantity_options` から取得し、UI に番号を固定しない。

## 移行と境界

前方 migration は v37 を新規公開し、v36 の World と queued な地上命令の definition 参照を更新する。既存 Ship の作成 Ruleset、元 request Ruleset、fingerprint、過去命令、資産は保持する。World lock と未解決の次回 production Turn の拒否を維持する。DB の船同居制約は変更せず、種類別上限の override だけを追加する。

工作船撃沈のカルマ加算は0。施設破壊のカルマは変更しない。通常船の撃沈ルールも維持する。本番 migration・deploy・merge はこの backend 作業の範囲外。
