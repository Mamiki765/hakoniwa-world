# 箱庭諸島2＋ 原画像インベントリ

調査日：2026-07-26

## 調査範囲と判定方法

`_references/hakoniwa-2plus/assets/hakogif` にある58個を、ファイルを変更せずに原寸表示して確認した。全ファイルは単一フレームのGIFで、合計57,863 bytesである。寸法、GIFヘッダー、ファイル名、見た目を確認し、ゲーム上の意味は可能な限り次の原資料で照合した。

- 地形定数：`map.h:136-164` の `Land::*`
- C側の表示分類：`Land::landValue`, `map.c:1328-1365`
- 実画像選択：`hakow.js:513-624`
- 怪獣名と画像：`hakow.js:68-103`
- 賞名と画像：`hakow.js:105-137,728-755`
- 記念碑名：`hakow.js:139-145,620-623`
- 配置指示：`hakow-readme.txt:80-86`

「確定」は原コード内の実参照まで確認できたもの、「推定」は見た目または命名だけによるもの、「未使用」は今回の原コードから参照を確認できなかったものを表す。見た目だけでdefinitionへ割り当てない。

## マップ地形・施設（17個）

| 元ファイル名 | 画像サイズ・形式 | 推定される地形・施設・怪獣 | C版kindまたは定数 | 新作definition ID候補 | 使用予定 | 判断根拠 | 未確認事項 |
|---|---|---|---|---|---|---|---|
| `land0.gif` | 32×32 GIF89a | 深海 | `Land::Sea`, `SeaDeep` | `terrain.sea.deep` | 使用 | `hakow.js:514-524`で`kind=0,param=0`に指定 | 色覚・縮小時の浅瀬との差 |
| `land1.gif` | 32×32 GIF89a | 通常の荒地 | `Land::Waste`, `WasteNormal` | `terrain.wasteland` | 使用 | `hakow.js:527-533`で`param=0`に指定 | なし |
| `land2.gif` | 32×32 GIF89a | 人口0の平地 | `Land::Town` (`param=0`) | `terrain.plain` | 使用 | `hakow.js:536-550` | なし |
| `land3.gif` | 32×32 GIF89a | 村 | `Land::Town` (`1..29`) | `settlement.village` | 使用 | `hakow.js:541-544`; `Land::landValue`, `map.c:1336-1345` | 人口閾値を新作でも同じにするか |
| `land4.gif` | 32×32 GIF89a | 町 | `Land::Town` (`30..99`) | `settlement.town` | 使用 | `hakow.js:544-547`; `map.c:1336-1345` | 同上 |
| `land5.gif` | 32×32 GIF89a | 都市 | `Land::Town` (`100以上`) | `settlement.city` | 使用 | `hakow.js:547-550`; `map.c:1336-1345` | 同上 |
| `land6.gif` | 32×32 GIF89a | 森 | `Land::Forest` | `terrain.forest` | 使用 | `hakow.js:555-560` | 木の量を別画像にする必要性 |
| `land7.gif` | 32×32 GIF89a | 農場 | `Land::Farm` | `facility.farm` | 使用 | `hakow.js:563-566` | 規模差は原画像1種のみ |
| `land8.gif` | 32×32 GIF89a | 工場 | `Land::Factory` | `facility.factory` | 使用 | `hakow.js:568-571` | 規模差は原画像1種のみ |
| `land9.gif` | 32×32 GIF89a | ミサイル基地 | `Land::Base` | `facility.missile_base` | 使用 | `hakow.js:573-577` | レベル差は原画像1種のみ。敵には森として表示する旧仕様の採否 |
| `land10.gif` | 32×32 GIF89a | 防衛施設／ハリボテ共通 | `Land::DBase`, `DBaseTrue`, `DBaseFalse` | `facility.defense`, `facility.decoy` | 使用 | `hakow.js:579-586`は両paramに同一画像 | 新作で真偽を同じ画像にするか |
| `land11.gif` | 32×32 GIF89a | 山 | `Land::Mountain` (`param=0`) | `terrain.mountain` | 使用 | `hakow.js:588-595` | なし |
| `land12.gif` | 32×32 GIF89a | 海底基地 | `Land::SBase` | `facility.seabed_base` | 使用 | `hakow.js:614-618` | 敵には深海表示する旧秘匿仕様の採否 |
| `land13.gif` | 32×32 GIF89a | ミサイル跡の荒地 | `Land::Waste`, `WasteMissile` | `terrain.wasteland.missile_scar` | 使用 | `hakow.js:527-533`で非0paramに指定 | 新作で通常荒地とdefinitionを分けるか |
| `land14.gif` | 32×32 GIF89a | 浅瀬 | `Land::Sea`, `SeaShoal` | `terrain.sea.shoal` | 使用 | `hakow.js:518-520` | 深海との差が小さいため代替テキスト必須 |
| `land15.gif` | 32×32 GIF89a | 採掘場のある山 | `Land::Mountain` (`param>0`) | `facility.mine` | 使用 | `hakow.js:588-595` | 地形と施設を別definitionへ分離する新作モデルとの対応 |
| `land16.gif` | 32×32 GIF89a | 海底油田 | `Land::Sea`, `SeaOil` | `facility.seabed_oil_field` | 使用 | `hakow.js:521-523` | 新作資源体系での油田の扱い |

