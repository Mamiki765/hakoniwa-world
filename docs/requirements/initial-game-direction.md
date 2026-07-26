# Initial game direction

この文書は実装前の初期要件です。現在は設計文書を確定する段階であり、ゲーム本体、施設master、巨大数libraryは実装しません。

## 最初のMVP縦切り

最初の実装は次に限定する。

```text
Laravel
→ PostgreSQL
→ Discord OAuth / Google OAuth
→ 1 Userへの複数identity連携
→ 共有地上World初期生成
→ Nation自動配置
→ Capitalと初期Territory生成
→ /api/v1のCapital周辺chunk API
→ Vue地図表示
```

turn、command queue、生産・消費、災害、missile、怪獣、国境侵食、防壁都市、休眠遷移Job、村の自然発生、緊急農場、研究・熟練度、追加resource、item、地下・宇宙、Mariachang通知はこの縦切りへ含めない。

## 認証と所有

- MVP providerはDiscord OAuthとGoogle OAuthとする。
- `users`をゲーム内accountの正本とし、外部主体は`auth_identities`へ分離する。
- 1つのUserへ複数identityを明示的に連携でき、連携済みのどちらからでも同じUserへloginできる。
- `(provider, provider_user_id)`を一意にし、同じ外部identityを複数Userへ関連付けない。
- providerのメールアドレス一致だけでは既存Userへ自動統合しない。
- 未登録identityの初回loginでは、新しいUserとAuthIdentityを同じtransactionで作成する。
- 既存Userへの2つ目のprovider追加は、login済み状態からの明示的なlink操作だけで行う。
- Identityが1個しかない場合、最後のlogin手段を解除できない。
- Userは認証account、NationはWorld内のゲーム主体として分離し、Discord IDやGoogle IDをNation所有者IDへ使わない。
- 具体的なLaravel OAuth package、Sanctum、配信origin、redirect UXは`docs/open-questions.md`の指定時点までに決める。

## 画像素材

- 箱庭諸島2＋に存在する既存地形・施設・怪獣等は、ローカル保有の原GIFを新作UIで使用する。
- 原GIFはGitへ収録せず、原名・原形式のままGit外へ配置し、実行環境から読み取り専用で参照する。
- definitionは絶対パスやファイル名ではなく論理`asset_key`を持ち、manifestが原ファイル名へ解決する。
- 原作にない首都、防壁都市、大学、研究所、新資源、隕石item、地下・宇宙施設へ既存GIFを流用しない。placeholderを経て新規画像を追加可能にする。
- 画像欠落時はCSSと短い名称・IDで代替し、壊れた画像アイコンを出さず、ゲーム処理を停止しない。
- Creditsへ字・原作、画像、題字、原配布元を表示し、`THIRD_PARTY_NOTICES.md`と一致させる。

## 共通世界

- 地上は全プレイヤー共通マップとする。
- 初期生成範囲はcanonical x=0..59、y=0..59の60×60とする。
- 地上はstaggered square-tile gridとし、DB、API、ゲームルールはx、yを正本にする。
- 偶数absolute yのrowを16px右へずらし、各rowは60セルを持つ。
- 世界座標は固定上限に依存させない。
- 世界全体を1つの巨大JSONとして保存しない。
- マップはチャンク単位で保存、取得、更新する。
- チャンク辺長は16とし、負座標で数学的なfloor divisionとfloor moduloを使う。
- PostgreSQLを箱庭専用DBとして使用する。
- map APIのversion prefixは`/api/v1`とし、可読なJSONをchunk単位で返す。
- compact array、binary、独自圧縮はMVP後とする。
- プレイヤー画面は自国の首都周辺を中心表示する。
- スクロール時に近隣チャンクを追加取得する。
- 遠方のデータを無条件に読み込まない。

## 首都

- 国家作成時に1つ自動生成する。
- 国家登録地点はserverが空き地点から自動配置し、座標直接指定と3候補提示UIはMVP後とする。
- 初期Territoryはrulesetの暫定既定値としてCapitalからx/y grid distance 2以内、最大19セル相当とする。
- Capital間最低距離はrulesetの暫定既定値として12とする。
- 初期TerritoryとCapital間距離は確定balance値ではなく、新しいruleset versionで変更可能にする。
- 初期Territoryは他国と重ねず、Capital周囲に最低限の発展可能地を求める。
- ログイン時の表示中心とする。
- 通常コマンドでは建設できない。
- 災害、ミサイル、怪獣、戦争では破壊されない。
- 通常処理では領有権を変更できない。
- 地図上に存在する間、首都人口は最低1単位を下回らない。
- 首都人口、稼働率、税収、機能は被害を受ける。
- 将来のsettlement_seed能力は、隣接する適格な自国Territoryまたは中立地へ村を発生させ、敵国Territoryを上書きしない。
- 将来の緊急開拓は、詰み条件を満たす場合だけ隣接する自国Territoryへ最低規模の緊急農場を1つ生成できる境界にする。
- settlement_seedと緊急農場の具体値はturn・command実装前に決め、MVP縦切りへ先行実装しない。
- sunken_archivedでは首都を現在地図から除去するが、過去首都履歴は保持する。

## 防壁都市

