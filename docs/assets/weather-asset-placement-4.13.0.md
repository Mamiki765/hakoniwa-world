# 4.13.0 天候GIFの外部配置

既存の[外部tile asset運用](tile-asset-mapping.md)を使う。今回のPRは対応表・読み取り配信・
UIを追加し、本番のファイル配置は行っていない。GIFバイナリはGitと`product/public`へ置かない。

承認済み素材は `hakoniwa-weather-24px-gifs.zip` version 2。
ZIPのSHA-256は `4d2f3ab17e2e8aca9339c644f0be94e14bc1e33b37573ce1f0bbc2d58e234217`。
24×24px、透明背景、各1フレームのstatic GIFを原ファイル名のまま使う。
比較画像・README・manifestは配信しない。箱庭諸島2＋の原画像とは別の今回承認された新規素材。

| 天候key / asset key | ファイル | 意匠 |
|---|---|---|
| sunny / weather.sunny | weather-sunny.gif | 太陽 |
| cloudy / weather.cloudy | weather-cloudy.gif | 雲 |
| rain / weather.rain | weather-rain.gif | 傘 |
| snow / weather.snow | weather-snow.gif | 雪結晶 |
| thunder / weather.thunder | weather-thunder.gif | ピンクの雷 |
| typhoon / weather.typhoon | weather-typhoon.gif | 台風 |
| meteor_shower / weather.meteor_shower | weather-shooting-stars.gif | 緑の双星 |
| huge_meteor / weather.huge_meteor | weather-doomsday.gif | 赤い燃える尾 |

## リリース時の配置手順

1. 承認済みZIP version 2のhashを確認し、作業ディレクトリに展開する。
2. アプリ適用より先に、上表の8 GIFだけを既存assetディレクトリへ同名で追加する。
   本番で確認したhost側は`/srv/bot-assets/hakoniwa`、container側の
   `HAKONIWA_TILE_ASSET_PATH`は`/srv/hakoniwa-assets/tiles`で、read-only mountされている。
   既存の同名fileがあればhashを照合し、一致しなければ上書きせず停止する。
   既存GIFの置換、再エンコード、ビルド成果物への取り込みは不要。
3. 既存`HAKONIWA_TILE_ASSET_BASE_URL`（既定`/assets/hakoniwa-tiles`）を維持する。
   allowlist・実MIME・正方形検査を通った画像にだけURLが付く。URLはmtime/sizeでversion化される。
4. 先行配置後に既存asset HTTP配信の200・`Content-Type: image/gif`・24×24 GIF形式/hashを確認する。
   旧アプリのallowlistは新天候名をまだ受理しないため、アプリrouteの確認は切替後に行う。
   切替後に各`/assets/hakoniwa-tiles/<ファイル名>`が200・`Content-Type: image/gif`で読み取れることと、
   マップの8天候アイコン・「海域・天候」toggleを確認する。欠落時は天候文字が表示され、Turnは処理できる。

UIはnearest-neighborで画面上12×12pxに縮小する。ズーム・全体表示でも画面サイズを維持する。
light/dark/skyblue/autumn/blackで薄い天候filterと外周線を重ねる。セル/施設の行高は変更しない。

ローカルではLibraryからWindowsのconsumer workspaceへ素材を取得し、ZIPのhash・8 GIFの
24×24ヘッダ・比較画像を確認した。素材の本番配置、merge、deployは別のリリース操作として残る。
