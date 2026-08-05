# World reset procedure

## Purpose

この手順はPR23のgo-live前に残る開発Worldと仮データだけを対象とする。production Worldの最終fresh生成、一般Nation登録開放、初回正式turn開始の3条件が揃った後は使用禁止である。go-live後はWorld、Nation、cell、command queue、TurnRun、eventをresetせず、schemaまたはgameplay data変更へforward migrationか明示的な変換経路を用意する。

go-live前にhistorical rulesetを参照するWorldは地図、event、TurnRun、ruleset snapshotをread-onlyで閲覧できるが、turn、command queue、Nation作成、sale policy更新その他のgame-state mutationは`reset_required`で拒否される。

既存Worldの`ruleset_version_id`をapplication codeで付け替えない。更新時はbackupとdry runを確認し、この専用commandで対象Worldの開発game dataを破棄してcurrent rulesetへ再初期化する。published ruleset rows、settings、command definitions、production definitionsは削除・上書きしない。

## Safety

- 対象は設定済み `shared-world` だけ
- PR23の3条件が揃うgo-live前だけ実行可能。go-live後の実行は禁止
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

## Execute

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
