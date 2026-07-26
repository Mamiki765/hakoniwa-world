# hakoniwa-world

PHP/LaravelとVueによる、全プレイヤーが一つの地上世界を共有する箱庭ゲームを設計中です。

## 現在の状態

現在はゲーム本体の実装前です。参考実装を読み取り専用で調査し、出典、要件、設計判断、未解決事項を文書化しています。`product/`のLaravel・Vue実装、migration、Docker Compose、ゲーム処理はまだ作成していません。

確定済みの基盤方針には次が含まれます。

- pointy-top hexのsigned axial `q`、`r`を正本座標とする。
- 地上は国家ごとに分割せず、共有Worldとする。
- `chunk_size = 16`で保存・取得範囲を局所化する。
- 正本Databaseには専用PostgreSQLを使用する。
- MVPではDiscord OAuthとGoogle OAuthを提供し、1つのUserへ複数の認証identityを連携できるようにする。
- User、認証identity、Nationを別の概念として扱う。

設計判断と実装前のgateは[`docs/open-questions.md`](docs/open-questions.md)を参照してください。

## 最初のMVP目標

```text
Docker Composeで起動
→ DiscordまたはGoogleでログイン
→ 国家を作成
→ 共有世界へ首都を自動配置
→ 首都周辺チャンクを表示
```

turn、command、生産・消費、災害、戦闘、通知、地下・宇宙等は、この最初の縦切りには含めません。

## Repository構成

- `docs/architecture/`: 新作の目標architecture。
- `docs/decisions/`: 採用済みのArchitecture Decision Record。
- `docs/operations/`: 将来の配備・運用境界。
- `docs/reference-analysis/`: 参考実装から確認した事実とprovenance。
- `docs/requirements/`: 初期要件。
- `product/`: 明示的な実装承認後に独自実装する領域。現在は未実装。
- `_references/`: ローカルの読み取り専用参考資料。Git管理・公開対象外。

## Third-party material

箱庭諸島2＋と「やまにてぃ」を設計上の参考資料として調査しています。参考source、clone、原作GIFはこのGitHub repositoryへ収録しません。出典、謝辞、画像の外部配置方針は[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)を参照してください。

## License

新作codeと文書の最終licenseは未決定です。repositoryの公開は第三者素材の再利用許可を与えるものではありません。詳細は[`LICENSING.md`](LICENSING.md)を参照してください。
