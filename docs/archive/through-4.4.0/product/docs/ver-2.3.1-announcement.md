# ver 2.3.1 announcement

ver 2.3.1のdeployと動作確認が完了した後、repository convention上player-facing announcementが必要な場合に限り、既存のお知らせ管理画面から次の内容を1件作成する。migration、seeder、deploy scriptから自動作成・上書きしない。同じrelease announcementがすでに存在する場合は重複作成しない。

## Title

```text
ver 2.3.1
```

## Body

```text
・内部のゲーム処理を整理し、テストと開発工程を軽量化しました。
・ゲームバランスやゲーム仕様の変更はありません。
・セーブデータの変更はありません。
```

作成後はpublic lobbyの最新お知らせと詳細でtitle、改行、本文を確認する。
