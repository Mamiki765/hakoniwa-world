# ver 1.6.0 Nation lifecycle

ver 1.6.0では、認証済みownerがプロフィール編集画面の危険領域から、自分のactive Nationを手動で破棄できる。これは自動休眠、転生、lineage、account資産引き継ぎを導入する機能ではない。Userは残り、Nationは一代の島として`state = abandoned`で履歴に残る。

## 確認と直列化

UIは「この島を破棄する」buttonからmodalを開き、現在の島名との完全一致が成立するまで最終buttonを無効にする。APIも認証、owner membership、locked Nationのactive state、locked Nation名との完全一致を再確認する。

破棄はNation作成・World拡張・turnと同じ`WorldMutationLock`をtransaction前に取得し、current rulesetのWorldだけを変更する。World、Nation、Capital、対象surface MapCell、MapChunk、monster occupancy、queue、resource、sale policy、membershipを単一DB transactionで処理し、event記録を含むどの段階の失敗でも全変更をrollbackする。

## surface map cleanup

旧Capitalの`map_cell_id`、`x`、`y`をtransaction開始時に保持する。対象はsurfaceだけであり、次のunionとする。

- 破棄Nationが所有する全surface cell。旧Capitalからの距離は問わない。
- current rulesetの`initial_island_reservation_radius`以内にある、ownerなしのsurface cell。

別Nationが所有するcellは半径内でも変更しない。対象cellは通常の空の海へ戻し、terrainをsea、ownerをnull、populationを0、facility・monument・terrain quantity・facility scale・experience・operational stateをnullにする。各cell versionを1増やし、変更された各chunk versionもchunkごとに1回だけ増やす。対象cell上のmonsterはrewardやkill statなしで除去し、`removal_reason = nation_abandoned`を記録する。

## Nation assets and history

成功時はmoneyと全resource amountを0にし、sale policyとcommand queueを削除し、idle counterを0にする。NationCapitalとactive NationMembershipも削除する。Nation row、nation number、name、registered turn、audit/event、award、monster kill record、messageなどの歴史recordは削除しない。

current turn、economy、disaster、monster spawn、ranking、public island listはactive Nationだけを対象にする。archived Nationをcurrent pathへ残すためのfake Capitalは作らない。

## Re-registration

membership削除後、同じUserは同じWorldへ別名の新しいNationを登録できる。`nation_creation_requests`は`request_key` uniqueを維持し、`(user_id, world_id)` uniqueを通常indexへ変更する。completed requestは削除せず、過去のrequest keyを再送した場合は過去の同じrequest/Nationを返す。新規登録には新しいrequest keyが必要である。

World内Nation名uniqueとmonotonic nation numberは維持する。abandoned Nationの名前は再利用できず、新Nation numberはabandonedを含む過去の最大値より大きくなる。旧Capital rowは削除済みなので、新規配置のminimum capital distance判定を塞がない。

## Public event

成功時はpublic `nation.abandoned` eventを1件記録し、重大ニュースを正確に`「○○島は破棄され、忘れ去られた。」`と表示する。同じrecordをoperator audit traceとしても使用し、actor、World、turn、旧Capital、owned/neutral cleanup countをmetadataへ残す。
