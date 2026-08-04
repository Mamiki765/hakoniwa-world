# タイル画像の論理対応・外部配置設計

## 決定

箱庭諸島2＋に存在する地形、施設、怪獣などは、ローカルに保持する原GIFを新作UIで使用する。ただし画像バイナリは公開Gitリポジトリへ収録せず、実行時にGit外のディレクトリから読み取り専用で提供する。

ゲーム定義はファイル名や絶対パスではなく`asset_key`だけを持つ。Git管理する画像マニフェストが`asset_key`を原ファイル名へ解決し、環境設定が画像ディレクトリと公開URLを解決する。

```json
{
  "id": "facility.farm",
  "asset_key": "tile.farm"
}
```

```json
{
  "tile.farm": "land7.gif"
}
```

`land7.gif`は`hakow.js:563-566`で農場に割り当てられており、例示ではなく原資料で確認済みである。

## 責務の分離

| 要素 | 保持するもの | 保持しないもの |
|---|---|---|
| 地形・施設・怪獣definition | 安定ID、`asset_key`、代替表示用の短い名称・分類 | 絶対パス、URL、base64、原GIF |
| 画像マニフェスト | `asset_key`から原ファイル名への対応、期待形式・寸法の候補 | ホスト固有パス、画像バイナリ |
| 環境設定 | 外部ディレクトリ、公開URL prefix、機能の有効化 | definition別ファイル名 |
| 配信層 | 許可されたbasenameだけを読み取り配信、存在・形式検査、cache header | 任意パスの読取り、directory listing |
| UI | 解決済みURL、fallback種別、短い名称 | filesystem path |

クライアントが任意のファイル名やパスを指定するAPIは作らない。マニフェスト値は区切り文字を含まないbasenameだけに制限し、`..`、絶対パス、未知拡張子を拒否する。

## 確認済みマップ対応

| definition ID候補 | asset_key | 元ファイル名 | C版対応・備考 |
|---|---|---|---|
| `terrain.sea.deep` | `hakoniwa_original.terrain.sea.deep` | `land0.gif` | `Land::Sea/SeaDeep` |
| `terrain.sea.shoal` | `hakoniwa_original.terrain.sea.shoal` | `land14.gif` | `Land::Sea/SeaShoal` |
| `facility.seabed_oil_field` | `hakoniwa_original.facility.seabed_oil_field` | `land16.gif` | `Land::Sea/SeaOil` |
| `terrain.wasteland` | `hakoniwa_original.terrain.wasteland` | `land1.gif` | `Land::Waste/WasteNormal` |
| `terrain.wasteland.missile_scar` | `hakoniwa_original.terrain.wasteland.missile_scar` | `land13.gif` | `Land::Waste/WasteMissile` |
| `terrain.plain` | `hakoniwa_original.terrain.plain` | `land2.gif` | `Land::Town,param=0` |
| `settlement.village` | `hakoniwa_original.settlement.village` | `land3.gif` | `Land::Town,param=1..29` |
| `settlement.town` | `hakoniwa_original.settlement.town` | `land4.gif` | `Land::Town,param=30..99` |
| `settlement.city` | `hakoniwa_original.settlement.city` | `land5.gif` | `Land::Town,param>=100` |
| `terrain.forest` | `hakoniwa_original.terrain.forest` | `land6.gif` | `Land::Forest` |
| `facility.farm` | `hakoniwa_original.facility.farm` | `land7.gif` | `Land::Farm` |
| `facility.factory` | `hakoniwa_original.facility.factory` | `land8.gif` | `Land::Factory` |
| `facility.missile_base` | `hakoniwa_original.facility.missile_base` | `land9.gif` | `Land::Base` |
| `facility.defense` | `hakoniwa_original.facility.defense` | `land10.gif` | `Land::DBase/DBaseTrue` |
| `facility.decoy` | `hakoniwa_original.facility.decoy` | `land10.gif` | `Land::DBase/DBaseFalse`。原作でも共通画像 |
| `terrain.mountain` | `hakoniwa_original.terrain.mountain` | `land11.gif` | `Land::Mountain,param=0` |
| `facility.mine` | `hakoniwa_original.facility.mine` | `land15.gif` | `Land::Mountain,param>0` |
| `facility.seabed_base` | `hakoniwa_original.facility.seabed_base` | `land12.gif` | `Land::SBase` |
| `facility.monument.monolith` | `hakoniwa_original.facility.monument` | `monument0.gif` | `Land::Monument,param=0` |
| `facility.monument.peace_tower` | `hakoniwa_original.facility.monument` | `monument0.gif` | `Land::Monument,param=1`。原作でも共通画像 |
| `facility.monument.war_memorial` | `hakoniwa_original.facility.monument` | `monument0.gif` | `Land::Monument,param=2`。原作でも共通画像 |

