# 新作の目標アーキテクチャ

## 状態

MVP縦切りの基盤設計を確定した文書。ゲーム実装を開始する承認ではない。箱庭諸島2＋とやまにてぃのコードを移植せず、観察した挙動を独立したモデルとして再構築する。

## 設計目標

1. 複数国家が同じ地上世界を共有する。
2. 初期60×60から、既存座標を変えずに必要な方向へ拡張できる。
3. 表示・保存・ターン処理をチャンクで局所化し、世界全体の読み込みを不要にする。
4. ターン結果を再現・監査でき、二重実行と部分保存を防ぐ。
5. 地下・宇宙、資源、研究、熟練度、アイテムを後付けできる。
6. 外部通知の障害がゲーム進行を止めない。
7. 国家を物理削除せず、休眠・領土解放・地図からの退去を監査可能な状態遷移として扱う。
8. 首都だけが残った国家も、村発生と緊急開拓から時間をかけて再建できる。

## 論理構成

| 境界 | 主な責務 | 主なデータ |
|---|---|---|
| Identity and Access | ユーザー、外部認証identity、権限 | users、auth_identities、roles |
| World and Map | 世界、map space、staggered x/y座標、チャンク、セル | worlds、map_spaces、map_chunks、map_cells |
| Nation and Territory | 国家、首都、領土、国境影響、休眠状態 | nations、nation_capitals、cell_ownership、nation_state_transitions |
| Turn and Commands | 命令キュー、ターン実行、フェーズ、乱数 | commands、turn_runs、phase_runs |
| Rules and Catalog | 地形、施設、資源、災害、費用、ruleset | rulesets、catalog entries、rule values |
| Events and Notifications | 構造化イベント、ログ投影、通知配送 | domain_events、log_entries、outbox_messages |
| Administration | ruleset公開、ターン監視、監査、復旧 | audit_logs、admin_operations |
| Query and Presentation | チャンク、国家状態、履歴の読取モデル | read models、cache keys、API DTO |

境界間の参照は不変IDと明示的なApplication Serviceを通す。Eloquentモデルから別領域の保存処理を直接連鎖させない。

## レイヤー

- Interface: LaravelのHTTP API、管理画面、CLI、Scheduler入口。
- Application: ユースケース、トランザクション境界、認可、冪等性。
- Domain: 座標、国家、命令、ターンフェーズ、効果計算。LaravelやDB型から独立させる。
- Infrastructure: Eloquent、DBロック、キャッシュ、outbox配送、外部認証。

Vueを採用する場合も、Vueコンポーネントはルール計算を持たず、APIが返す表示用状態と実行可能操作を扱う。

## 実行配置

| プロセス | 責務 | 失敗時の扱い |
|---|---|---|
| Web | API、認証、画面配信 | ターン処理を直接実行しない |
| Scheduler | 期限到来した世界のturn jobを一度だけ投入 | 分散ロックと一意制約で重複抑止 |
| Lifecycle worker | UTC日時から休眠遷移と沈没処理を冪等実行 | world排他、transition checkpoint、再試行 |
| Turn worker | 世界単位の排他下でフェーズを実行 | transaction rollback、同じrunの安全な再試行 |
| Notification worker | outboxからDiscord等へ配送 | ゲーム保存後に再試行、進行を停止しない |
| Database | 正本、ロック、監査 | 継続バックアップと復旧手順を別途定義 |

正本DatabaseにはPostgreSQLを採用し、箱庭専用DBとしてNextcloud用MariaDBから分離する。JSONB、transaction、行lock、一意制約、座標・event・履歴検索を利用でき、既存のboard-webで運用経験があるためである。Dockerの最終構成、cache製品、queue製品は各機能の実装前に決める。

## 主要なデータ原則

- user_id、world_id、map_space_id、nation_id、turn_run_idは不変の代理キーとする。
- Userは認証account、NationはWorld内のゲーム主体として分離する。Discord ID、Google ID、メールアドレス、ranking順位をUserまたはNationの内部IDとして使わない。
- 外部認証主体は`auth_identities`へ置き、`(provider, provider_user_id)`を一意にする。providerのメールアドレス一致だけではUserを自動統合しない。
- map cellの正本座標はstaggered square-tile x、yとし、pixel座標や距離用の一時cube成分を保存しない。
- セルの座標、所有者、地形、施設、資源残量など、検索・制約・ロック対象は通常列にする。
- JSONまたはJSONBは、地形固有パラメータなど可変で疎な属性に限定する。
- rulesetは版を持ち、各Worldは不変の`ruleset_version_id`を参照する。turn導入後は各turn_runも使用版を記録する。
- 時刻はUTCで保存し、表示時に利用者のタイムゾーンへ変換する。
- 文字列は全面的にUTF-8とする。
- user、nation、event、過去統計、領土履歴は休眠・放棄を理由に物理削除しない。

