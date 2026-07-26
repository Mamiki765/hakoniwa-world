# 箱庭諸島2＋ ビルド・実行環境

## 結論

**確定**：これは古いC++方言で書かれた、単一プロセス・単一実行ファイルのCGIである。ファイル拡張子は `.c` だが、コンパイルとリンクの双方に `g++` を使う。今回コンパイルは実行していない。

## ビルド定義

| 項目 | 内容 | 根拠 |
| --- | --- | --- |
| コンパイラ | `g++` | `Makefile:3` (`CC`) |
| リンカー | `g++` | `Makefile:4` (`LINKER`) |
| 出力 | `hakow.cgi` | `Makefile:1,15-16` (`TARGET`) |
| コンパイルオプション | `-c -O2 -Wall` | `Makefile:13,18-19` (`CFLAGS`) |
| ローカル用候補 | `-c -O4 -Wall -DLOCAL`。コメントアウト済み | `Makefile:12` |
| 明示ライブラリ | なし | `Makefile:15-16` |
| clean | `rm` でオブジェクトとCGIを削除 | `Makefile:21-22` |

ソースは `main.c`, `hako_io.c`, `template.c`, `value.c`, `mentenance.c`, `info.c`, `map.c`, `sight.c`, `new_island.c`, `util.c`, `toppage.c`, `command.c`, `owner.c`, `turn.c`, `monster.c`, `rename.c` の17本（`Makefile:6-9`）。

明示的な `-l...` はないが、`g++` がC++ランタイム/標準ライブラリを暗黙にリンクし、OS側ではC/POSIX APIを利用する。別途のゲーム用第三者ライブラリはソース上確認できない。

## 必要と見込まれる環境

**確定**：ソースは標準化前の `iostream.h` / `fstream.h` を使い、POSIX系の `unistd.h`, `sys/file.h`, `sys/stat.h`, `dirent.h` と `open`, `flock`, `close`, `unlink`, `rename`, `mkdir`, `rmdir`, `opendir` 等へ依存する（`hako_io.h:4-10`; `util.h:4-7`; `value.h:4-8`; `mentenance.h:4-7`; `util.c:123-143`; `mentenance.c:38-175`）。

**推測**：次段階の隔離Dockerでは、現代のWindowsネイティブ環境より、古いGNU C++との互換性を検証できるLinux/POSIX環境が適する。標準化前ヘッダーは現代のGCCではそのまま利用できない可能性が高く、まず原版相当のコンパイラ世代を固定するか、検証専用の最小互換パッチを別コピーへ適用する方針が必要である。原本と今回の展開物へは変更を加えない。

文字列リテラルとコメントはShift_JIS系で、レスポンスもShift_JISを宣言する（`Template::header`, `template.c:14-16`; `Util::cutColumn`, `util.c:67-97`）。コンパイラ入力文字コード、ロケール、CGIサーバーから渡るバイト列を同じ系統に合わせる必要がある。

## 設置形態と権限

設置マニュアルは、CGI実行可能ディレクトリへ `hakow.cgi` と `config.cgi` を置き、`hakow.cgi` を実行可能にし、`config.cgi` を他者から読めない権限にするよう指示する（`hakow-readme.txt:52-77`）。画像と `hakow.js` は通常の公開ディレクトリへ置く（同 `80-86`）。

`Mentenance::makeData` は `dirName` のディレクトリを `dirMode` で作成する（`mentenance.c:38-53`）。同梱設定は `dirName=data`, `dirMode=705` である（`config.cgi:22-29`）。先頭ゼロのない `705` も `Value::input` が `%o` で読むため8進数として解釈される（`value.c:105-108`）。

**設計上の注意**：国家パスワードと管理者パスワードは平文ファイル・平文比較である（`config.cgi:4-8`; `Island::output`, `info.c:159-177`; `Util::passCheck`, `util.c:99-120`）。現代環境へ持ち込まない。

## CGI入力

`main` は最初に `HakoIO::cgiInput` を呼ぶ（`main.c:7-10`）。入力は次の通り。