人口段階や施設規模をゲームデータとして維持しても、原画像が1種しかない段階をファイル名の連番で推測しない。definition IDと表示variantの関係は明示的に記録する。

## 確認済み怪獣対応

| definition ID候補 | asset_key | 元ファイル名 | 備考 |
|---|---|---|---|
| `monster.mecha_inora` | `hakoniwa_original.monster.mecha_inora` | `monster7.gif` | monster kind 0 |
| `monster.inora` | `hakoniwa_original.monster.inora` | `monster0.gif` | kind 1 |
| `monster.sanjira` | `hakoniwa_original.monster.sanjira` | `monster5.gif` | kind 2 |
| `monster.red_inora` | `hakoniwa_original.monster.red_inora` | `monster1.gif` | kind 3 |
| `monster.dark_inora` | `hakoniwa_original.monster.dark_inora` | `monster2.gif` | kind 4 |
| `monster.inora_ghost` | `hakoniwa_original.monster.inora_ghost` | `monster8.gif` | kind 5 |
| `monster.kujira` | `hakoniwa_original.monster.kujira` | `monster6.gif` | kind 6 |
| `monster.king_inora` | `hakoniwa_original.monster.king_inora` | `monster3.gif` | kind 7 |
| monster kind 2/6の硬化表示 | `hakoniwa_original.monster.hardened` | `monster4.gif` | 状態variant。独立monster種ではない |

対応根拠は`hakow.js:68-103,599-610`である。怪獣名やルールの新作採否と、画像ファイルの対応確認は別の判断として扱う。

PR21ではこの対応表をproduction manifestの正本として採用した。8つのnormal `asset_key`と硬化variantは`AssetManifestResolver`から既存外部tile routeへ解決する。Git管理するのはmappingだけで、GIFは`product/public`へ置かない。画像のhash、catalog、fallback、deployment検査は`product/docs/monster-audit-pr21.md`を参照する。

## 新規要素

原作にない首都、防壁都市、大学、研究所、新資源施設、新隕石アイテム、地下・宇宙専用施設には`hakoniwa_original.*`を割り当てない。原GIFを意味の似た新施設へ流用せず、当面は次のような別namespaceのplaceholderを使う。

```json
{
  "id": "facility.capital",
  "asset_key": "placeholder.facility.capital",
  "fallback_label": "首都",
  "fallback_style": "capital"
}
```

将来画像を追加するときはdefinition IDを変えず、マニフェストのasset key解決先または選択themeを追加する。原画像namespaceと新規画像namespaceを混ぜない。

## 実行時配置

Roadmap PR2で採用したコンテナ内読取先は次とする。

```text
/srv/hakoniwa-assets/tiles
```

設定候補：

```text
HAKONIWA_TILE_ASSET_PATH=/srv/hakoniwa-assets/tiles
HAKONIWA_TILE_ASSET_BASE_URL=/assets/hakoniwa-tiles
```

ホスト側はGit外の保有ディレクトリを指定し、コンテナへread-only bind mountする。Composeへ反映する段階では概念上、次の契約にする。

```yaml
volumes:
  - type: bind
    source: ${HAKONIWA_TILE_ASSET_HOST_PATH}
    target: /srv/hakoniwa-assets/tiles
    read_only: true
```

Windowsの開発ホスト、Linux本番ホストともホスト絶対パスはGit外のCompose overrideで与え、リポジトリへ個人パスを書かない。旧`HAKONIWA_ORIGINAL_ASSET_*`は移行互換のfallbackとして読めるが、新規設定は`HAKONIWA_TILE_ASSET_*`を使う。

### 配信方式の要件

- web serverのaliasまたはアプリの制限付きasset endpointを候補とし、Docker設計時に選ぶ。
- URLは`HAKONIWA_TILE_ASSET_BASE_URL`とマニフェストbasenameから生成する。filesystem pathをAPIへ返さない。
- 許可された58ファイル以外を列挙・取得できないようにする。
- GIF、PNG、WebPの実画像MIME、nosniff、適切なCSP、cache方針を設定する。
- URLへ`?v=<mtime>-<size>`を付ける。同名ファイルを置換するとURLが変わり、image rebuild、DB更新、frontend buildなしでbrowser reload時に取得し直せる。
- 原名・原形式のまま配信し、build時の最適化、sprite化、再encodeはしない。
- DBのJSON、APIレスポンス、manifestへbase64を保存しない。

