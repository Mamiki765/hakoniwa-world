# 箱庭実績・肩書きとMariachangの取得連携（Draft）

確認日: 2026-10-06。Worldの起点は `main` @ `1ecf8265eca44a638f031ff6ffee219b958f912a`（v32）、Mariachangは `master` @ `7a7d984230311ec381ba0f3561951baea1352996`。World [Draft #204](https://github.com/Mamiki765/hakoniwa-world/pull/204)と既存のMaria [Draft #21](https://github.com/Mamiki765/Mariachang/pull/21)で作業する。実装・模擬検証までで、merge・deploy・実環境DB更新・実Discord通知は行っていない。

## 確定した取得・表示

実績は箱庭とMariaの両方で取得する。肩書きは箱庭の秘書だけが持ち、取得済み実績に対応する肩書きから装備を選ぶ。最初は実績・肩書きとも「島の秘書」。秘書の命名が条件で、新しい命名時に取得して初期装備する。既に命名済みの秘書にもmigrationで補填し、初期装備する。補填日時は元の `named_at`。

`SecretaryNamingService::name` の既存transaction・秘書row lockで命名と取得を保存する。`user_achievements` の `unique(user_id, achievement_key)` で再取得を防ぐ。Userに保持するので島の破棄・再登録では失わない。既存の再命名経路では取得処理を呼ばない。箱庭にMariaのcache/dirty保存方式は持ち込まない。

`GET /api/v1/me` で自分の取得済み実績と肩書きを返す。秘書画面のsubheaderに「実績」tabを置き、一覧と肩書き選択を表示する。秘書プロフィールには装備中の肩書きを表示する。新規命名の応答だけが新規取得情報を持ち、既存の報酬toastで表示する。再読込・再命名・装備変更で取得toastを再表示しない。

定義はcanonical Ruleset v33 draftの `user_achievements.island_secretary`。適用済みv32のpayload/checksumは変更しない。新migrationは既存のWorld lock・未完了Turn guardでv32→v33に進める。アプリversionは現行のままで、Draftは新release公開を意味しない。

## 最小の連携

データの流れは「箱庭の取得行 → 既存Discord紐づけ → Mariaの既存付与関数 → 既存保存と所属者への通知」。箱庭は `auth_identities(provider='discord').provider_user_id` を使う。表示名やメールで照合しない。Googleのみの人は後から既存のDiscord連携を行うと送信対象になる。

箱庭の `hakoniwa:sync-maria-achievements` が、Discord紐づけ済み・`maria_sent_at` が空の取得行を一回最大25件送る。現行の[host cron方式](../../../docs/operations/turn-cron.md)で独立jobを毎分登録し、hostの `flock` で重複起動を避ける導入案。Laravel schedulerが本番で動いている証拠は確認していないため、それを前提にしない。取得・ターン更新のtransaction外で接続し、外部障害は箱庭の取得やターン進行を止めない。受け付け成功時だけ `maria_sent_at` を保存し、失敗は次回へ残す。汎用イベント基盤・別の履歴テーブルは作らない。

Mariaの既存Expressに `POST /internal/hakoniwa/achievement` を加える。専用Bearer認証後、Discord IDと既知の実績keyだけを受け付け、`island_secretary` を既存実績ID **151** に対応させる。Draft #21の `/hakoniwa` 用150「島主」はそのまま。HTTPと通常コマンドは共通の初回読込Promiseで同じ実績JSONを使い、片方のcache上書き・取得喪失を防ぐ。HTTPだけのqueueは置かず、既存 `unlockAchievements` の取得済み判定を使う。

Mariaの取得行がない人にも、既存関数が行を作って実績を保存する。非所属・退会済みでもMaria実績を取得し、通知だけを抑止する。Mariaに肩書きは追加しない。既存cache/dirty・毎分DB保存・shutdown保存は変更しない。`accepted:true` はこの既存経路での受付完了であり、DB書込み完了の応答ではない。受付後、毎分保存前にprocessが強制終了すると未保存分を失う既存の窓がある。今回の最小実装はこの保存基盤を作り直さない。

## 所属判定と通知

既存envの非秘密設定から雨宿り `1025416221757276242` を確認できた。`GUILD_IDS` と `MEE6_GUILD_ID` が一致する。Maria DBには現在所属者を保証する専用リストがない。points・mee6・実績行の存在は所属判定に使わない。

既存Clientには `GuildMembers` intentがある。起動時に雨宿りのメンバーを一回fetchし、以降はDiscord.jsのjoin/removeによるmember cache更新を使う。実績ごとのfetch・polling・大きなretryは追加しない。初期取得未完了・取得失敗は非所属と区別し、通知を抑止する。どちらも実績の取得・保存は続ける。

この所属確認は雨宿り向けの通常・隠し実績通知にも共通で適用する。他guildの運用範囲には拡張しない。現在所属している新規取得者には既存設定のpublic/dm/noneと通知先を使う。production public設定のchannelは `1421521075337953321`。今回、channelへの試験送信はしていない。Maria側で既に取得済みなら再送しても通知しない。後日の加入だけで過去実績を通知し直す処理はない。

## 導入時の設定（Owner作業）

両containerは既存 `apps_default` networkを共有し、Mariaの3000/tcpはhost公開なし。内部候補URLは `http://mariachang:3000/internal/hakoniwa/achievement`。読み取りで接続構成を確認しただけで、実付与APIは呼んでいない。

1. Draftレビュー後、通常のbackup・migration手順でWorldのschema/v33を適用する。未完了Turnでは既存guardが移行を拒否する。既命名者の補填もこのmigration内で行い、外部通信しない。
2. Ownerが新しい専用共有secretを用意し、Maria実行envの `HAKONIWA_LINK_SECRET` とWorldのroot `.env` の `MARIA_ACHIEVEMENTS_TOKEN` に同じ値を置く。Worldの `MARIA_ACHIEVEMENTS_URL` に上記内部URLを設定する。`compose.yml` のWeb serviceはこの2変数を転送する。本番の別Compose fileにも同じ環境設定が必要。通常の導入手順でWeb containerを再作成し、実行envへ反映する。Composeを使わないLaravel実行時は `product/.env` 側に設定する。既存Bot TOKEN・DB資格情報は転用しない。秘密値をGitやPRへ置かない。
3. Mariaを更新して既存起動処理・一回のmember cache取得を確認する。上記host cron手順の専用wrapperで送信commandを毎分呼ぶjobをOwnerが登録する。今回、実cron登録はしていない。未設定なら送信commandは何もしない。設定済み環境では補填行も送信対象になり、Mariaで初取得かつ現所属なら既存通知が出る。

新しい資格情報の生成・配置、merge/deploy、migration適用、host cron有効化は今回行っていない。送信済み後のDiscordアカウント付け替えまで再配布する仕様は追加していない。

## 次の20候補の実現性（未採用・未実装）

名前・肩書きはまだ案。追加の過去履歴基盤を作らず、既存skill・現在値・永続progressを使う。地上系は経験値flush後のターン終了時にまとめて確認する。資金は導入時とターン終了時の現在保有額を使い、過去最高額を復元しない。地底系は既存の進行・戦闘精算後の値を使う案。

| 候補 | 既存の判定値・境界 |
| --- | --- |
| 農場を建てる | `agricultural_policy` Lv1以上。高速建設も同じskillに加算される |
| 工場を建てる | `specialty_development` Lv1以上 |
| 採掘場を建てる | `gold_vein_survey` Lv1以上 |
| 9999億円保持「億万長者」 | `Nation.money >= 9999`。現在額だけ |
| 油田発見 | `oil_development` Lv1以上。成功した油田探索でXP加算、既存補填処理もある |
| 発電所／灯り | 厳密な建築履歴より「電力を1MW以上消費」へ変更案。`energy_saving.level >= 1 OR experience >= 1`。XPはLv上昇時に消費するため、Lvと残XPを両方見る |
| 30000億円保持 | `Nation.money >= 30000`。現在額だけ |
| 輝石コマンドを使う | 既存 `user_paradox_ledger` の `source_kind='command' AND delta<0` が実行時引落。予約ではない。User索引を使う存在確認で判定可能。新しい履歴不要 |
| 240ターン生存「永遠の乙女」 | 既存表示と同じ `current_turn - registered_turn >= 240`。現在の島の年齢、再登録後は再計算 |
| 地底チュートリアル「地底の迷い人」 | `underground_contract_completed_at` またはintro stage `underground_open`。脱出直後とは別なので、完了境界をOwner確認 |
| 試練1「冒険者」 | `trial_01` の `first_cleared_at` がある |
| 初覚醒「変身ヒロイン？」 | 既存生涯統計 `self.awakened_at_start` / `self.awakened_in_battle` の既知合計が1以上。古い統計の未知分を復元しない。新カウンター不要 |
| Lv50 | `UndergroundProfile.combat_level >= 50` |
| 試練2 | `trial_02` の `first_cleared_at` がある |
| Lv100 | `combat_level >= 100` |
| 異界を一度クリア | 異界の対象keyについて永続 `underground_content_clear_progress.actual_clear_count > 0`。発見日時や単なるskip数とは区別する |
| Lv300 | `combat_level >= 300` |
| Lv500 | `combat_level >= 500` |
| 試練3 | `trial_03` の `first_cleared_at` がある |
| Lv1000 | `combat_level >= 1000` |

覚醒は既存集計済み生涯統計と今後の戦闘結果を使う案とし、実績追加のために全過去戦闘を再走査しない。異界は初級等を問わず対象異界の実戦初勝利とするか、skipも含めるかが未確定。チュートリアル境界・異界の対象・覚醒の未知分の扱い・名称/肩書きを決めてから別差分で実装する。今回のcanonical定義と付与処理は「島の秘書」だけ。