## 書込みモデルと読取りモデル

書込み側は正規化データ、制約、ロックを優先する。読取り側は、画面に必要なセル表示、所有国の色、施設アイコン、更新版をチャンクDTOへ投影する。キャッシュは正本ではなく、chunk_versionまたはturn_numberで無効化できるものに限る。

世界全体を1件のJSONへ直列化しない。ターン処理も変更対象のチャンク・国家・イベントを明示して保存し、必要なら集計用read modelを更新する。

## Historical initial MVP

このsectionは最初のarchitecture sliceのprovenanceであり、現在の実装範囲やOpen gateを表さない。現在の索引は`docs/open-questions.md`とする。

最初の実装は次の一本道に限定する。

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

この段階ではturn_run、command queue、生産・消費、災害、戦闘、国境変化、自動村発生、休眠遷移Job、domain event log、notification outboxを実装しない。ただし、次の追加場所を閉じない。

- commandを後から通常tableとhandlerとして追加できる。
- turnを複数phaseへ分割し、World lockとruleset versionを参照できる。
- 構造化event logとnotification outboxを後から同一transactionへ追加できる。
- terrain・facility定義に安定した`asset_key`を持たせられる。
- resource typeをcatalogから追加でき、固定columnだけに依存しない。
- 同じUserがWorldごとに別Nationを持てる。

将来tableやclassを空の形で先行作成せず、境界と不変IDだけをMVPに反映する。

## ターンの整合性境界

1つの世界の1ターンをturn_runで表す。world_idとturn_numberに一意制約を置き、世界行または専用lock行を排他取得する。乱数seed、ruleset_version、各phaseの開始・終了・件数・エラーを記録する。

ゲーム状態とdomain_event、outbox_messageは同じDBトランザクションで確定する。Discordなどへのネットワーク送信はコミット後に別workerが行う。

巨大な1トランザクションが現実的かは性能試験で確認する。分割が必要な場合は、フェーズごとの再開点と不可逆な公開境界を設計し、単純な途中コミットにはしない。

## 拡張ポイント

- map_space.type: surface、underground、space。
- catalog_entry: terrain、facility、resource、disaster、itemなどの安定キー。
- modifier: 条件、対象、演算、優先度、期間。
- command handler: 命令種別ごとの検証・費用・効果。
- domain event subscriber: ログ、通知、実績、分析投影。

プラグインコードをDBから動的実行する方式は採用しない。追加要素は型付きコードと版管理されたデータを組み合わせ、未知の効果は明示的に拒否する。

## セキュリティと管理

- プレイヤー用、管理者用、ターン実行用の権限を分離する。
- OAuth loginとprovider連携を分け、callbackのstate、session、CSRF、identity一意性をserverで検証する。
- Discord・Googleのメールアドレスは補助snapshotとし、認証主体の識別や自動統合に使わない。
- 命令対象の所有・可視性をサーバーで再検証する。
- ruleset変更は下書き、検証、公開、有効ターンを持ち、監査ログへ残す。
- 秘密情報は環境または秘密管理基盤に置き、rulesetやクライアントへ出さない。
- デバッグ認証は本番ビルド・本番設定で到達不能にする。

## 非目標

- CコードのPHPへの逐語変換。
- やまにてぃのモデルや画面のコピー。
- 全要素を初回リリースへ詰め込むこと。
- あらゆる仕様をJSONだけで変更可能にすること。

## 段階案

1. 設計整理: MVP縦切りに必要な基盤判断を確定し、残りを機能直前へ分類する。
2. 最小垂直スライス: 認証、共有地上World、Nation作成、自動配置、Capital周辺chunk API、Vue地図表示。
3. コマンド基盤: queue契約、handler、検証、失敗・予約規則。
4. ターン基盤: 排他、seed、phase、event、outbox、復旧試験。
5. ゲーム要素: 地形、施設、収支、災害、攻撃、国境。
6. 将来拡張: 資源、研究、熟練度、item、地下・宇宙。

## MVP実装記録（2026-07-26）

最初の縦切りは`product/`へ実装した。PHP 8.5.8、Laravel 13.22.0、Vue 3.5.40、TypeScript 6.0.2、Node.js 24.18.0 LTS、PostgreSQL 18.4を採用する。productionはmulti-stage buildでVueをcompileし、Apache + PHPの単一`hakoniwa-web` serviceから同一origin配信する。document rootは`public/`であり、`artisan serve`は使用しない。

MVPのapplication service境界は`OceanWorldGenerator`、`CapitalPlacementService`、`InitialIslandGenerator`、`NationCreationService`、`NationResourceService`、`AssetManifestResolver`である。command、turn、worker、schedulerは追加していない。同じapplication imageを別commandで起動できるため、後続PRでworker/scheduler serviceを追加できる。
