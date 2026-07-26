# 世界とマップ空間

## 状態

地上世界の座標と拡張に関する設計。座標方式、`chunk_size = 16`、初期生成範囲はADR-0003で確定した。拡張候補の評価と1回の最大拡張量は国家作成実装前に決める。

## 必須条件

- 地上は初期60×60の共有世界である。
- 国家ごとに別マップを持たず、全国家が同じ座標空間を参照する。
- 拡張後も既存セルの座標と識別は変化しない。
- 表示・保存・ターン処理で世界全体の読み込みを必須にしない。
- 将来、地下・宇宙を別map_spaceとして追加できる。

## 正本座標

DB、API、ゲームルールでは、pointy-top hexの符号付き整数axial座標q、rを正本とする。x、y、row、column、offset座標はセルの保存形式にしない。qとrは負数を許可し、unsigned型を使わない。

6方向の隣接ベクトルは(+1, 0)、(+1, -1)、(0, -1)、(-1, 0)、(-1, +1)、(0, +1)とする。距離はcube成分s=-q-rを導出し、標準のaxial/cube距離式を使う。

UI、領土、ミサイル、災害、怪獣、登録地点探索が独自の隣接・距離式を持つことを禁止する。詳細、採否理由、変換テストはADR-0003を正本とする。

## UI投影

矩形状の画面配置だけにpointy-top odd-q vertical offsetを使い、奇数列を下へ半セルずらす。column、rowは一時的な表示座標であり、DBやAPIへ保存・送信しない。

- parity = floorMod(q, 2)
- column = q
- row = r + (q - floorMod(q, 2)) / 2
- q = column
- r = row - (column - floorMod(column, 2)) / 2

負の奇数でもfloorModは1を返す。このため通常の剰余演算をparity判定へ直接使わない。

## 初期生成範囲

地上の初期生成範囲はq=-30..29、r=-30..29の60×60、合計3,600セルとする。q=0..59、r=0..59案は原点が初期範囲の隅になり、正負方向への拡張、登録探索、運用表示の説明に偏りが出るため採用しない。

偶数幅なので幾何学的中心は(-0.5, -0.5)になるが、原点を含み、正負方向をほぼ均等に確保できる。登録地点は原点からの単純距離ではなく、現在境界、既存首都距離、地形、余白のscoreで探索する。UIは絶対q、rを投影するため、負の初期境界を特別扱いしない。

初期範囲と交差する外周chunkであっても、範囲外セルを生成済みとはみなさない。生成済み境界はWorldの論理上限ではなく、現在materialize済みの範囲だけを表す。

## 世界とレイヤー

概念上の関係は次の通り。

- World: ターン番号、`ruleset_version_id`、共有地上世界を含むゲーム世界。
- MapSpace: surface、underground、spaceごとの座標系、生成境界、可視性。
- MapChunk: map_space内の連続したセル集合、版番号、更新ターン。
- MapCell: 絶対q、r、地形、所有者、施設、可変属性。

map_space_id、q、rに一意制約を置く。表示上の位置、offset座標、配列順は識別子にしない。

論理上、地上map_spaceは1つで全国家が共有する。「地上が1つ」であることは「地上を1ファイルで保存する」ことを意味しない。地下・宇宙は別map_spaceとし、地上と同じ境界を共有する必要はない。宇宙は別トポロジーになる可能性があるため、map_spaceにはcoordinate_systemを持たせる余地を残す。

## チャンク

チャンクは保存、取得、cache、lock範囲、変更通知の単位である。辺長は16とする。これは既存Worldの保存・API互換性に関わる内部仕様であり、通常のruleset値として変更しない。

qとrを独立に数学的floor divisionし、ローカル座標をfloor moduloで求める。

- floorDiv(value, size) = floor(value / size)
- floorMod(value, size) = value - floorDiv(value, size) * size
- size = 16
- chunk_q = floorDiv(q, size)
- chunk_r = floorDiv(r, size)
- local_q = floorMod(q, size)
- local_r = floorMod(r, size)

0→(0,0)、15→(0,15)、16→(1,0)、-1→(-1,15)、-16→(-1,0)、-17→(-2,15)をqとrの両方で満たす。PHP、TypeScript、SQLのゼロ方向丸めや負の剰余差を共通関数で吸収する。

セルそのものを巨大JSONへ詰めない。セル所有者・座標の検索と行ロックを満たすため、map_cellsへq、rとchunk_idを保持し、map_chunksへchunk_q、chunk_r、version、checksumを保持するハイブリッドを採用する。セル情報の正本はmap_cellsだけに置く。map_chunksのversion、checksum、更新turnはキャッシュ無効化、変更通知、同時更新検出の補助であり、別のマップ正本ではない。