## 怪獣（9個）

`Monster` の内部種別は0から7で、`param = kind * 20 + hp`（`Monster::fromParam/toParam`, `monster.c:55-70`）。画像番号と怪獣番号は一致しないため、ファイル名だけで割り当ててはならない。

| 元ファイル名 | 画像サイズ・形式 | 推定される地形・施設・怪獣 | C版kindまたは定数 | 新作definition ID候補 | 使用予定 | 判断根拠 | 未確認事項 |
|---|---|---|---|---|---|---|---|
| `monster0.gif` | 32×32 GIF89a | いのら | `Land::Monster`, monster kind 1 | `monster.inora` | 使用 | `monsterImage[1]`, `hakow.js:68-92` | 新作表示名の継承可否 |
| `monster1.gif` | 32×32 GIF89a | レッドいのら | monster kind 3 | `monster.red_inora` | 使用 | `monsterImage[3]`, `hakow.js:68-92` | 同上 |
| `monster2.gif` | 32×32 GIF89a | ダークいのら | monster kind 4 | `monster.dark_inora` | 使用 | `monsterImage[4]`, `hakow.js:68-92` | 同上 |
| `monster3.gif` | 32×32 GIF89a | キングいのら | monster kind 7 | `monster.king_inora` | 使用 | `monsterImage[7]`, `hakow.js:68-92` | 同上 |
| `monster4.gif` | 32×32 GIF89a | 硬化中の共通画像 | monster kind 2または6の硬化状態 | `monster.state.hardened` | 使用 | `monsterImage2[2]`と`[6]`, `hakow.js:98-103,599-610` | 2種が同一画像でよいか |
| `monster5.gif` | 32×32 GIF89a | サンジラ | monster kind 2 | `monster.sanjira` | 使用 | `monsterImage[2]`, `hakow.js:68-92` | 新作表示名の継承可否 |
| `monster6.gif` | 32×32 GIF89a | クジラ | monster kind 6 | `monster.kujira` | 使用 | `monsterImage[6]`, `hakow.js:68-92` | 同上 |
| `monster7.gif` | 32×32 GIF89a | メカいのら | monster kind 0 | `monster.mecha_inora` | 使用 | `monsterImage[0]`, `hakow.js:68-92` | 同上 |
| `monster8.gif` | 32×32 GIF89a | いのらゴースト | monster kind 5 | `monster.inora_ghost` | 使用 | `monsterImage[5]`, `hakow.js:68-92` | 同上 |

## 記念碑（3個）

| 元ファイル名 | 画像サイズ・形式 | 推定される地形・施設・怪獣 | C版kindまたは定数 | 新作definition ID候補 | 使用予定 | 判断根拠 | 未確認事項 |
|---|---|---|---|---|---|---|---|
| `monument0.gif` | 32×32 GIF89a | 記念碑共通（見た目はモノリス） | `Land::Monument`, param 0..2 | `facility.monument.monolith`, `.peace_tower`, `.war_memorial` | 使用 | `hakow.js:139-145,620-623`は全種類にこの画像 | 種類別新規画像を将来用意するか |
| `monument1.gif` | 32×32 GIF89a | 平地と同一内容 | 原コード参照なし | 割当なし | 保留 | SHA-256が`land2.gif`と一致し、コード参照なし | 同梱意図。未完成の差替え枠か |
| `monument2.gif` | 32×32 GIF89a | 平地と同一内容 | 原コード参照なし | 割当なし | 保留 | SHA-256が`land2.gif`と一致し、コード参照なし | 同上 |

## 賞アイコン（12個）

これらはマップチップではなく、順位・実績表示用の16×16アイコンである（`hakow.js:728-755`）。新作で同じ賞を採用する場合に限り、外部配置から利用する。

