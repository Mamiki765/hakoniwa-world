# Public lobby and island dashboard

## Scope

Roadmap PR5 は、共有世界の公開閲覧、自島中心の responsive UI、開発計画の予約・編集境界を提供する。turn runner、command execution、資金・地形・施設・資源の変更は行わない。

canonical coordinate は PR4 の staggered square-tile x/y である。現行 API、TypeScript、PHP、UI に q/r を再導入しない。

## Public and private API

guest が利用できる endpoint は `/api/v1/public` 以下へ分離する。

- `GET /worlds`
- `GET /worlds/{world}/summary`
- `GET /worlds/{world}/rankings`
- `GET /worlds/{world}/events`
- `GET /worlds/{world}/map-spaces`
- `GET /nations/{nation}`
- `GET /nations/{nation}/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}`

public route は 1分60 request の rate limit と、`public, max-age=30, stale-while-revalidate=60` を使う。public map endpoint は常に guest representation を生成するため、Cookie の viewer state と混同しない。

認証 route は従来どおり `/api/v1` 以下に置き、全 response を `private, no-store, max-age=0`、`Vary: Cookie` とする。`/me` の401は正常なguest状態であり、Frontendはpublic dataの読込を継続する。

## Nation profile and resource capacity projection

PR19の公開rankingとNation detailは、島名に加えて`owner_name`と`comment`を返す。両fieldは保存済みplain textをそのままJSON stringとして投影し、Frontendはtext interpolationだけで表示する。HTML rendering、Markdown、URL link化、OAuth profileへの参照、provider metadataの公開は行わない。

プロフィール更新はowner-onlyの`PATCH /api/v1/nations/{nation}/profile`へ分離する。`owner_name`と`comment`はpartial update可能で、422 validationをfield単位で返す。guestは401、別Nationの利用者は403、過去ruleset Worldは`reset_required`の409を返す。

owner Nation responseでは資金・食料の既存capacityに加え、全resource itemへ`capacity`、`remaining_capacity`、`is_at_capacity`を返す。PR19の工業品は`unit` / `ユニット`、鉱物は`ton` / `トン`で、双方の個別上限は9,999,000である。food itemのcapacityは種類別上限ではなく既存のfood category合計上限を示す。public ranking、public Nation detail、public mapへ正確な資源残高・capacityを追加しない。

## Ranking and public money

人口ランキングは次の安定順序を使う。

1. 所有cell人口合計 descending
2. 所有cell数 descending
3. nation ID ascending

内部moneyの1単位は1億円である。owner response は整数値と `62,728億円` 形式の `money_display` を返せる。public response は正確値を返さず、`money_display` と `money_bucket` だけを返す。

| 内部money | public display | bucket |
| ---: | --- | --- |
| 0–499 | `500億円未満` | `under_500` |
| 500–999 | `約500億円` | `500` |
| 1,000以上 | 1,000億円単位で切り捨てた推定値 | 推定値のdecimal string |

500–999を500 bucketへ固定し、`約0億円`を生成しない。Frontendにも同じformatterを置くが、public APIの正確値をFrontendで丸める用途には使わない。

## Viewer secrecy and public events

guest map は既存のviewer-safe presenterを `viewerNationId = null` で通す。disguised missile baseは通常の他国forestと同じterrain、asset、facility null、details空、public versionを返す。施設key、経験、level、稼働状態、aria label、audit metadata、樹木量をresponseへ含めない。通常の他国forestも樹木量を返さない。

public eventsはraw `audit_events`を返さない。PR5のallowlistは `nation.created` だけで、projectionはmessage、nation ID、nation name、occurred_atだけを返す。秘密座標、資源、facility、exception、stack traceはprojectionへ入れない。将来は同じpublic projection境界へsystem announcementとturn eventを追加する。

