# World reset procedure

## Purpose

`hakoniwa:world:reset`はlocal/testingの開発Worldと仮データだけを対象とする。production環境では、World key、profile、確認文字列、`--dry-run`の指定にかかわらず実行コードがfail-closedで拒否し、World、Nation、cell、command queue、TurnRun、eventを一切変更しない。

現行の本番・既存DB upgradeには[install/upgrade境界](../../product/docs/architecture/install-upgrade-rebaseline.md)と承認されたforward migrationを使う。この文書の末尾に残す公開前fresh化の記録は履歴であり、現在の本番resetを許可するものではない。

設定されたcurrent Rulesetと一致しないWorldは、`CurrentRulesetGuard`によりturn、command queue、Nation作成、sale policy更新等のmutationを`reset_required`で拒否される。過去Worldの閲覧と、現在の設定でのmutationを分ける。

既存Worldの`ruleset_version_id`をapplication codeで付け替えない。local/testingの更新時はbackupとdry runを確認し、この専用commandで対象Worldの開発game dataを破棄してcurrent rulesetへ再初期化する。published ruleset rows、settings、command definitions、production definitionsは削除・上書きしない。

## Safety

- 対象は設定済み `shared-world` だけ
- `hakoniwa:world:reset`はlocal/testing専用。productionではgo-live前後を問わず実行不可
- go-live後のproduction resetは禁止し、forward migrationまたは明示的な変換だけを使用する
- users と auth identities は常に保持
- 他 World は変更しない
- production data を test に使わない
- `migrate:fresh`、`db:wipe`、`docker compose down -v`、volume 削除は禁止
- mutation は transaction 内で行い、検証失敗時は rollback
- local/testingのhistorical Worldはread-only確認後も、開発データのmutation再開にはこのresetを使う。本番upgradeへこの手順を流用しない

## Dry run

```bash
php artisan hakoniwa:world:reset \
  --world=shared-world \
  --dry-run
```

対象 row 数を表示し、変更しない。nation memberships/resources/sale policies、command queues/items、capitals、creation requests、nations、map cells/chunks、generation runs、map space と World を集計する。

## Execute (local/testing only)

backup と dry run の確認後、exact confirmation を付ける。

```bash
php artisan hakoniwa:world:reset \
  --world=shared-world \
  --confirm=RESET-shared-world \
  --preserve-users \
  --preserve-auth-identities
```

preserve options は運用意図を明示するため受け付けるが、指定の有無にかかわらず users と auth identities は保持する。

## Postconditions

command は success 表示前に次を検証する。

- coordinate system = `staggered_square_offset`
- x/y bounds = 0..59
- 3,600 cells
- 60 distinct y rows
- 各 row 60 cells
- x min/max = 0/59
- user count unchanged
- auth identity count unchanged

失敗時は success を表示せず、transaction を rollback する。

## 履歴: go-live前だけの最終fresh化

旧公開準備では、historical Rulesetを参照する開発Worldをread-onlyで確認した後、local/testingのmutation再開にはresetを必須とした。本番の最終fresh化は、一般Nation登録の開放と正式cron開始の前に限る一度だけの計画だった。

当時の記録は「maintenance中にbackupを取得し、一般Nation登録と正式cronが停止していることを確認し、専用reset commandではなく`php artisan migrate:fresh --force`、`php artisan db:seed --force`、`php artisan hakoniwa:world:init`の順で初期化する」というもの。この段落は過去の計画を保存しており、現在実行する手順でも、実行済みを示す証拠でもない。一般Nation登録または正式cronを開始した後のproduction resetは禁止という境界を維持する。
