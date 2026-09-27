# ver 1.4.0 伝言板・秘密通信契約

## Scope and ruleset boundary

各Nationは同じmessage storageとviewer-aware backend projectionを使う伝言板を1つ持つ。自島では開発画面の主要領域の後、他島ではpublic islandの地図の下へ同じVue componentを置く。公開済み`hakoniwa-2s-plus-v1`、`hakoniwa-2s-plus-v2`、`hakoniwa-2s-plus-v3`のpayload・checksum・gameplay semanticsは変更しない。秘密通信費用はmessage-board domainの固定application contractであり、ruleset v4や環境変数へ移さない。

## Timeline and retention

- 通常表示はnewest firstの最新16件で、同時刻はmessage IDの降順をsecondary orderとする。
- canonical target boardは`target_nation_id`である。target boardごとに通常伝言とincoming秘密通信を合わせた最新100 recordだけを物理保持し、101件目のsuccessful insertと同じtransactionでoldestから削除する。
- AからBへの秘密通信はBをcanonical targetとするDB record 1件だけを保存する。sender用recordや永久本文auditは作らない。
- B boardはB宛の通常伝言とincoming秘密通信を統合する。秘密通信はauthorized ownerには本文、その他には`--秘密通信あり--`を投影してから最新16件として返すため、placeholderも1件に数える。
- A ownerが自島developmentのA boardを見る場合だけ、Aがsenderのoutgoing秘密通信を同じrecordから追加投影し、owner-onlyの最新16件へ含める。A owner以外のA board queryはoutgoingをquery対象から完全に除外し、placeholder、件数、latest16へ影響させない。B ownerもA boardではoutgoing duplicateを見ない。
- B retentionでsecret recordが削除されると、A ownerのoutgoing projectionからも消える。

## Posting and body

- readはlogin不要。normal／secretのpostは既存Laravel session authとCSRFを使い、新しいtoken authは導入しない。
- 通常伝言はlogin済みUserなら投稿できる。対象Worldにowner Nationがなければ観光客、あれば投稿時点のNation authorとして記録し、後日のNation作成で過去のauthor typeを変えない。
- 本文はUnicode code pointで1〜140文字。backend validationとPostgreSQL `char_length` constraintを正本とし、frontendは同じ数え方の`n / 140` counterを表示する。HTML、Markdown、script、embed、URL展開をせず、Vue text interpolationでplain text表示する。
- player edit、delete、secret revoke、reaction、reply threadのrouteは作らない。
- successful normal postはmessage insert、cooldown更新、target retentionを1 transactionで確定する。失敗時はどれも進めない。

## Author identity and visitor privacy

- messageは`author_kind`、`author_user_id`、nullable `author_nation_id`を持ち、投稿時のNation／visitor種別を固定する。Nation authorの表示名は現行Nation profileをrelationから読む。
- 観光客は`観光客(ID:XXXXXXXX)`と表示する。codeは8文字ASCII英数字でUserへ一度だけpersistし、投稿ごとに再計算しない。
- 初回seedは同じUserにDiscordとGoogleがあればDiscord、DiscordがなければGoogleのstable `provider_user_id`を選ぶ。display name、email、username風表示値はseedに使わない。
- `APP_KEY`をkeyに、`hakoniwa-message-board-visitor:v1`、provider、stable provider ID、collision counter、block counterをdomain-separated HMAC-SHA-256 inputとする。rejection-sampled base62で候補を作り、raw provider IDやHMAC inputはAPI、log、message metadataへ出さない。
- DB unique constraintとUser row lockを正本とし、collision時はdomain-separated counterで次候補を試す。後日のDiscord link、provider unlink、display-name変更でもpersist済みcodeは変えない。Nation author responseにはvisitor codeを返さない。

## Cooldown and transaction order

- normal／secret、全World、全targetを通したUser単位10秒cooldownを、最後のsuccessful postから測る。validation、authorization、insufficient funds、transaction failureはcooldownを更新しない。
- post transactionは既存lock orderと合わせてWorld row、User row、関係Nation rowをNation ID昇順でlockする。User rowがcooldownとvisitor allocationを、canonical target Nation rowがretentionを直列化する。Nation rowはTurnRunnerのmoney更新とも直列化し、lost updateを防ぐ。
- cooldown違反はHTTP 429、`Retry-After`、残り秒数の短い案内を返し、message／moneyを変更しない。

## Secret communication and privacy

- 秘密通信は対象WorldのNation ownerだけが、同じWorldの別Nationへ送れる。self-sendと`sunken_archived` sender／recipientは拒否し、dormantの新しいgameplay意味は決めない。
- 費用は固定100 money units（1 unit = 1億円、表示`100億円`）でsenderだけから控除する。authorization、target、cooldown、current money、debit、1 record insert、cooldown、retention、本文なしaudit metadataを1 transactionで確定する。insufficient fundsまたは途中失敗は全てrollbackする。
- secret bodyを読めるのはsender Nation ownerとrecipient Nation ownerだけ。B boardで両ownerは`[A島からの秘密通信] 本文`、A ownerの自島boardだけは`[B島への秘密通信] 本文`を読む。
- unauthorized incoming projectionはmessage public key、created timestamp、placeholder kind、exact `--秘密通信あり--`だけを返す。body、sender／target／counterpart、direction、sender User、内部ID、cost、secret metadata、raw visibility subjectを返さない。
- 費用監査はsender、target、cost、type、timestamp、message record referenceだけをprivate audit metadataへ記録し、本文を複製しない。

## UI, refresh, and deferred work

- timeline、normal textarea、Unicode counter、validation／cooldown error、logged-out read-only、観光客／島主／他島label、owner向け秘密通信formと`100億円`表示を提供する。島主は赤系、他島／観光客は青系、secret／placeholderは濃い灰色系の既存design tokenを使い、text labelも必須とする。
- 投稿成功時はserver responseをauthoritative timelineとして置換し、失敗時はoptimistic entryを残さない。WebSocket、typing indicatorは追加せず、既存polling方針に合わせて60秒再取得する。
- headingとtoggleは常時表示し、初期expandedとする。timelineと投稿formをまとめてcollapseし、`aria-expanded`を持つbuttonでkeyboard操作できる。`localStorage`へpublic／development context別に保存し、Cookie／DBへ保存しない。collapseはbackend read、cooldown、secret visibilityを変えない。
- mobileではboardをmap／commandの後へstackし、textarea、counter、timeline、44px以上の主要buttonを利用可能にする。大規模UI redesignは行わない。
- block list、通知、game内通報管理、operator moderation UI、高度削除workflowは今回決定・実装しない。既存D-07 moderation方針とpost-release backlogを維持する。
