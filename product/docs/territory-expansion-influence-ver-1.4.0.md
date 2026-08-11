# ver 1.4.0 領土拡張・領地感化契約

## Scope

ver 1.4.0は、手動command `territory_expand`の境界拡張と、turn処理の自動的な領地感化だけを追加する。通常開発command、missile、災害、怪獣actor、Nation lifecycle、防壁・抵抗・報復は変更しない。

公開済み`hakoniwa-2s-plus-v1`と`hakoniwa-2s-plus-v2`のpayload・checksum・semanticsは不変とし、新契約は`hakoniwa-2s-plus-v3`だけで有効にする。

## 箱庭諸島2＋sourceから採用した挙動

- `command.c`の`Widen`（おおむね396–435行）は、中立の非water/monster陸地に加え、他国所有の荒地を取得でき、成功時はownerだけを変更する。
- `map.c`の`Map::infLand`（おおむね211–260行）は、対象cellごとに6隣接から1方向を選び、条件を満たす別ownerの隣接cellへownerだけを即時変更する。
- `turn.c`（おおむね26–36行）の実行順とcell shuffleにより、後続cellは同一turn内の先行owner変更を観測する。
- `hakow.js`（おおむね1278–1279行）は領地感化による所有移転を公開logとして表示する。

原sourceに2S+のCapital core、Nation lifecycle state、独立monster occupancy、shared 60×60 World、transaction/queue、ruleset versioningと同等の概念はない。以下の境界は原作から補完せず、ver 1.4.0のowner decisionとして定義する。

## 手動territory_expand

actorはactive Nationに限定する。従来どおり、中立の`wasteland`、`scorched`、`plain`、`forest`、`mountain`を取得できる。追加で、別active Nation所有の`wasteland`または`scorched`だけを取得できる。

共通条件は、targetの6隣接のいずれかがactor所有、targetにfacilityとmonster occupancyがなく、targetが他active NationのCapital coreでないこと。自領、foreign settlement/forest/facility、dormant/sunken所有cellは失敗する。

成功時はownerだけをactorへ変更し、terrain、population、facility、facility scale、resource、cell stateを保持して100億円を消費する。失敗時はeffect・costなしで当該queue itemだけを消費し、後続itemの実行を継続する。previewとruntimeは同一policyで判定する。

## 自動territory influence

対象とsourceのownerは、同じWorldに属する互いに異なるactive Nationでなければならない。

対象cellは、settlement、forest、farm、factory、missile base、defense facility、mountain/mineに限定する。neutral、dormant、sunken、wasteland、scorched、water、seabed base、monument、monster occupied cell、Capital coreは対象外とする。

sourceは6隣接のactive Nation所有cellとする。neutral、water、wasteland/scorched、seabed base、monster occupied cellはsourceにならない。Monumentは取得対象にならないがsourceにはなれる。Capital core内cellも外側へのsourceとして通常どおり機能する。

処理はturnの共有surface cell shuffle順を再利用し、各cellを1回だけ訪問する。対象になったcellごとに専用のversioned random streamで0–5の1方向だけを一様抽選する。map外または不適格sourceでも再抽選しない。成功時はownerだけを即時変更し、同じturnの後続cellは変更後ownerを見る。このため同一turn内の連鎖を許可し、snapshot同時解決や専用spread loopは採用しない。

## Capital core

各active NationのCapitalからhex distance 2以内の19 cellsをCapital coreとする。`territory_expand`と`territory influence`による他Nationへのownership transferだけを禁止する。missile、災害、terrain change、neutralizationなど既存effectは変更しない。

dormant Nationの占領とdormant Capital保護は未決のまま維持し、今回実装しない。

## Eventと可視性

手動拡張は`command.territory_expanded`、自動感化は`territory.influenced`をpublic eventとして記録する。構造化metadataには座標、旧owner、新owner、ownership change発生を含める。terrain、facility、scaleその他のhidden stateはpublic metadataへ含めない。公開projectionは安全なmessageだけを返す。

## Ruleset migration

forward migrationはv3をimmutable snapshotとしてpublishし、production Worldをresetせずv2からv3へ進める。現在のWorldに属するcommand queue item、全stateのmonster instance、Nation monster kill aggregateをstable keyでv3 definitionへremapする。queueのorder、quantity、coordinates、parameters、request key、statusと履歴は保持する。ただし実行待ちのv2 `territory_expand`はv3へ付け替えると意味が変わるため、1件でも残っていればlive stateを変更せずfail closedし、事前の明示解消を要求する。

完了済み・過去のTurnRunとaudit/event historyは変更しない。次turnのnon-dry TurnRunが存在する場合、live referenceが期待rulesetと一致しない場合、stable-key setが一致しない場合、kill aggregate collisionがある場合はfail closedとする。migrationはidempotentかつforward-onlyである。

## 保留事項

- 防壁都市、占領抵抗、抵抗値
- dormant territory占領
- dormant Capital protection
- 報復・反撃system
- legacy foreign-water command挙動

これらはver 1.4.0のterritory featureから分離し、個別のowner decisionなしに拡張しない。
