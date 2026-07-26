# 認証とidentity

## 状態

MVPの認証domain modelと安全条件は確定済み。Laravelで使用する具体的なOAuth package、SPA session方式、配信origin、redirect UXは未決定であり、`docs/open-questions.md`の`AUTH-*`を実装前に確認する。

## MVP範囲

MVPでは次を実装対象とする。

- Discord OAuth login。
- Google OAuth login。
- 未登録identityからのUser作成。
- login済みUserへの2つ目のprovider連携。
- 連携済みのDiscordまたはGoogleのどちらからでも同じUserへlogin。
- 同じ外部identityの重複登録防止。
- 最後のlogin手段を失わせない不変条件。
- identity連携を将来のaudit eventへ記録できるservice境界。

メールアドレス・password、passkey、GitHub・Apple等の追加provider、User同士のmerge、管理者による緊急復旧、identity解除UIはMVP後とする。

## 概念の分離

`User`、`AuthIdentity`、`Nation`を別の概念にする。

```text
User
  1 ── * AuthIdentity
  1 ── * Nation（Worldごと。MVPは1 Worldにつき最大1 Nation）

AuthIdentity
  └── DiscordまたはGoogle上の認証主体
```

- `users`がゲーム内accountの正本である。
- `auth_identities`は外部providerの認証主体をUserへ関連付ける。
- `nations`はゲーム内国家であり、認証accountではない。
- Discord ID、Google ID、メールアドレス、ranking順位をUserやNationの内部IDとして使わない。
- UserとNationは不変の内部IDで参照する。
- 将来は同じUserが複数Worldで別Nationを持てる。MVPの1 User 1 Nationは`(world_id, user_id)`の一意性として表し、全systemで1 Nationへ固定しない。

## データモデル

### users

- id
- display_name
- status
- created_at
- updated_at

provider固有のIDやtokenを`users`の固定列へ追加しない。

### auth_identities

- id
- user_id
- provider
- provider_user_id
- provider_email nullable
- provider_display_name nullable
- provider_avatar_url nullable
- linked_at
- last_used_at nullable
- metadata JSONB
- created_at
- updated_at

最低限の制約は次の通り。

- `user_id`は`users.id`を参照する。
- `(provider, provider_user_id)`へ一意制約を置く。
- `provider_user_id`はproviderが返す不変のsubject識別子を文字列として保存し、表示名やメールアドレスで代用しない。
- provider名は安定した内部keyで正規化する。MVPのkeyは`discord`と`google`。
- `provider_email`、display name、avatar URL、metadataは補助snapshotであり、認証主体の正本ではない。
- provider metadataの検索に不要な値だけをJSONBへ置く。provider user IDや外部キーをJSONBへ隠さない。

OAuth tokenは、loginと基本profile取得だけで不要なら永続保存しない。将来provider API呼出しのため保存が必要になった場合は、token本体の暗号化、scope、失効、refresh、削除、漏えい時対応を別のcredential modelとして設計し、`auth_identities.metadata`へ平文保存しない。

## Loginの解決規則

OAuth callbackを受けたときは、検証済みの`provider`と`provider_user_id`だけで既存identityを検索する。

### 既存identity

1. `(provider, provider_user_id)`に一致する`auth_identity`を取得する。
2. 関連するUserのstatusを確認する。
3. login sessionを更新し、`last_used_at`を記録する。
4. 連携済みのproviderがDiscordでもGoogleでも、同じ`user_id`へloginする。

### 未登録identity

login済みUserへの連携操作ではない場合、新しいUserとAuthIdentityを1つのtransactionで作成する。

1. callbackのstate、期限、providerを検証する。
2. `(provider, provider_user_id)`が未登録であることを再確認する。
3. Userを作成する。
4. AuthIdentityを作成する。
5. 両方が確定した場合だけloginを成立させる。

providerが返すメールアドレスが既存Userのidentityと一致しても、自動的にそのUserへ関連付けない。未連携の別providerからloginしただけなら、別User候補として扱う。メールの変更、再利用、未検証値、共有アドレスによるaccount takeoverを防ぐためである。

## 2つ目のprovider連携

既存Userへの追加は、login済み状態から開始する明示的なaccount linking操作だけで行う。

```text
Discordでlogin済み
→ account設定でGoogle連携を開始
→ Google OAuth callbackを検証
→ Google identityを同じUserへ追加
→ 以後はDiscordまたはGoogleで同じUserへlogin
```

逆方向も同じserviceを使う。連携処理は次を満たす。