## 拡張規則

国家登録候補地またはゲーム要素が現在境界の安全余白を満たせないとき、必要方向へ拡張する。

1. 要求領域と他国首都からの必要距離を算出する。
2. 現境界内で候補を探索する。
3. 見つからない場合、要求領域を含む最小のチャンク整列境界を計算する。
4. map_spaceのmin_q、max_q、min_r、max_rを原子的に更新する。
5. 必要なチャンクと既定地形を生成する。
6. 同じトランザクション内で候補地を再検証し、国家を配置する。

「必要最小限」はセル1列ではなく、取得・保存単位であるチャンク境界までの最小矩形と定義する。上下左右のどの方向を優先するか、地形生成seed、同時登録候補のscoreは国家作成実装前に決める。

既存セルを動かす、原点を振り直す、全セルを別配列へコピーする方式は採用しない。境界はメタデータであり、座標空間そのものには固定上限を設けない。

## 共有世界と領土

海・中立地・国家領土はセル状態として同じレイヤーに存在する。所有者はnullableなnation_idで表し、地形種別から独立させる。海を所有可能にするかはrulesetで定義するが、「ownerがないため海である」とは解釈しない。

国境とは国ごとの別オブジェクトではなく、所有者が異なる隣接セル間の導出情報とする。境界線のキャッシュを持つ場合も正本はセル所有権である。所有権変更はdomain eventを発行し、チャンク版と国家集計を更新する。

首都セルは国家と1対1で参照し、通常の国境変更から除外する。首都の施設、人口、所有権に関する詳細はcapital-and-territory.mdで扱う。

## 取得APIの概念

MVPでは`/api/v1`の可読なJSONを使う。初回login時は自国Capitalを中心とするviewportと、それに交差するchunkだけを返す。scroll時は未取得chunkを追加要求する。World全体や地上全体を1 responseで返さない。応答には次を含める。

- world、map_space、turn_number
- chunk_q、chunk_r、chunk_version
- セルの絶対q、r、地形、施設、所有国表示キー
- 可視範囲に必要な最小限の国家表示情報
- ETagまたは同等の条件付き取得キー

APIは配列添字やoffset座標から正本座標を推測させず、各セルのabsolute q、rを明示する。未生成領域、現在生成境界外、未発見領域、権限で非公開の領域を別の状態として区別する。compact array、binary、独自圧縮はMVP後とする。

## ターン処理との関係

フェーズは対象チャンク集合を宣言する。全世界災害であっても、対象抽選、セル更新、集計を分ける。活動セル、国家周辺、イベント対象を索引で絞り、固定60×60全走査を将来の前提にしない。

複数チャンクを跨ぐ攻撃や国境更新では、ロック順をmap_space_id、chunk_r、chunk_qなどの全処理共通順に固定してデッドロックを抑える。競合時の再試行にはturn_runのseedと冪等キーを使う。

## 不変条件

- 同一map_spaceの同一q、rは最大1セル。
- セルの座標は作成後に更新しない。
- 所有国は同じworldに属する。
- 施設は対応可能な地形にのみ存在する。
- chunk_versionはセル変更と同じトランザクションで増える。
- map_spaceの現在生成境界外に通常セルを確定しない。
- 首都座標は国家存続中に通常処理で所有者変更しない。
- offset座標をDB、API、ゲームルールの正本にしない。

## 要決定事項

- Status: Open / Required before: 国家作成実装前 — 拡張方向の選択、1回の最大拡張量、地形生成algorithm、再現seed。
- Status: Open / Required before: マップAPI実装前 — 未発見領域と非公開属性の応答契約。
- Status: Open / Required before: 国家作成実装前 — 初期Territoryへ水域・建設不能地形を含めるか。
- Status: Deferred / Required before: MVP後 — 領海、地下・地上間移動、宇宙のcoordinate system。

## MVP実装記録（2026-07-26）

`php artisan hakoniwa:world:init`はWorldとsurface MapSpaceをtransaction内で冪等作成し、`q=-30..29`、`r=-30..29`の3,600セルを全て`sea`、owner/facilityなし、population 0で生成する。generator ID、version、seedと完了状態は`world_generation_runs`へ記録する。世界初期化はNation、Capital、Islandを作らない。

新しい範囲も先に海として生成し、その後の国家登録が`InitialIslandGenerator`を呼ぶ境界を維持する。本格的な自動拡張方向・最大量は未決定のままMVP後へ送る。
