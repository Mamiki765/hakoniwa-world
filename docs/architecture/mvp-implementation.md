# MVP実装構成

## 採用version

| component | version |
|---|---:|
| PHP | 8.5.8 |
| Laravel | 13.22.0 |
| Node.js | 24.18.0 LTS |
| Vue | 3.5.40 |
| TypeScript | 6.0.2 |
| Vite | 8.1.5 |
| PostgreSQL | 18.4 |
| Laravel Socialite | 5.29.0 |
| SocialiteProviders/Discord | 4.2.0 |

いずれも実装時点の公式support対象または現行stableから、相互に解決できる組合せをlock fileへ固定した。Vue buildはApache + PHP imageへcopyし、Laravelと同一originで配信する。

## service境界

- `OceanWorldGenerator`: World、MapSpace、catalog、全面海セルを冪等生成する。
- `CapitalPlacementService`: distance 5の91セルとCapital距離を評価し、安定順の配置候補を選ぶ。
- `InitialIslandGenerator`: seedから初期島、施設、Capital、Territoryを生成する交換可能なinterface。
- `NationCreationService`: PostgreSQL lockと1 transactionで登録全体を調停する。
- `NationResourceService`: rulesetにある初期資源を国家残高へ作成する。生産・消費は担当しない。
- `AssetManifestResolver`: asset keyを外部GIFまたはCSS fallbackへ解決する。
- `HexCoordinate` / `ChunkCoordinateService`: signed axial計算を一か所へ集約する。

Controllerは入力、認証、DTO変換だけを扱い、配置や島生成を行わない。

## PostgreSQL schema

認証は`users`と`auth_identities`、ゲーム主体は`worlds`、`map_spaces`、`nations`、`nation_memberships`へ分離する。地図は`map_chunks`と`map_cells`、Capitalは`nation_capitals`、生成履歴は`world_generation_runs`、登録予約は`nation_creation_requests`、監査は`audit_events`に保存する。

資源は`resource_definitions`と`nation_resources`に分ける。definitionはunit、nullable nutrition、storable、tradable、ruleset側の`sale_price_key`を持ち、価格を不変値として固定しない。初期定義はwheat、fish、monster_meatだけで、生産・消費・売却・市場は未実装である。moneyの汎用資源化は後続判断とする。

## Worldと国家作成

`hakoniwa:world:init`は3,600セルを全て海で作る。国家作成時だけ海域を予約し、旧作の初期島生成を基礎に島を生成する。Nation、初期資源、Island、Capital、Territory、Membership、auditを同じtransactionへ含める。

同時登録はWorld row lockと`pg_advisory_xact_lock`でworld単位に直列化する。候補を確定後に再検査し、DB一意制約も最終防衛線とする。

## APIとVue

API prefixは`/api/v1`で、me、world一覧、map space一覧、chunk、Nation作成・取得をLaravel API Resource経由で返す。外部provider ID、email、token、内部metadataは返さない。

VueはAPI client、map state、odd-q projection、DOM rendererを分離する。Capital周辺9 chunksを取得し、pan、zoom、六方向keyboard選択、選択セルtext、loading/error/empty state、asset fallbackを提供する。

## MVP外

command、queue、turn、resource production/consumption/sale、market、facility production outputs、disaster、monster、missile、worker、scheduler、Redis、WebSocket、本番OCI Compose統合は実装しない。