| 元ファイル名 | 画像サイズ・形式 | 推定される地形・施設・怪獣 | C版kindまたは定数 | 新作definition ID候補 | 使用予定 | 判断根拠 | 未確認事項 |
|---|---|---|---|---|---|---|---|
| `prize0.gif` | 16×16 GIF89a | 100ターン杯 | `prize` bit 0 | `achievement.turn_100` | 条件付き | `prizeName/prizeImage[0]`, `hakow.js:105-137` | 同賞を新作で採用するか |
| `prize10.gif` | 16×16 GIF89a | 300ターン杯 | bit 1 | `achievement.turn_300` | 条件付き | 配列index 1 | 同上 |
| `prize11.gif` | 16×16 GIF89a | 1000ターン杯 | bit 2 | `achievement.turn_1000` | 条件付き | 配列index 2 | 同上 |
| `prize1.gif` | 16×16 GIF89a | 繁栄賞 | bit 3 | `achievement.prosperity` | 条件付き | 配列index 3 | 条件値の新作仕様 |
| `prize2.gif` | 16×16 GIF89a | 超繁栄賞 | bit 4 | `achievement.prosperity_great` | 条件付き | 配列index 4 | 同上 |
| `prize3.gif` | 16×16 GIF89a | 究極繁栄賞 | bit 5 | `achievement.prosperity_ultimate` | 条件付き | 配列index 5 | 同上 |
| `prize7.gif` | 16×16 GIF89a | 災難賞 | bit 6 | `achievement.calamity` | 条件付き | 配列index 6 | 同上 |
| `prize8.gif` | 16×16 GIF89a | 超災難賞 | bit 7 | `achievement.calamity_great` | 条件付き | 配列index 7 | 同上 |
| `prize9.gif` | 16×16 GIF89a | 究極災難賞 | bit 8 | `achievement.calamity_ultimate` | 条件付き | 配列index 8 | 同上 |
| `prize4.gif` | 16×16 GIF89a | 平和賞 | bit 9 | `achievement.peace` | 条件付き | 配列index 9 | 同上 |
| `prize5.gif` | 16×16 GIF89a | 超平和賞 | bit 10 | `achievement.peace_great` | 条件付き | 配列index 10 | 同上 |
| `prize6.gif` | 16×16 GIF89a | 究極平和賞 | bit 11 | `achievement.peace_ultimate` | 条件付き | 配列index 11 | 同上 |

## 旧UI部品・用途未確定（17個）

| 元ファイル名 | 画像サイズ・形式 | 推定される地形・施設・怪獣 | C版kindまたは定数 | 新作definition ID候補 | 使用予定 | 判断根拠 | 未確認事項 |
|---|---|---|---|---|---|---|---|
| `black.gif` | 16×32 GIF87a | 六角行の黒い半幅余白 | なし | 割当なし | 不使用 | `hakow.js:498-502,645-648`で行ずらしに使用 | なし。新UIは座標投影で配置 |
| `space.gif` | 16×32 GIF87a | 海背景の半幅余白 | 原コード参照なし | 割当なし | 不使用 | 見た目・寸法。検索で参照なし | 旧版UIでの用途 |
| `space0.gif` | 16×32 GIF89a | 海背景の座標0 | 原コード参照なし | 割当なし | 不使用 | 見た目・連番。検索で参照なし | 旧版UIでの用途 |
| `space1.gif` | 16×32 GIF89a | 海背景の座標1 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space2.gif` | 16×32 GIF89a | 海背景の座標2 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space3.gif` | 16×32 GIF89a | 海背景の座標3 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space4.gif` | 16×32 GIF89a | 海背景の座標4 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space5.gif` | 16×32 GIF89a | 海背景の座標5 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space6.gif` | 16×32 GIF89a | 海背景の座標6 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space7.gif` | 16×32 GIF89a | 海背景の座標7 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space8.gif` | 16×32 GIF89a | 海背景の座標8 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space9.gif` | 16×32 GIF89a | 海背景の座標9 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space10.gif` | 16×32 GIF89a | 海背景の座標10 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `space11.gif` | 16×32 GIF89a | 海背景の座標11 | 同上 | 割当なし | 不使用 | 同上 | 同上 |
| `spacep.gif` | 16×32 GIF89a | 平地背景の半幅余白 | 原コード参照なし | 割当なし | 不使用 | 見た目・寸法。検索で参照なし | 旧版UIでの用途 |
| `xbar.gif` | 400×16 GIF89a | 海背景の横座標バー0..11 | 原コード参照なし | 割当なし | 不使用 | 見た目。検索で参照なし | 旧版UIでの用途 |
| `f02.gif` | 32×32 GIF89a | 茶・紫系の装飾物（用途不明） | 原コード参照なし | 割当なし | 保留 | 原寸目視と検索で参照なし | 正式名称、由来、旧版での用途 |

## 確定事項と推測の境界

確定事項は、58個すべてが単一フレームGIFであること、上表の寸法・ヘッダー、`land*`・`monster*`・`monument0`・`prize*`の原コード上の対応である。`space*`、`xbar.gif`、`f02.gif`、`monument1.gif`、`monument2.gif`の制作意図は原コードだけでは確定できない。これらへ新作definitionを割り当てるには追加根拠が必要である。

## 原本保護

画像ファイル名、内容、形式は変更しない。画像はGit管理下の`product/public/`、`product/resources/`、`docs/`へコピーせず、実行環境へ外部配置する。論理対応と配置契約は`tile-asset-mapping.md`を正本とする。
