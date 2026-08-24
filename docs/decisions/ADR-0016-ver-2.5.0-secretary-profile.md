# ADR-0016 ver 2.5.0 秘書公開プロフィール

- 状態: 採用
- 日付: 2026-08-24

## 背景

ver 2.5.0の第一段階として、既存のUser永続Secretaryを他playerも閲覧できる
キャラクターステータスシート型の公開プロフィールへ拡張する。外部PBWは一画面に
画像、基本情報、経歴、装備を置く構成だけを参考にし、design、素材、文言、codeは
コピーしない。

既存実装には、4 passive skill、5slot equipment、`NationCapacityResolver`、問い合わせ
画像の安全なvalidation/storage境界、owner専用の熟練度/装備/倉庫tabがある。これらを
第二engineへ複製せず、用途固有の差分だけを追加する必要がある。

## 決定

1. 既存Secretary tabの左端に「メイン」を追加し、これだけをpublic profileとする。
   PCは3:4画像と基本情報/経歴を並べ、装備を下へ置く。mobileは画像、基本情報、
   経歴、装備の順へ縦配置する。他userへ編集controlは返さない。
2. `secretaries`へ1000文字plain-text経歴と、最新1枚だけの画像path/MIME/制作方法/
   160文字以内の任意credit/更新時刻を持たせる。HTMLは拒否し、Markdown syntaxは
   解釈せずplain textとして表示する。gallery、履歴、generic profile/media tableは作らない。
3. 画像はPNG/JPEG/WebP/GIF、10MiB以下とし、問い合わせ実装から共有したserver MIME、
   readable-image、dimension/pixel、256-bit filename、disk-write確認を使う。保存diskと
   public URL/cache policyは問い合わせから分離し、DB更新失敗時は新fileを消し、成功後は
   旧fileを消す。
4. 制作方法は`self_made`、`ai_generated`、`commissioned_or_permitted`、`other`の4値とする。
   `users`へnullableなAI表示boolと`silhouette|peridot` fallbackを一組で持たせ、両方nullを
   未設定とする。AI非表示はowner自身を含むviewer presentationでNo imageを返し、保存画像を
   削除しない。画像未設定で設定済みなら選択fallback、未設定ならNo imageを返す。
5. 秘書Lvは既存4 passive skill levelの合計とする。immutable ruleset v14は資金capacityと
   食料capacityへ`floor(base * (100 + level) / 100)`を適用し、上限を置かない。canonical
   `NationCapacityResolver`とbounded credit経路を使う。E-04のgeneric Modifierは実装せず、
   将来の別bonusは乗算として明示的なruleset変更で追加する。
6. exact v13だけをv14へforward migrationし、published v13を変更しない。World、Nation、
   User/Secretary/skill/item/equipment、queue/request、TurnRun/RNG/event/audit、monster/kill
   provenanceを保存する。fresh installはv14だけを公開する。

## 延期

画像gallery、過去画像、複数立ち絵、高度な画像編集、著作権審査workflow、CMS、コメント、
いいね、称号、秘書戦闘/FFA/訓練/探索/館、新しいSecretary XP/level systemは実装しない。

## 結果

- public profile応答はviewer preferenceで変わるためprivate/no-store API responseとする。
- fallback SVGは本projectの単純なoriginal assetであり、第三者素材を含めない。
- public image bytesは長期cache可能だが、DBとfile directoryを別々に復旧すると参照が
  一致しないため、production backupでは同一復旧点として扱う。
- 詳細なAPI、storage、migration、test契約は
  `product/docs/ver-2.5.0-secretary-profile.md`を正本とする。
