# Reference source inventory

記録日時：2026-07-26T09:20:36+09:00

この文書は、設計調査に使うローカル参考資料の所在と同一性を追跡するための目録です。`_references/` 以下は読み取り専用であり、Git管理しません。

## 配置結果

| 参考資料 | ローカル配置 | ファイル数 | 合計容量 | 備考 |
| --- | --- | ---: | ---: | --- |
| 箱庭諸島2＋資料 | `_references/hakoniwa-2plus/source` | 3 | 64,736 bytes | 移動前後で一致 |
| 箱庭諸島2＋画像 | `_references/hakoniwa-2plus/assets/hakogif` | 58 | 57,863 bytes | 移動前後で一致 |
| やまにてぃ | `_references/yamanity/repository` | Git clone | - | `main`、変更なし |

原資料と画像はrepository外の作業場所から`_references/`配下へ集約し、移動前後の内容一致を確認しました。個人環境の元pathは公開文書へ記録しません。

## 箱庭諸島2＋

### 直下の原資料

- `hakow-readme.txt` — 暫定版設置マニュアル、使用条件、配布条件、作者表示
- `hakow094.tar` — Cソース一式の原アーカイブ
- `jcode.pl` — 同梱Perlライブラリ

Phase 1開始時に原本のSHA-256を記録し、安全な相対ファイル名35件であることを確認したうえで、`_references/hakoniwa-2plus/extracted` へ展開しました。原本と展開物は引き続き読み取り専用です。展開前後のハッシュ、容量、ファイル名、文字コード、改行、タイムスタンプは `hakoniwa-2plus-overview.md` に記録しています。

### `hakow094.tar` の主要内容

- ビルド・設定：`Makefile`、`config.cgi`
- エントリーポイント：`main.c`、`main.h`
- コマンド：`command.c`、`command.h`
- 入出力：`hako_io.c`、`hako_io.h`
- マップ：`map.c`、`map.h`
- ターン：`turn.c`、`turn.h`
- 新規島：`new_island.c`、`new_island.h`
- 所有・名称：`owner.c`、`owner.h`、`rename.c`、`rename.h`
- 怪獣：`monster.c`、`monster.h`
- 表示：`info.c`、`info.h`、`sight.c`、`sight.h`、`toppage.c`、`toppage.h`
- テンプレート：`template.c`、`template.h`
- 保守：`mentenance.c`、`mentenance.h`（原ファイル名の綴りを維持）
- 共通処理・値：`util.c`、`util.h`、`value.c`、`value.h`
- ブラウザ側補助：`hakow.js`

アーカイブ内は合計35ファイルです。Cソース、説明書、アーカイブ、設定ファイルの文字コード・改行・内容は変更していません。

### 画像

58個のGIFを原名のまま配置しています。主な命名群は `land*.gif`、`monster*.gif`、`monument*.gif`、`prize*.gif`、`space*.gif` です。画像バイナリは公開Gitリポジトリへ収録せず、箱庭諸島2＋に対応する既存要素のUIではGit外の実行時assetとして読み取り使用します。全件調査は`docs/assets/hakoniwa-original-tile-inventory.md`、論理対応と配置契約は`docs/assets/tile-asset-mapping.md`に記録します。

## やまにてぃ

- Repository: <https://github.com/mjtakenon/hakoniwa>
- Clone URL: `https://github.com/mjtakenon/hakoniwa.git`
- ローカル配置：`_references/yamanity/repository`
- ブランチ：`main`
- コミット：`ac4edce07784eb391ab7a56f1a833ca25e3597c8`
- clone日時（直後確認時刻）：2026-07-26T09:20:36+09:00
- clone後の状態：`main...origin/main`、作業ツリー変更なし

ルートの主要項目は `.github/`、`app/`、`infra/`、`.gitignore`、`docker-compose.yml`、`Makefile`、`package.json`、`README.md`、`yarn.lock` です。Laravelの `composer.json` は `app/composer.json` にあります。

依存パッケージのインストール、Docker起動、ビルド、参考ファイルの変更は行っていません。