- `CONTENT_LENGTH` が存在すれば標準入力からPOST本文を読む。8,192 bytes超は拒否する（`HakoIO::cgiInput`, `hako_io.c:243-258`）。
- それ以外は `QUERY_STRING` をGET入力として読む（`hako_io.c:259-267`）。
- `+`, `%XX`, `=`, `&` を独自デコーダーで解釈し、最大16項目を固定配列へ格納する（`hako_io.h:25-26`; `hako_io.c:275-339`）。
- `MenteMode`, `MapMode`, `OwnerMode`, `CommandMode`, `NewIslandMode`, `RenameMode`, `TurnMode`, `MesMode*`, `Activate*`, `Second`, `MakeData`, `BackUp`, `ChangeTime`, `Delete*`, `cname`, `IslandName`, `Message`, `Password*`, `PointX`, `PointY`, `Island`, `CommandList` を認識する（`hako_io.c:346-444`）。
- Cookieは `HTTP_COOKIE` から読み、島IDとパスワードをJavaScript変数 `did`, `dpass` として出力する（`HakoIO::cookieInput`, `hako_io.c:154-240`）。

`REQUEST_METHOD` や `CONTENT_TYPE` を検証せず、`CONTENT_LENGTH` の有無だけでPOST/GETを分ける。16項目を超えると `count` を0へ戻す処理があり、拒否ではなく先頭領域を再利用する（`hako_io.c:315-318`）。これらは新作の入力仕様として継承しない。

## CGI出力

`HakoIO::out` は `cout` へ直接書く（`hako_io.c:14-21`）。`Template::header` がCookieヘッダー、`Content-type: text/html`、Shift_JIS指定のHTML、外部 `hakow.js`、インラインJavaScript開始部を出し、各モードが `mapData`, `infoData`, `comData`, `logData` 等をJavaScriptリテラルとして出力する。`Template::footer` が `main();` を実行してHTMLを閉じる（`template.c:3-30`; `Map::jsOut`, `map.c:50-67`; `Info::jsOut`, `info.c:145-156`; `Command::jsOut`, `command.c:50-60`）。

これはJSON APIではなく、HTML内へ実行可能JavaScriptデータを埋め込む方式である。

## 入口とターン起動

`main()` は乱数を現在秒で初期化し、入力、設定、ヘッダー、Cookie、ロック、`info.cgi` 読込を行う（`main.c:3-23`）。メンテナンス以外では、現在時刻が `lastTime + unitTime` を超え、ゲームが開始済み・未終了なら、そのリクエスト自体をターン更新へ切り替える（`main.c:25-41`）。

ターン開始時は `lastTime += unitTime` を一度だけ行う（`main.c:72-76`）。**確定**：遅延分を `while` で一括追いつきする実装ではなく、CGIアクセス1回につき最大1ターンである。

## 排他制御

`main` は情報読込前に `Util::lock` を呼び、多くの応答経路が `Util::unlock` する（`main.c:21-23`; `util.c:123-143`）。`LOCAL` 未定義時は、データディレクトリ自体を `open(..., O_RDWR)` して `flock(..., LOCK_EX)` する。

**コード上の問題候補**：通常のPOSIX `flock` は成功時0、失敗時-1を返すが、実装は戻り値が非0なら「成功」、0なら `exit(0)` としている（`Util::lock`, `util.c:124-133`）。したがって通常仕様では成功時に終了する逆判定である。さらに `open` 失敗の検査もない。コンパイル・実行段階で必ず隔離確認する。

## データバックアップと復旧

定期バックアップは `Info::turn % backUpTurn == 0` のターン末に `Mentenance::slideBack` を呼ぶ（`turn.c:145-148`）。`data.bakN` をローテーションし、現役ディレクトリ内で末尾が `cgi` のファイルだけをテキスト行コピーする（`mentenance.c:107-151,163-175`）。手動バックアップも同じ処理を使う（`mentenance.c:101-105`）。

復旧は現役データを先に削除し、選んだバックアップディレクトリを `rename` して現役化する（`Mentenance::activateData`, `mentenance.c:89-99`）。

**コード上の問題候補**：コピーは空行を捨て、個々の出力を一時ファイル経由で原子的に置換せず、現役削除後のrename失敗にも復旧処理がない（`mentenance.c:119-151,89-99`）。新作ではスナップショット整合性、検証、原子的切替、復旧失敗時のロールバックが必要である。

## 次段階の隔離環境で確認すること

1. 原版を変更せずビルドできるGNU C++/libstdc++世代。
2. `iostream.h` / `fstream.h` とPOSIX APIの提供状況。
3. Shift_JIS/CP932入力ソースと実行時ロケール。
4. CGIサーバーが渡す環境変数とPOST標準入力。
5. ディレクトリへの `open(O_RDWR)` と `flock` の実挙動、および逆判定の再現。
6. 実行ユーザーに必要なCGI、設定、データ、バックアップ各権限。
7. 明示ライブラリなしでリンクできるか。
