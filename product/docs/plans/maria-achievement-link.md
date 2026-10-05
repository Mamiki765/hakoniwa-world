# 箱庭実績・肩書きとMariachangの取得連携（草案）

確認日: 2026-10-06。箱庭 `main` @ `1ecf8265eca44a638f031ff6ffee219b958f912a`、Mariachang `master` @ `7a7d984230311ec381ba0f3561951baea1352996`を起点に調査。以下の連携は未実装・未導入で、実DB付与・Discord通知は行っていない。

## 今回の箱庭実装

初めて秘書を命名したUserが、実績「島の秘書」と肩書き「島の秘書」を一緒に取得する。条件と表示名はcanonical Ruleset v33 draftの`user_achievements.island_secretary`に置く。production適用済みv32のpayload・checksumは変更しない。

`SecretaryNamingService::name`の既存transactionと秘書row lockを使う。命名と同じtransactionで`user_achievements`へ実績key・肩書きkey・取得日時を1行保存し、`unique(user_id, achievement_key)`で二重取得を防ぐ。改名では付与処理を呼ばない。取得日時は今回の初回命名の`named_at`。保存に失敗したら命名もrollbackする。

User所有なので、島の破棄・再登録から独立して保持する。`GET /api/v1/me`が取得済み実績・肩書きを返し、オプションの「実績と肩書き」に二つのリストを表示する。肩書きの選択・装着・公開プロフィールへの表示は追加していない。

新migrationはschema追加と既存のWorld lock・未解決Turn guardを使うv32→v33移行のみ。既に命名済みのUserの補填は実装していない。このDraftは新releaseの作成・公開を意味せず、アプリversionは現行値のまま。導入releaseのversion決定は別途必要。

## 現行の接続と保存

- 箱庭の`auth_identities`には`provider='discord'`の`provider_user_id`がある。これをMariaのDiscordユーザーIDへ照合できる。表示名・メールアドレスでは照合しない。GoogleのみのUserは、既存のDiscord連携操作が必要。IDは通常の公開APIへ出さない。
- Mariaの既存envの非秘密設定では`GUILD_IDS`に雨宿り`1025416221757276242`が含まれ、`MEE6_GUILD_ID`も同じ。既存のSSH経路で変数名とguild設定のみ確認した。秘密値は表示・コピー・commitしていない。
- Mariaは`main.mjs`にDiscord Client、GuildMembers intent、Expressがあり、`utils/achievements.mjs`が実績保存・通知を担う。`user_achievements`はDiscord ID主キーのJSONBで、`unlocked`・`progress`・`hidden_unlocked`を保存する。実績は`constants/achievements.mjs`に定義される。
- Maria masterには肩書き保存・選択・表示の基盤が見つからなかった。Embedの`title`はメッセージの表題で、取得した肩書きではない。
- Maria [Draft PR #21](https://github.com/Mamiki765/Mariachang/pull/21)は`/hakoniwa`使用時に実績150「島主」を得る別の変更で、箱庭の取得結果は検証していない。未mergeのこのPRは変更しない。「島の秘書」のMaria IDを採用するなら150との衝突を避ける。
- 既存通知は`config.achievementNotification`のpublic/dm/noneを使う。production publicの設定先は`1421521075337953321`。そのchannelの雨宿り所属・送信権限・採用可否は今回APIで検証していない。

## 最小連携案（未実装）

データの流れは「箱庭の取得済み行 → Discord紐づけ → Mariaの単一所属確認 → Maria実績の保存 → 通知」。箱庭の命名transaction内で外部APIを呼ばず、外部障害でも箱庭の実績・肩書きは保持する。

1. 箱庭が保存した実績keyと既存Discord紐づけを、認証付きのMaria専用受信処理へ渡す。任意のプレイヤーがDiscord IDを指定して取得するAPIにはしない。安定した冪等keyは`箱庭User ID + 実績key`とし、改名や再送で変えない。名前は判定に使わない。
2. Mariaの既存Clientで雨宿りguildを取得し、対象1人を`guild.members.fetch({ user: discordUserId, force: true })`で確認する。全員の個人情報を一覧で持ち出さない。`Unknown Member`だけを非所属とする。guild取得失敗、権限不足、timeout、rate limitは取得不能として保留・再試行する。
3. 所属する場合に、対応するMaria実績を既存JSONBへ一度保存する。現在の通常付与はメモリcacheを更新して1分後にDBへ保存するため、外部受付の「成功」を返す前の永続化と、同一ユーザーcacheとの整合が必要。単純に外からDBを直接更新するとcacheの後保存で消えるおそれがある。
4. 永続化後、既存の通知設定に沿って新規取得だけを通知する。保存・通知・応答の失敗を分ける。通知後に応答が失われても再付与しない。通知送信後にreceipt更新が失敗した場合の完全な一回配送は保証できないので、再送方針はOwner判断を得る。

小さい専用mjsと短いコメントを基本にし、汎用イベント基盤やService/Repository階層は作らない。ただしMaria肩書きの保存先・表示範囲は未確定なので今回追加しない。

## Owner確認が残る点

- **非所属**: 「ひっそりと取得」は箱庭だけ取得してMaria付与・Discord通知なし、という意味か。確定まで分岐は実装しない。
- **二つの取得・表示の範囲**: 箱庭の実績と肩書きに加えて、Mariaにも両方を保存・表示するか。Mariaには既存肩書き基盤がない。
- **既に命名済み**: `name`と`named_at`を根拠に補填するか、補填時の取得日時を`named_at`にするか、過去分を通知するか。候補は箱庭へ静かに補填し、外部通知は別判断だが未採用。
- **後から連携・加入**: 初取得時にDiscord未連携／非所属だった人が後から連携／加入した場合、過去分もMariaへ取得するか。
- **通知先と再送**: 既存公開channelを使うか、通知失敗を自動再送するか。実通知の試験は別途許可が必要。
- **接続の導入**: 専用共有secret等の認証、限定したinternal接続先、timeoutと再試行の運用。既存Bot TOKEN/DB資格情報を箱庭へ転用しない。新資格情報・権限・接続設定が必要なら導入前に報告し、今回設定しない。

## 導入時の確認

Draftのreviewと上記判断が終わってから、通常のbackup・migration手順でschemaとv33を適用する。未解決Turnを残したWorld移行は既存guardが拒否する。現在のTurn・資産・計画のretry provenanceを変更しない。既存Userへの補填・Maria接続・通知有効化をこのschema移行から自動実行しない。

今回の実装は実通知処理を持たず、外部連携の模擬送信も行っていない。Maria連携の実装時は、所属／非所属／取得不能・再送の代表確認をstubで行い、実DBやDiscordへ試験付与・通知しない。