PR21のowner-only player island event projectionは怪獣eventをallowlistへ追加するが、metadata自体は返さない。Nation attributed final blowは`monster.reward_distributed`一件をrole-awareに投影し、killerには撃破とapplied賞金、死亡時hostには怪獣肉のappliedトン数を示す。同一Nationなら両方を一つの明確なmessageへまとめ、neutral hostならkiller messageだけを返す。unattributed death、spawn、movement/trample、hardening block、defense contact、terrain removalも安全な定型文へ変換する。seed、draw、candidate、内部move counter、source metadataは文面/APIへ入れない。

公開Nation detailは対象Nationの`nation_monster_kill_stats`を一query・最大8行で取得する。`monster_final_blow_count = SUM(kill_count)`とdefinition別key/name/`kill_count`/`first_killed_turn`/`last_killed_turn`を返す。

ver 1.3.0の公開TOPランキングだけは全Nationの`nation_awards`と正数の`nation_monster_kill_stats`をtableごとの一括queryで投影する。各Nationは順位と主要数値の1行、島主名とprofile commentの2行目で表示する。ver 1.3.1 overrideにより、2行目はcommentがある場合は`島主名：comment`、ない場合は島主名だけとする。賞は全反復受賞turn、怪獣markは最大stable source kindの画像、tooltipはkind昇順の種類別正確countを返す。raw kind、source metadata、seed、周期内部countは公開しない。World summaryとpublic Nation detailへ`achievements`を追加しない。正本は`docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md`とする。

## Effective 20-slot plan

DBは明示commandだけを `nation_command_queue_items` に保存する。APIは常に20件のeffective planを返し、未使用positionを次のplaceholderで補う。

```json
{
  "position": 3,
  "kind": "automatic_finance",
  "editable": false,
  "command_name": "資金繰り"
}
```

明示commandは `kind: explicit`、`editable: true` とする。挿入位置を省略した場合は最初のautomatic slotへ追加する。位置を指定した場合は、その位置以降の明示commandだけを後ろへshiftする。20を超える明示commandを黙って破棄せず422を返す。取消後は残る明示commandを左詰めし、末尾をautomatic financeで補完する。

reorderは全明示itemのIDとpositionを送る。optimistic queue versionを維持し、drag以外にkeyboardの前後移動を提供する。

automatic financeの実行、`auto_finance_streak`、`last_player_plan_turn`、720turnでの休眠遷移はturn runner／nation lifecycle PRへ延期する。PR5は表示用placeholderと拡張境界だけを持ち、loginだけでstreakを解除する等の挙動を決めない。

## Universal development-plan quantity

PR6はPR5の掘削固有quantity metadataを廃止し、全commandへ共通のfirst-class `quantity`を追加する。既存queue itemはdata-preserving migrationで`parameters.quantity`をcolumnへ移し、quantityがないitemは1とする。移行対象値が整数1–99でなければ、推測や切り捨てをせずmigrationを停止する。

```json
{
  "type": "integer",
  "minimum": 1,
  "maximum": 99,
  "default": 1,
  "quick_presets": [1, 5, 10, 25, 50, 99]
}
```

quantity省略はdefault 1として有効だが、明示的`null`は422とする。UIはすべてのcommandで同じpresetとvalidationを使い、planには`×1`を含めて数量を明示する。desktopはdouble clickまたはquantity button、keyboardは`q`またはEnter、mobileは行tapで編集を開ける。

既存のJSON `parameters`は将来のcommand固有parameter用に維持するが、quantityを二重に保持しない。required parameterの不存在時だけdefault適用を許し、required parameterの明示的`null`はvalidation errorとする。

PR6は予約、表示、編集、validationだけを行う。quantityに応じた費用、生産量、複数回実行、decrementなどの意味はturn runner実装前の設計判断まで延期する。

## Responsive interaction

desktopはleft command、center map、right 20-slot planの3列とする。初期cameraはviewport sizeとcapital absolute x/yから計算し、固定pan値を使わない。hoverとkeyboard focusは取得済みviewer-safe cellから同じtooltipを生成し、追加API requestを送らない。

mobileはcompact HUDの直後にmapを置く。command/cell情報はbottom sheet、planはtop drawerにし、一方を展開すると他方を閉じる。zoom button、tap、44px以上の主要target、plan内scrollを維持し、hover、double click、context menuだけに依存しない。
