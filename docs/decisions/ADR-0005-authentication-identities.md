# ADR-0005 認証identityとUserの分離

- 状態: 採用
- 日付: 2026-07-26
- 対象: OAuth login、account linking、User、Nation所有者、将来の認証provider追加

## 文脈

MVPはDiscord OAuthとGoogle OAuthを提供し、同じ利用者がどちらからでも同じゲーム内accountへloginできる必要がある。Discord IDやGoogle IDをUserやNationの正本にすると、provider追加、provider終了、複数World、account recoveryを安全に扱えない。

providerのメールアドレスは変更・再利用される可能性があり、検証状態もproviderごとに異なる。メール一致による自動統合は、別人のUserへ外部identityを関連付けるaccount takeoverにつながる。

## Decision

ゲーム内accountの正本を`users`、外部認証主体を`auth_identities`、ゲーム内国家を`nations`として分離する。

```text
users
- id
- display_name
- status
- timestamps

auth_identities
- id
- user_id
- provider
- provider_user_id
- provider_email
- provider_display_name
- provider_avatar_url
- linked_at
- last_used_at
- metadata
```

次を不変条件とする。

1. 1つのUserへ複数のAuthIdentityを関連付けられる。
2. `(provider, provider_user_id)`はsystem全体で一意とする。
3. 認証主体の識別にはprovider user IDを使い、メールアドレスや表示名を使わない。
4. メールアドレス一致だけで既存Userへ自動統合しない。
5. Discord IDやGoogle IDを`users`の固定列、Userの主キー、Nationの所有者IDにしない。
6. UserとNationは不変の内部IDで関連付ける。
7. OAuth tokenは不要なら永続保存しない。必要になった場合は暗号化、scope、失効、refresh、削除を別途設計する。
8. 最後のAuthIdentityを解除してlogin不能にする操作を禁止する。

MVP providerは`discord`と`google`とする。具体的なLaravel OAuth packageは本ADRで決定しない。

## 初回login

callbackで検証した`(provider, provider_user_id)`が未登録なら、UserとAuthIdentityを1 transactionで新規作成する。

同じ人物と思われるメールアドレスが既存identityにあっても自動連携しない。login済みUserから開始した明示的な連携操作でない限り、未登録identityは別User候補として扱う。

既存identityなら、常に関連済みの同じUserへloginする。

## Provider連携

2つ目のproviderはlogin済みUserのaccount設定から追加する。OAuth stateを、現在のUser、provider、`link`目的、期限へ結び付ける。

callback成功時に、対象identityが他のUserへ関連済みでないことをtransaction内で確認し、未使用の場合だけ追加する。競合や失敗では既存Userを変更しない。連携操作は将来のaudit eventへ記録できるapplication serviceを通す。

他のUserへ連携済みのidentityを自動で移動・mergeしない。

## UserとNation

Userは認証account、NationはWorld内のゲーム主体である。MVPは1 Userにつき1 Nationを基本とするが、一意性の境界は`(world_id, user_id)`とし、将来同じUserが複数Worldで別Nationを持てる余地を残す。

## Identity解除

MVP縦切りでは解除UIを実装しない。将来追加する専用serviceでは、Userとidentity集合をlockし、2個以上ある場合だけ1個を解除できる。1個しかない場合は拒否する。

## Rejected

### Discord IDまたはGoogle IDをusersへ直接保存する

provider追加のたびにschemaとdomainを変更し、provider終了時の移行が困難になるため採用しない。

### Providerメールアドレスで自動統合する

メールアドレスは不変の外部subjectではなく、誤統合とaccount takeoverを防げないため採用しない。

### 初回login時に既存User候補を暗黙選択する

本人確認と連携intentがないため採用しない。既存Userへの追加はlogin済み状態からの明示的なlinkに限定する。

### MVPでUser mergeを実装する

Nation、資源、履歴、認証状態の衝突規則が未決定であり、安全な単純置換ができないため延期する。

## 影響

- `users`と`auth_identities`に別のmigrationとmodel境界が必要になる。
- login callbackはprovider SDK objectを直接Domainへ渡さず、正規化したprovider keyとprovider user IDをApplication serviceへ渡す。
- 同じidentityの同時登録はDB一意制約とtransactionで解決する。
- account linkingと将来のunlinkは通常loginと異なる目的・認可・監査を持つ。
- Nation所有者は内部`user_id`を参照し、外部provider変更の影響を受けない。

## 未決定事項

- Discord対応に使用する具体的なSocialite provider。
- SPA認証でSanctumを使うか。
- VueとLaravelを同一originで配信するか。
- login・link後のredirect UX。
- provider障害・終了時の復旧。
- 管理者による緊急復旧。
- User mergeの具体的処理。

これらの`Status`と`Required before`は`docs/open-questions.md`を正本とする。
