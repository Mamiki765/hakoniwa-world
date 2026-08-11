# ver 1.4.1 announcement

ver 1.4.1 deployとsmoke確認後、既存のお知らせ管理画面から次のplayer-facing announcementを1件作成する。migration、seeder、deploy scriptから自動作成・上書きしない。

## Title

```text
ver 1.4.1
```

## Body

```text
・通常の島開発行動が公開島ログへ反映されるよう、島ログの表示を改善しました。
・島主ログを見やすく整理し、不要な内部通知や重複表示を非表示にしました。
・島画面と公開previewで、伝言板を島ログより上へ移動しました。
・更新時のデータ移行を含む安定性を改善しました。
```

作成後はpublic lobbyの最新お知らせと詳細でtitle、改行、本文を確認する。同じrelease announcementが既に存在する場合は重複作成しない。
