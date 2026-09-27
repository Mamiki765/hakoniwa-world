# やまにてぃ 管理性と設定

## 管理機能の有無

専用管理画面、管理route、管理Controller、admin role/permission、Policy、ユーザー停止、設定変更履歴、管理ログ閲覧は存在しない。/settingsはプレイヤー自身の島名・owner名とtheme等の設定で、ゲーム運営設定画面ではない（Web Settings IndexController.php:13-45）。

## 環境変数で変更可能

config/app.php:225-248がHakoniwa固有設定を束ねる。

| key | default | 用途 |
| --- | ---: | --- |
| TURN_UPDATE_MINUTES | 240 | 次回turn予定時刻の加算 |
| MAX_ISLANDS | 50 | 登録上限 |
| PRIVATE_POST_PRICE | 1000 | 非公開BBS |
| CHANGE_ISLAND_NAME_PRICE | 1000 | 改名 |
| DEFAULT_SHOW_BBS_COMMENTS | 10 | BBS表示数 |
| INDEX_PAGE_SHOW_LOG_TURNS | 5 | top global log範囲 |
| DETAIL_PAGE_SHOW_LOG_TURNS | 20 | 島log/summary範囲 |
| BACKUP_LOGS_INTERVAL | 1 | 古いsnapshotを残す間隔。1はprune無効 |
| PRUNE_LOGS_MARGIN_TURN | 100 | 直近保持turn |
| MONSTER_ACTION_PROBABLY | 1 | 怪獣行動確率 |
| ISLAND_ABANDONED_TURN | null | 休眠放棄までのCashFlow回数 |
| DEBUG_LOGIN_USING_ID | null | local debug login |
| NOTIFICATION_WEBHOOK_URL | null | turn/prune失敗通知 |
| IS_SYSTEM_MAINTENANCE | false相当 | Web/API 503 |

DB接続、session、cache、queue、Socialite/Yahoo設定はLaravel標準config/envへ置く。秘密値はenvで参照するが、local compose/init.sqlには固定開発credentialが直接記載されている。

## コードへ埋め込まれたゲーム値

- 15×15 map寸法（HakoniwaService.php:11-12）。
- 初期地形個数と分布（Terrain.php:103-131）。
- 初期資金/食料/資源と上限、生産・消費係数（Status.php:15-34）。
- 全Plan費用、解放点、実行条件。
- 全災害率と被害率。
- 人口・施設・怪獣・船の容量/耐久/維持値。
- 100turnごとの賞（ExecuteTurn.php:274-290）。
- rate limit 60（RouteServiceProvider.php:52-70）。

これらは再deployなしに変更できず、変更者、理由、適用turn、旧値、ruleset versionを記録しない。

## 次回turn時刻

turns.next_turn_scheduled_atはDBに保存されUI countdownへ渡されるが、execute:turnは時刻到来を検査しない。TURN_UPDATE_MINUTES変更後は次に作るTurnから反映されるだけで、既存next timeの管理操作はない。

## maintenance・debug

MaintenanceFilterはIS_SYSTEM_MAINTENANCE時、APIに503 JSON、Webに503を返す（MaintenanceFilter.php:18-29）。Artisan turn自体はこのmiddlewareを通らないため、maintenance中も外部scheduler次第で進み得る。

OnlyDebugModeはlocalかつdebug時だけdebug loginを許可する（OnlyDebugMode.php:18-29）。ただしdebug loginの「指定IDがない場合に新規User作成」分岐はloginUsingIdの戻り値をclosureへ渡す実装が不自然で、実動確認が必要である。

## 新作でDB管理へ移す候補

運用中に変える設定はworld_settingsとして通常列/型付き値で管理し、effective_turn、changed_by、reason、before/after、versionを保存する。

- turn間隔、next scheduled time。
- 災害全体倍率、災害別rate/enabled。
- 初期資源・初心者保護。
- world拡張閾値/余白。
- ログ保持、通知重要度。

施設・研究等のruleは運用settingと分け、versioned rulesetとして検証・publishする。秘密はDB設定UIへ入れずsecret store/envを継続する。
