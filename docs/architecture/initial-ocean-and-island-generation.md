# 全面海Worldと初期島生成

## 世界初期化

`OceanWorldGenerator`は`q=-30..29`、`r=-30..29`の3,600セルを`sea`として生成する。owner、facilityはnull、populationは0である。World、surface MapSpace、16 chunks、catalog、cells、generation runは1 transactionで作成し、同じgenerator ID/version/seedの完了記録があれば増殖させない。

世界初期化時にNation、Island、Capital、Village等を作らない。国家0件で全面海は正常状態である。

## 確認した旧作生成規則

箱庭諸島2＋の解析文書と`new_island.c`から、初期島は固定円形ではなくseed依存の成長形であることを確認した。

1. 中心からdistance 5相当の91セルが中立である地点を探す。
2. 中心からdistance 2以内を荒地にする。
3. distance 4以内で100回、隣接陸地がある候補を海→浅瀬→荒地→平地の順に成長させる。
4. 森3、村2（各人口500）、山1、ミサイル基地1を配置する。
5. 国家人口合計1,000、初期資金100、旧作初期食料100とする。

distance 5は全陸地半径ではなく配置に必要な空白・予約範囲である。成長結果は確率的なので「正確な形状」はgenerator手順とseedで定義され、単純な全面平地や円形への推測置換はしていない。

## 新作MVPの差分

- 旧作の村2つのうち中心側1つを新施設Capitalへ置換する。
- Capital populationを1,000とし、通常Villageは1つ残す。
- 森3、山1、ミサイル基地1を維持する。
- 旧作初期食料100は初期小麦100へ読み替え、魚と肉は0とする。
- Capitalからdistance 2以内の陸地19セルだけを初期Territoryにする。
- distance 2外の生成陸地は中立のまま許容する。
- Capital assetは`hakoniwa_new.capital`のCSS placeholderで、原作GIFを流用しない。

`LegacyInspiredInitialIslandGenerator`は保存したseedで決定的に再現でき、generator ID/version/seedを記録する。予約範囲をrow lock後に再検査し、途中失敗時はNationを含む全変更をrollbackする。