1. 現在のlogin sessionと連携intentを確認する。
2. 必要に応じて現在のidentityで再認証する。
3. OAuth requestのstateを、provider、目的`link`、現在のUser、期限へ結び付ける。
4. callback、state、provider user ID、必要最小scopeを検証する。
5. 対象の外部identityが他のUserへ連携済みでないことをtransaction内で再確認する。
6. 成功時だけ`auth_identity`を追加する。
7. 競合、取消、provider errorでは既存Userとidentityを変更しない。
8. actor、User、追加identity、provider、日時、結果を将来のaudit eventへ記録できるapplication serviceを通す。

他のUserへ連携済みの場合、付け替えやmergeを自動実行しない。利用者へ競合を通知し、MVP後の回復・merge手続きへ委ねる。

## Identity解除の不変条件

MVP縦切りでは解除UIを実装しない。ただし解除処理を追加するときは、Userとそのidentity行をtransaction内でlockし、次を保証する。

```text
identityが2個以上
→ 再認証等の条件を満たせば1個を解除可能

identityが1個だけ
→ 最後の1個は解除不可
```

解除は直接の汎用deleteではなく専用application serviceへ限定する。将来、再認証、重要操作の確認、provider情報の表示、audit event、当該identity由来sessionの失効を追加できる境界にする。

## Account重複とmerge

メールアドレス一致、表示名一致、avatar一致ではUserをmergeしない。DiscordとGoogleで別々のUserが作られた場合も、MVPでは自動mergeも手動mergeも行わない。

将来のmergeでは、少なくとも次の衝突を個別に扱う必要がある。

- 同じWorldに双方がNationを持つ。
- 資源、command、item、ranking、称号が双方にある。
- event、audit、territory履歴が別Userを参照する。
- 同じprovider種別の複数identityがある。
- moderation、停止、本人確認状態が異なる。

単純な`user_id`一括置換で安全に統合できるとは仮定しない。

## OAuth security境界

package選定にかかわらず、次を必須とする。

- OAuth requestごとに推測困難で一回限りのstateを発行し、session、provider、loginまたはlink目的、期限と照合する。
- callback URLを許可済みの固定値から生成する。
- session IDはlogin成功時に更新する。
- browserからの状態変更requestへCSRF対策を適用する。
- login済みUserへのlinkでは、callback先のUserをrequest parameterだけで決めない。
- providerごとに必要最小限のscopeだけを要求する。
- provider secretとtokenをclient bundle、log、rulesetへ出さない。
- callback errorへprovider token、authorization code、個人情報を表示・記録しない。
- identity一意制約違反をaccount付け替えとして扱わない。

## Package選定の境界

現時点では具体packageを決定しない。確認候補は次の通り。

- Laravel Socialite。
- Discord対応の追加Socialite providerまたは同等adapter。
- SocialiteのGoogle provider。
- SPA sessionにSanctumを使うか。

比較時は、対応Laravel版、保守状況、provider user ID、state検証、OAuth callback、session、CSRF、account linking、scope、token非保存を満たせるか確認する。package固有objectをDomain層へ渡さず、Infrastructure adapterが正規化した外部identity DTOをApplication層へ渡す。

## 必須test

- 未登録Discord identityからUserとidentityが同時作成される。
- 未登録Google identityからUserとidentityが同時作成される。
- 既存identityで毎回同じUserへloginする。
- DiscordへGoogleを連携後、どちらでも同じUserへloginする。
- GoogleへDiscordを連携する逆方向も同じ結果になる。
- 同じ外部identityを2つのUserへ連携できない。
- 同じメールアドレスだけではUserを自動統合しない。
- link callbackのstate、目的、User、providerが一致しなければ変更しない。
- provider errorまたはDB競合時に既存identityが変わらない。
- 最後のidentityを解除できない。
- User ID、Nation ID、provider user IDを相互に代用しない。

## 未決定・延期事項

`docs/open-questions.md`を正本とし、次を実装中に暗黙決定しない。

- Discord用の具体的なSocialite provider。
- Sanctum採用の有無。
- VueとLaravelを同一originで配信するか。
- login・link後のredirect UX。
- provider障害・終了時の復旧。
- 管理者用緊急復旧。
- User merge。
- identity解除UI。
- password、passkey、追加provider。

## MVP実装記録（2026-07-26）

Laravel Socialite 5.29.0とSocialiteProviders/Discord 4.2.0を採用した。Discordは`identify`、Googleは`openid profile`だけを要求する。OAuth stateを有効のまま使用し、callback後にsession IDを再生成する。VueとLaravelは同一originで、Laravel sessionとCSRFを用いる。

`users`はゲーム内User、`auth_identities`は外部identityであり、`(provider, provider_user_id)`と`(user_id, provider)`を一意にする。tokenとprovider emailは保存しない。loginとlinkの意図はsessionで分け、linkはログイン済みUserにだけ許可する。別々に作成済みのUser mergeとidentity解除はMVP外である。