## 起動時検査

画像欠落でゲームを停止させない。起動時またはcatalog読込時に、各マニフェスト項目を次の状態へ分類する。

| 状態 | 条件 | UI動作 | 運用動作 |
|---|---|---|---|
| `available` | 許可basenameが存在し、読取可能で、GIFとして検査できる | 原GIFを表示 | 通常metricsのみ |
| `missing` | ファイルまたはmountがない | fallback tile | asset key、filename、環境を構造化ログへ記録 |
| `invalid` | path違反、非GIF、読取不能、期待寸法外 | fallback tile。対象ファイルは配信しない | warning/errorとmetric。ゲーム処理は継続 |
| `unmapped` | definitionのasset keyがmanifestにない | definitionのfallback | catalog検証ログ。ルール処理は継続 |

SHA-256の厳格一致を必須にするかは未決定である。少なくともファイル名、GIF signature、寸法は検査候補とし、原ファイルを変更しない。

## UIフォールバック仕様

1. UIは画像URLと同時に`fallback_label`、`fallback_style`、definition IDを受け取るか、versioned definition catalogから取得する。
2. serverが`missing/invalid/unmapped`と判定した場合、URLを空または明示的なavailability=falseとして返し、最初からCSS fallbackを描く。
3. 配信後の404や通信失敗にも備え、DOMなら`error`時に画像要素を隠し、Canvasならload失敗をfallback描画へ置換する。壊れた画像アイコンを画面へ残さない。
4. fallback tileは地形分類ごとのCSS色・patternと、短い名称またはIDを併用する。色だけに依存しない。
5. 画像失敗はゲームのcommand、turn、保存、API本体を失敗させない。
6. 同じasset keyの失敗をセル数だけログ出力しない。asset key・manifest version・原因で集約し、一定時間で抑制する。
7. 画像が復旧した場合はcatalog/asset healthの再確認後に通常表示へ戻し、セルデータの変更は不要とする。

最低限の代替表現例：

| 分類 | CSS代替 | 短い表示例 |
|---|---|---|
| 海・浅瀬 | 青系pattern。浅瀬は境界線を追加 | `海`, `浅` |
| 自然地形 | 緑・茶系pattern | `平`, `森`, `山`, `荒` |
| 集落 | 中立背景＋人口段階記号 | `村`, `町`, `都` |
| 施設 | 枠線＋施設略称 | `農`, `工`, `鉱`, `基`, `防` |
| 怪獣 | 警告pattern＋名称 | `怪`または短縮名 |
| 新規placeholder | definition固有のCSS custom property | `首`, `壁`, `学`, `研` |

アクセシビリティ用のセル説明は画像の有無にかかわらず同じ地形名、施設名、所有者、座標を読み上げる。alt相当の文字列を原GIF名にしない。

## Creditsと出典

Creditsまたは画面上の適切な位置に、少なくとも次を表示する設計を維持する。

```text
字・原作：徳岡宏樹
画像：小川克人
題字：稲葉修吾
原配布元：http://www.bekkoame.ne.jp/~tokuoka/hakoniwa.html
```

表示は`THIRD_PARTY_NOTICES.md`および`docs/reference-analysis/license-and-provenance.md`と一致させる。原配布元へのリンクを保持し、画像を新作独自素材として表示・再配布しない。

## Git境界

Git管理してよいのは、論理キー、対応表、必要ファイル一覧、検査仕様、配置手順、環境変数例、Creditsである。原GIF、そのコピー、変換物、sprite、base64は`product/public/`、`product/resources/`、`docs/`その他のGit管理領域へ置かない。

## 未決定事項

- 本番の配信をweb server alias、アプリendpoint、外部静的配信のどれにするか。
- SHA-256完全一致を起動条件ではなくwarningにするか。
- Creditsの常設位置と、原配布元が取得不能な場合の案内文。
- 58個すべてに後年の画像許可説明が適用されるかという公開前確認。
- 原作の賞、記念碑名、怪獣名を新作仕様としてどこまで採用するか。
- Canvas採用時のpixel scaling、補間無効化、high-DPI描画方式。
