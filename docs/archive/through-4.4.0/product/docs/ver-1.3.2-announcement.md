# ver 1.3.2 announcement

ver 1.3.2 deploy後、既存のお知らせ管理画面から次のplayer-facing announcementを1件作成する。announcementはoperator-authored production dataであるため、migration、seeder、deploy scriptから自動作成・上書きしない。

## Title

```text
ver 1.3.2
```

## Body

```text
・定期バックアップを自動化しました。
・バックアップをゲームサーバーとは別の場所にも保存するようにしました。
・実際のバックアップから復元できることを確認しました。
・運用まわりの安定性を改善しました。

ゲーム内容の変更はありません。
```

作成後はpublic lobbyの最新お知らせとお知らせ詳細でtitle、改行、本文を確認する。同じrelease announcementが既に存在する場合は重複作成せず、既存記事を確認する。
