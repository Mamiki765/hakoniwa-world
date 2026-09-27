# Staggered x/y test plan

## Backend unit

- even y / odd y の6近傍
- corner / edge bounds
- distance zero、symmetry、adjacent 1、multi-step
- radius 2 = 19、radius 5 = 91
- negative floorDiv / floorMod
- chunk_x/y と local_x/y
- projection parity と horizontal no-drift

## Backend feature

- world 3,600 cells、x/y 0..59、60 rows、各 row 60
- unique x/y、generator idempotence、chunk/local correctness
- nation creation、initial island、territory 19、capital distance 12
- command target_x/target_y、idempotency、optimistic locking、secrecy
- coordinate migration down/up と data preservation
- reset dry-run、wrong confirmation、isolated target、users/identities retention、failure rollback

Backend DB test は production から分離した temporary Compose project と `hakoniwa_test` database で行う。

## Frontend

- `gridToPixel` の例と absolute y parity
- 60×60 footprint、same row width、left 0/16、no cumulative drift
- even/odd six-direction movement
- map state `${x}:${y}`
- command selection payload x/y
- stale refresh / AbortController
- typecheck、lint、Vitest、production build

## Browser QA

- 32px square tile
- 各 row 同数
- even row right 16px、odd row left 0px
- 左右端だけ交互に半マス差
- 世界全体が平行四辺形でない
- initial island が自然に見える
- command selection が正しい x/y を指す
- console error なし
