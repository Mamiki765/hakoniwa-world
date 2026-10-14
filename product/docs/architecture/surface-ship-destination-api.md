# 船の目的地指定 API（5.2.0 向け Draft）

既存の六方向進路指定に、海の座標を目的地として保存する即時操作を追加する。開発計画枠・資金・Turnは消費しない。UI担当への接続契約を本書にまとめる。frontend、新船、海底建設は対象外。application / Ruleset version、immutable snapshot はこの変更では更新しない。

## 操作とレスポンス

認証・CSRFは既存の進路変更と同じ。操作できるのは自国の現役船を持つ owner。membership mutation lock、world mutation lock、現行 Ruleset / 未解決次Turn guard、船の行ロックと楽観ロックを既存サービスで共用する。

```http
PATCH /api/v1/nations/{nation}/ships/{ship}/destination
Content-Type: application/json

{"destination":{"x":12,"y":8},"expected_version":3}
```

解除は `{"destination":null,"expected_version":4}`。`destination` は必須で、null または x・y の整数を両方持つオブジェクト。マップの現在の範囲内で、操作国に表示される地形が `sea` のマスを指定する。距離上限はない。視界外でも地図上で海と表示されるマスは指定できる。浅瀬・陸地・存在しない座標は拒否する。

目的地設定は既存の heading を解除する。既存の
`PATCH /api/v1/nations/{nation}/ships/{ship}/heading`
も利用でき、heading の設定・null 指定はいずれも目的地を解除する。同じ指示の再送は、version が一致すれば書換え・加算しない。古い version は同じ指示でも 409。

両操作とも成功時の形は共通。

```json
{"data":{"id":42,"heading":null,"destination":{"x":12,"y":8},"version":4}}
```

`heading`: 0〜5 または null（既存の方向定義）、`destination`: `{x,y}` または null、`version`: 正の整数。両方が同時に非 null にはならない。解除後は両方 null になり、船種の通常挙動に戻る。

自国船一覧 `GET /api/v1/nations/{nation}/ships` の各行に `destination` と `version` を追加する。所有者向け地図の `ship` は `movement_mode` も返し、選択中の船の移動方式を得るためだけの一覧再取得を省く。目的地候補を選ぶときと409の再取得では一覧を使う。所有者向け地図の `ship` も同じ値を返す。他国・匿名向け地図では heading・destination・version を全て null にする。mutation のレスポンスで手元の船情報を置き換え、409 時は一覧を再取得してから再操作する。

| status | 条件 |
| --- | --- |
| 401 | 未認証 |
| 403 | 自国 owner でない |
| 404 | route binding の島・船が存在しない |
| 409 | 船 version 不一致、world lock 競合、未解決次Turn、Ruleset reset 必須。reset 必須時のみ既存 `code` も返る |
| 422 | 不完全・不正な入力、海以外・範囲外、現役でない島、自国現役船でない |

422 の目的地エラーは一般的な海マス指定メッセージとする。施設の有無・所有者・機密施設の種類を拒否理由へ含めない。秘匿海底施設を含む判定は `MapCellPresenter::visibleState` に揃える。目的地として受け付けても、そのマスへの実際の通行は既存の船移動条件で判定する。

## Turn中の移動と終了

- 既存の船処理順・1 Turn 最大1マス・通行条件・石油消費・移動報酬・秘書経験値・戦艦攻撃を共用する。
- 指示中は、通行可能な六近傍のうち目的地との六角距離が最も小さい候補を選ぶ。同距離なら既存の宝追跡と同じ cell ID 順。現在位置より厳密に近づく場合のみ、目的地への移動とする。経路探索・迂回・同距離の横移動はしない。
- 近づける候補がなければ、その時点の位置で指示を `blocked` として終了する。最後の1マスが船などで塞がれている場合も同じ。隣接を到着として扱わない。heading_only は停止し、通常自動移動の船は既存の進路閉塞時と同じ通常移動へ戻る。自動移動可能なら同じTurn内で通常移動を行えるが、合計は最大1マス。
- 実際に目的地へ移動した場合、または指示地点が現在のマスそのものである場合だけ `arrived` として共通の終了処理を通す。到着Turnに通常移動を追加しない。
- 港なし・燃料不足による停止では指示を保持する。既存の燃料不足ダメージ・沈没条件は維持する。閉塞で移動候補がない船へ新たに燃料不足ダメージを追加しない。
- 指示先が海でなくなった場合は `terrain_changed` で解除する。港なし・燃料不足で停止している間は解除を保留する。解除後は進路閉塞と同じ通常行動へ戻る。
- 探索船は指示中に宝へ寄り道しない。通過先のマスにある宝は既存処理で回収する。指示終了後は通常の宝追跡・自動移動へ戻る（閉塞解除と同じTurnは既存のランダム移動、次Turnから宝追跡）。

目的地の自動終了は `ship.destination.ended` を自国限定で記録する。`reason` は `arrived` / `blocked` / `terrain_changed`。指定座標は自国限定 metadata に含め、他国・公開ログへ流さない。閉塞と地形変更の文言を到着とは分ける。即時の設定・解除は既存の進路操作と同じ admin audit に記録する。

## 永続化・確認範囲

`ships.destination_x / destination_y` の nullable な整数列を追加する forward-only migration。既存船の座標・進路・HP・資産・version・Ruleset provenance は変換しない。DB constraint で座標の片方だけの保存と heading との併存を禁止する。schema dump や既存 migration は変更しない。適用は別途 release 操作として扱う。

`SurfaceShipDestinationTest` は API の置換・解除・再送・競合・認可・秘匿、探索中の優先順位と宝回収、到着後の復帰、閉塞後の攻撃と自動移動、港・燃料不足、地形変更、既存船の追加 migration を代表する。既存の heading / 船移動テストも局所確認し、repository-wide 回帰は PR の exact-head Quality CI に委ねる。
