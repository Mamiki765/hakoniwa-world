# UIとマップ読み込み

## 状態

MVPのAPI prefixは`/api/v1`、map responseは可読なJSON、取得単位は辺長16のchunkとする。CanvasかDOMか、未発見領域の契約、SPA session方式、配信originは実装前に決める。

## 目標

ログイン後、自国の首都周辺をすぐ表示し、スクロールやズームに応じて必要なチャンクだけを取得する。世界全体や固定15×15配列をHTMLへ埋め込まない。

## 初期表示

1. DiscordまたはGoogleで認証済みのUser sessionを確認する。Sanctum採用の有無は認証実装前に決める。
2. Userが対象Worldに持つNationを内部`user_id`で解決し、Nation stateとCapitalのq、rを取得する。
3. viewportのピクセル寸法、zoom、端末密度から必要セル範囲を計算する。
4. 交差するchunk一覧を求め、未取得分を要求する。
5. 首都へカメラを合わせ、受信済みchunkから順に描画する。

国家を持たない利用者は登録導線へ移す。dormant_frozenまたはdormant_contestableでは休眠状態、停止機能、復帰結果を説明し、本人の有効な利用または復帰操作でactiveへ戻す。sunken_archivedでは旧領土を戻さず、将来の再入植導線を表示する。capital情報が破損している場合は原点へ黙って移動せず、回復可能なエラーとして扱う。

## API境界

MVPのversion prefixは`/api/v1`とし、次のresource構成を基準に詳細DTOをマップAPI実装前に確定する。

- `GET /api/v1/worlds/{world}/map-bootstrap`
- `GET /api/v1/worlds/{world}/map-spaces/{mapSpace}/chunks?minQ=&maxQ=&minR=&maxR=`
- `GET /api/v1/worlds/{world}/nations/{nation}/summary`

bootstrapはCapital座標、World・ruleset version、生成済み境界、catalog版、必要な小さなNation表示辞書を返す。セル本体はchunk応答へ分離する。World全体または地上全体を1 responseで返さない。

各chunk応答はchunk coordinate、version、absolute axial `q`・`r`を持つ可読なJSONとする。ETagとIf-None-Matchを利用し、同じ版の再転送を避ける。隣接chunkを少量先読みするが、低速回線では抑制する。compact array、binary、独自圧縮はMVP後へ延期する。

commandとeventのendpointはMVP縦切りに含めない。導入前に`docs/open-questions.md`のコマンド・ターン項目を決定してから`/api/v1`へ追加する。

## クライアント状態

Vue採用時はPinia等のstoreを次の責務へ限定する。

- cameraと選択セル。
- chunk keyから描画DTOへのcache。
- 読込中、失敗、再試行状態。
- World・ruleset versionとchunk versionの整合。

将来commandを追加しても、ゲームルール、費用の最終判定、命中抽選、Territory変更をclientで確定しない。画面上の予測はpreviewとして明示し、server結果を正本とする。

## 描画方式

DOM imageをセルごとに並べる方式は初期規模では可能だが、表示セル数・アニメーション・ズームで限界がある。候補を小さなprototypeで比較する。

| 方式 | 向く状況 | 注意点 |
|---|---|---|
| DOM/CSS grid | アクセシビリティ、少数セル、実装単純性 | 多数node、15列等の固定CSSを避ける |
| Canvas 2D | 多数セル、パン・ズーム、sprite描画 | hit testとアクセシビリティを別実装 |
| WebGL | 非常に広い表示、効果 | 複雑性が高く初期採用根拠がない |

暫定推奨はCanvas 2Dを候補としつつ、操作パネルと読み上げ用セル情報はDOMで提供する。箱庭諸島2＋に存在する地形・施設・怪獣等は、Git外へ原名・原形式で配置した原GIFを使用する。新規要素は既存GIFを流用せず、placeholderから将来の新規画像へ差し替える。やまにてぃ等の出典不明画像は使用しない。

## 画像解決とfallback

各definitionは`asset_key`を持ち、versioned manifestが原ファイル名へ解決する。definition、DB、APIへホスト絶対パスやbase64を保存しない。実行環境のbase pathとbase URL、read-only mount、確認済み対応は`docs/assets/tile-asset-mapping.md`を正本とする。

画像が欠落・不正・読取不能でもセル取得やゲーム処理を失敗させない。画像URLを出さず、短い地形名またはIDとCSS patternの代替タイルを表示する。配信後のload errorでも壊れた画像アイコンを残さず同じfallbackへ切り替える。ログはasset key単位で集約し、表示セル数だけ重複出力しない。

