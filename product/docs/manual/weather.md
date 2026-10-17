# 天候と災害

海は16×16マスごとの「海域」に分かれていて、ターン更新のたびに海域ごとの天候が決まります。ほとんどはただの空模様ですが、台風・流星群・終末の三つは被害を出す災害です。

## 天候を見る

地図の「海域・天候」を押すと、海域の区切りと、それぞれの天候が出ます。地図の隅には、選んでいるマス（選んでいなければ首都）がある海域の天候が大きく出ます。

**表示されているのは、その更新で実際に起きた天候です。** 次のターンの予報ではありません。

## 天候の種類

| 天候 | 起きること | 起きやすさ |
|---|---|---|
| 台風 | その海域の農場・風力発電所・ハリボテが、吹き飛ばされることがあります | {{ruleset:turn_processing.sea_area_weather.fixed_probabilities.typhoon/100}}% |
| 流星群 | その海域のどこかに、いくつか隕石が落ちます | {{ruleset:turn_processing.sea_area_weather.fixed_probabilities.meteor_shower/100}}% |
| 終末 | その海域のどこかに**巨大隕石**が落ちます | 約0.3% |
| 晴れ・曇り・雨・雪・雷 | 雨のときは、その海域の森がよく育ちます。ほかは何も起きません | 残り |

確率は、一つの海域・1回の更新あたりです。どれも決まった値で、島の大きさや人口では変わりません。災害にならなかったときは、晴れ{{ruleset:turn_processing.sea_area_weather.normal_weights.sunny}}・曇り{{ruleset:turn_processing.sea_area_weather.normal_weights.cloudy}}・雨{{ruleset:turn_processing.sea_area_weather.normal_weights.rain}}・雪{{ruleset:turn_processing.sea_area_weather.normal_weights.snow}}・雷{{ruleset:turn_processing.sea_area_weather.normal_weights.thunder}}の割合で決まります。

### 終末（巨大隕石）

終末の天候になった海域では、どこか一か所に巨大隕石が落ちます。落ちた場所は海になり、その周りは浅瀬や荒地になるほど大きくえぐられます。周りの施設・人口・船も、ほとんどが失われます。

めったに起きませんが、起きたら防ぐ方法はありません。大事な施設を一か所に集めすぎない、立て直すための資金や食料を残しておく、といった備えが助けになります。落ちた跡には宝が残ることもあります（[海と船](/manual/sea)）。

### 台風への備え

台風の海域にある農場は、周りの6マスにある森・記念碑・中央銀行・中央穀倉の数が多いほど、失いにくくなります。大農場はさらに失いにくくなります。

[[index:typhoon]]

**当たってしまえば、大農場もなくなります。** 規模が減るだけで耐える、というわけではありません。風力発電所とハリボテは、農場と同じ確率です。

## 天候のほかの災害

天候とは別に、島ごとに起きる災害もあります。

| 災害 | 主な被害 | 備え |
|---|---|---|
| 火災 | 工場や都市などが焼ける | 周りの森や記念碑で和らぐ |
| 地震 | 工場・発電所・大きな都市などに被害 | 大工場は少し耐える |
| 津波 | 海に近い施設や集落に被害 | 海から離れた場所に置く |
| 噴火 | 山ができ、周りの地形も変わる | ― |
| 地盤沈下 | 広げすぎた島の海沿いが沈む | 陸地を広げすぎない |

地ならしをすると、まれに地震が起きます。

## 地盤沈下と島の広さ

陸地が一定の広さを超えると、地盤沈下が起きるようになります。沈むのは海沿いの陸地と浅瀬です。土地を広げるだけでなく、一つのマスの施設を大きく育てるのも島を伸ばす方法です。

## ランク2の施設は少し強い

農場・工場・採掘場を上限まで育てると、ランク2（大農場・大工場・大採掘場）になります。ランク2は一部の災害やミサイルを、全部失うかわりに規模が減るだけで耐えます。

| 被害 | 大農場 | 大工場 | 大採掘場 |
|---|---:|---:|---:|
| ミサイルなどで荒地・焼け野原になる | −{{ruleset:facility_rank_system.definitions.farm.damage_scale_loss.ordinary_terrain_destruction*1000}}人 | −{{ruleset:facility_rank_system.definitions.factory.damage_scale_loss.ordinary_terrain_destruction*1000}}人 | もともと効きません |
| 陸地破壊弾・隕石などで陸地がえぐられる | −{{ruleset:facility_rank_system.definitions.farm.damage_scale_loss.land_destruction*1000}}人 | −{{ruleset:facility_rank_system.definitions.factory.damage_scale_loss.land_destruction*1000}}人 | −{{ruleset:facility_rank_system.definitions.mine.damage_scale_loss.land_destruction*1000}}人 |
| 火災・地震 | ― | −{{ruleset:facility_rank_system.definitions.factory.damage_scale_loss.fire*1000}}人 | ― |

- 巨大隕石の真下になったときと、怪獣に踏まれたときは耐えられません。巨大隕石の周りなら、上の表のとおり耐えます
- 規模が減ってランク1に戻ると、この強さもなくなります。増設してまた上限を超えれば、ランク2に戻ります

## 被害に遭ったら

島のログで、災害の名前・場所・何を失ったかを確かめます。農場を失ったら食料を、工場や採掘場を失ったら収支を見直しましょう。立て直しのために食料まで売ってしまうと、飢饉が重なることがあります。

首都地下の地底設備は地上の災害を受けないので、立て直しの支えになります。
