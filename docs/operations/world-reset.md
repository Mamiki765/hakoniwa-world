# World reset procedure

## Purpose

`hakoniwa:world:reset`はlocal/testingの開発Worldと仮データだけを対象とする。production環境では、World key、profile、確認文字列、`--dry-run`の指定にかかわらず実行コードがfail-closedで拒否し、World、Nation、cell、command queue、TurnRun、eventを一切変更しない。

go-live前にhistorical rulesetを参照するWorldは地図、event、TurnRun、ruleset snapshotをread-onlyで閲覧できるが、turn、command queue、Nation作成、sale policy更新その他のgame-state mutationは`reset_required`で拒否される。

既存Worldの`ruleset_version_id`をapplication codeで付け替えない。local/testingの更新時はbackupとdry runを確認し、この専用commandで対象Worldの開発game dataを破棄してcurrent rulesetへ再初期化する。published ruleset rows、settings、command definitions、production definitionsは削除・上書きしない。

production Worldの最終fresh化は、一般Nation登録の開放と正式cron開始の前に限り、maintenance中に`php artisan migrate:fresh --force`、seed、World初期生成の順で行う。go-live後のproduction resetは禁止し、forward migrationまたは明示的な変換だけを使用する。

## Safety

- 対象は設定済み `shared-world` だけ
- `hakoniwa:world:reset`はlocal/testing専用。productionではgo-live前後を問わず実行不可
- productionの最終fresh化は一般Nation登録の開放と正式cron開始の前に限る
- go-live後のproduction resetは禁止し、forward migrationまたは明示的な変換だけを使用する
- users と auth identities は常に保持
- 他 World は変更しない
- production data を test に使わない
- `migrate:fresh`、`db:wipe`、`docker compose down -v`、volume 削除は禁止
- mutation は transaction 内で行い、検証失敗時は rollback
- historical Worldのread-only確認後も、mutation再開にはこのresetを必須とする

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

productionの最終fresh化ではこのcommandを使用しない。maintenance中にbackupを取得し、一般Nation登録と正式cronが停止していることを確認してから、次を順に実行する。

```bash
php artisan migrate:fresh --force
php artisan db:seed --force
php artisan hakoniwa:world:init
```

このproduction初期化は正式公開前の一度だけを対象とする。一般Nation登録または正式cronを開始した後は実行しない。

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
