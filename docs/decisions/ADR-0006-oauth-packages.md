# ADR-0006: OAuth package選定

- Status: Accepted
- Date: 2026-07-26

## Context

Laravel 13でGoogleとDiscordのOAuth login/linkを同じUser identity境界へ接続し、state検証、最小scope、保守中のadapterを必要とする。

## Decision

GoogleはLaravel Socialite 5.29.0、DiscordはSocialiteProviders/Discord 4.2.0とSocialiteProviders Manager 4系を使用する。SocialiteはLaravel公式maintained packageで、Discord adapterはLaravel SocialiteのOAuth 2 providerを拡張し、Laravel 11以降のevent listener登録方式に対応する。

Discord scopeはadapter既定のemailを置換して`identify`だけ、Googleは`openid profile`だけとする。stateful redirect/callbackを使い、`stateless()`は呼ばない。token、email、refresh tokenは永続化しない。

VueとLaravelを同一originで配信し、Laravel session、CSRF、callback後のsession regenerationを使う。login/link intentはsessionへ明示保存し、ログイン済みlinkだけ既存Userへidentityを追加する。

## Consequences

provider固有IDを`users`や`nations`へ追加せず、別providerも同じadapter境界へ追加できる。Discord adapterのLaravel major対応とrelease状況はframework更新時に再確認する。User merge、identity解除、provider障害時の管理者復旧は別設計が必要である。
