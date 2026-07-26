# World reset procedure

## Purpose

PR #4 の coordinate migration は既存 world を削除せず、旧表示と同じ形へ x/y backfill する。正しい0..59×0..59の長方形 world へ切り替えるには migration 後に専用 command を実行する。

## Safety

- 対象は設定済み `shared-world` だけ
- users と auth identities は常に保持
- 他 World は変更しない
- production data を test に使わない
- `migrate:fresh`、`db:wipe`、`docker compose down -v`、volume 削除は禁止
- mutation は transaction 内で行い、検証失敗時は rollback

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