## 座標と操作

DB、API、ゲームルールの正本はADR-0003のsigned axial q、rである。Vueは旧作の32px正方形tileに合わせ、偶数行を右へ16pxずらすstaggered row projectionを表示専用に使う。

- row = r
- column = q + floorDiv(r + 1, 2)
- screenX = column * 32 + (floorMod(row, 2) === 0 ? 16 : 0)
- screenY = row * 32
- q = column - floorDiv(row + 1, 2)
- r = row

column、row、pixel位置、配列indexはAPIへ送らない。クリック位置は共通変換moduleでaxial q、rへ戻してからcommand targetへ設定する。UI上でx、yという呼称を使う場合は、axial q、rの表示名かpixel座標かを明記し、offset座標を曖昧にx、yと呼ばない。

parity、floorDiv、floorModはJavaScriptの剰余や切捨て除算へ直接依存させない。PHP側とTypeScript側で同じ正負座標fixtureを共有し、往復変換、6方向の隣接、距離、チャンク境界を一致させる。

Roadmap PR2では次の命令作成flowの1から4とqueue編集UIを実装する。5の実行結果はturn runnerまで延期する。

1. セルを選択し、可能な命令候補を表示する。
2. 対象、費用見積り、予定turn、公開範囲を確認する。
3. client_request_id付きで登録する。
4. serverのacceptedまたはvalidation errorを表示する。
5. ターン後、domain eventから結果を通知する。

## 整合性と更新

MVP縦切りにはturn処理がないため、turn完了通知とWebSocketを実装しない。turn導入時にpollingとWebSocketを比較する。通知を追加する場合も「新しいturnと変更chunk keyがある」ことだけを伝え、正本データはAPIで再取得する。

同じviewport取得中にturnが切り替わった場合、応答ごとのturn_numberを検査する。混在を許すならセルに更新turnを表示し、許さないなら新turnで必要chunkを再取得する。最終方針はUXと負荷試験で決める。

## 大規模世界への対策

- viewport外chunkはLRU等でメモリから解放する。
- 国家一覧やログはcursor paginationを使う。
- 低zoom集約tileはMVP後とし、最初は表示cell数へ上限を設ける。
- 検索結果から遠隔地へ移動しても、必要chunkだけ取得する。
- world境界と未生成領域を描画上区別する。

## アクセシビリティ

色だけで所有国や被害を表さない。選択セルには地形、施設、所有者、座標、人口、操作候補のテキスト表現を提供する。キーボードで六方向移動できるようにし、アニメーション軽減設定、十分なcontrast、通知のARIA live領域を検討する。

## エラー表示

- chunk取得失敗: 既存表示を保持し、対象範囲だけ再試行可能にする。
- version競合: command draftを保持し、最新セルで再検証する。
- 境界拡張中: 未生成と障害を区別し、短時間の再取得を案内する。
- 認証失効: draftをlocalに一時保持し、再認証後に再検証する。

## 要検証

- 典型viewportと最大zoom out時のセル数。
- CanvasとDOMの性能・操作性。
- 原GIFのpixel scaling、補間無効化、high-DPI表示。
- chunk sizeとprefetch量。
- staggered row投影の負座標、往復変換、pixel境界のcontract test。
- 霧・未発見領域を導入するか。
- モバイルでのパンとセル選択の競合。
- provider login・link後のredirect UX。
- 未発見領域と非公開属性のAPI契約。
- CanvasまたはDOMの採否とアクセシビリティ最低要件。

## MVP実装記録（2026-07-26）

MVPはVue 3のDOM/CSS rendererを採用し、Roadmap PR2でaxial/staggered projectionへ更新した。Capital周辺9 chunksだけを初期取得し、zoom、drag/pointer pan、セル選択、六方向keyboard移動、loading/error/empty chunkを扱う。viewport外cellはpan/zoom後の画面位置で除外し、不要なDOM nodeを生成しない。

選択セルはq/r、公開terrain、公開facility、ownerとserverのdetail descriptorを通常HTML textでも表示する。居住人口、森林量、施設規模、基地経験値を別の意味として扱い、0人口を全cellへ表示しない。基地の非所有者向け表現とARIAはserverで森へ置換する。Canvas置換、低zoom集約、WebSocketは計測後の後続PRとする。