- 国境防衛用施設とする。
- 通常都市より国境を塗り替えられにくくする。
- 完全な塗り替え不能とするか、抵抗値方式とするかは未決定とする。
- 首都とは異なり、攻略可能性を残す方向で検討する。
- 建設上限、維持費、周辺効果は後で決める。

## 世界の自動拡張

- 新規国家の配置場所を検索する。
- 十分な空間がない場合は世界を拡張する。
- 拡張量は必要最小限にする。
- 必要に応じて縦または横へ拡張する。
- 既存座標を移動しない。
- 同時登録で同じ場所を割り当てない。
- min_x、max_x、min_y、max_yをチャンク境界単位で必要な方向だけ広げる。
- x、y、chunk_x、chunk_yの将来拡張では負数も扱えるfloorDivとfloorModを使う。

## 国家の休眠

- lifecycle stateはactive、dormant_frozen、dormant_contestable、sunken_archivedに統一する。
- UTCのlast_active_atから30日でdormant_frozenへ移行する。
- 30日休眠では資源、人口、生産、消費、災害、自動発展、怪獣移動、国境、一般攻撃を凍結し、全領土を占領不能にする。
- dormant_frozenとdormant_contestableへの怪獣討伐専用ミサイルだけを例外とし、怪獣以外へ被害を出さない。
- 180日でdormant_contestableへ移行し、凍結を継続しながら首都以外の領土を占領可能にする。
- 復帰しても他国に占領された領土を自動返還せず、凍結期間の生産を遡及しない。
- 365日または明示的放棄でsunken_archivedへ移行し、残存領土・首都・施設を海へ戻す。
- user、nation、event、称号、統計、領土履歴は物理削除しない。
- sunken_archivedからの復帰は旧領土の巻戻しではなく再入植を基本候補とする。

## ターン・災害設定（MVP後）

- 最初のMVP縦切りにはturn処理を実装しない。
- 将来、ターン更新間隔を変更しやすくする。
- 災害全体倍率を変更可能にする。
- 災害ごとの発生率を変更可能にする。
- 災害ごとの有効・無効を切り替え可能にする。
- 再デプロイせず、管理画面またはDB設定で変更できる案を検討する。
- 設定変更履歴と変更者の記録も検討する。

## 将来拡張

最初のMVP縦切りでは未実装だが、次を追加できる設計にする。

- プレイヤー専用の地下マップ
- 他人が原則干渉できない地下施設
- 全プレイヤー共通の宇宙マップ
- 複数世界
- シーズン制
- 新しい施設
- 新しい地形
- 新しい災害
- 新しい資源
- 新しいコマンド

## データ駆動設計

- 各Worldは不変の`ruleset_version_id`を参照する。
- MVPのruleset versionには配置と初期Territoryに必要な最小keyだけを置き、turn・command・災害schemaを先行実装しない。
- 施設IDや地形IDをソースコード内の連番へ強く依存させない。
- 名称、説明、建設費、維持費、効果、建設条件などをマスターデータ化する。
- 拡張可能な属性にはJSONまたはJSONBを利用できるようにする。
- 検索、排他制御、整合性が重要な値まですべてJSONへ押し込まない。
- 世界全体を1つのJSONとして保存しない。
- 将来のインフレに備え、資金、人口、資源、攻撃力などの数値上限を不用意に小さくしない。
- 255や65535など、旧データ形式由来の上限を設けない。
- PHPの通常整数範囲やDBのBIGINTを超える可能性がある値は、実装前に数値表現を決定する。
- ゲームバランス上の上限とDB上の保存上限を分離する。
- 表示形式と内部数値形式を分離する。
- terrain・facility定義は安定した`asset_key`を持てる。
- resource種はcatalogから追加できるようにし、固定columnだけへ閉じ込めない。
- command table、複数turn phase、構造化event log、notification outboxを後から追加できる境界を維持する。
- 将来機能のための空tableやclassをMVPへ先行実装しない。

## 未解決の設計事項

- Status: Open / Required before: Laravel初期構築前 — VueとLaravelの配信origin。
- Status: Open / Required before: 認証実装前 — OAuth package、Sanctum、session、state、CSRF。
- Status: Open / Required before: 国家作成実装前 — Capital初期人口、初期Territoryへ含める地形、候補score、拡張上限。
- Status: Open / Required before: マップAPI実装前 — 未発見領域と非公開属性のAPI契約。
- Status: Open / Required before: UI実装前 — 描画方式、redirect UX、アクセシビリティ。
- Status: Open / Required before: ターン処理実装前 — phase順、transaction規模、災害抽選、seed、休眠Job。
- Status: Open / Required before: コマンド実装前 — queue順序、件数、失敗、予約、settlement_seed、緊急農場。
- Status: Open / Required before: 戦闘実装前 — Capital被害、防壁、国境同時解決、missile可視性、怪獣、dormant攻撃。
- Status: Open / Required before: 本番公開前 — provider障害、backup、moderation、明示的放棄。
- Status: Deferred / Required before: MVP後 — User merge、解除UI、追加provider、再入植、複数World、season、地下・宇宙、研究・熟練度、追加resource、Modifier、item、Mariachang、低zoom、WebSocket、binary map API。

詳細な状態と正本へのlinkは`docs/open-questions.md`に集約する。
