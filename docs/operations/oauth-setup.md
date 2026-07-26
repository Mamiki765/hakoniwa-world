# Discord・Google OAuth設定

secretはroot `.env`だけへ保存し、Git、issue、PR、logへ貼らない。local callbackは次を完全一致で登録する。

- Discord: `http://127.0.0.1:8080/auth/discord/callback`
- Google: `http://127.0.0.1:8080/auth/google/callback`

## Discord Developer Portal

1. Discord Developer Portalでapplicationを作成する。
2. OAuth2画面のRedirectsへ上記Discord callbackを追加する。
3. Application IDとClient Secretを`DISCORD_CLIENT_ID`、`DISCORD_CLIENT_SECRET`へ設定する。
4. `DISCORD_REDIRECT_URI`を登録値と完全一致させる。
5. 実装が要求するscopeは`identify`だけである。`email`やbot scopeを追加しない。

DiscordのOAuth2仕様はstateをCSRF対策に使う。本実装はSocialiteのstateful flowを維持する。

## Google Cloud Console

1. projectを作成または選択し、OAuth consent screen/Brandingを設定する。
2. testing中は許可するtest usersを追加する。
3. Clientsで`Web application`型のOAuth clientを作る。
4. Authorized redirect URIsへ上記Google callbackを追加する。
5. Client ID/Secretを`GOOGLE_CLIENT_ID`、`GOOGLE_CLIENT_SECRET`へ設定し、`GOOGLE_REDIRECT_URI`を完全一致させる。
6. 実装scopeは`openid profile`だけで、emailを要求しない。

Googleはscheme、host、port、path、末尾slashを含むredirect URI完全一致を要求する。productionでは公開HTTPS URLを別clientまたは明確な環境設定で登録する。

## 手動確認

loginとログイン済みaccount linkingを別々に確認する。callback後にsessionが更新され、同じidentityが別Userへ連携できず、provider email/tokenがDBやAPIへ残らないことを確認する。provider側で別Userを作った後のUser mergeはMVPにない。
