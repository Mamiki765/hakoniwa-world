# 避雷針による隕石誘導

## 目的

避雷針に相当する施設やitemが、隕石を消すのではなく落下候補の重みを変更し、特定地域へ誘導する仕組みを設計する。複数施設、射程、上限、誤差、防御との競合を決定的に処理する。

## 背景

隕石は被害とitem発見機会を同時に持つ。単純な無効化施設ではriskとrewardの選択が消える。target weightを調整する方式なら、自国領土へ希少itemを呼び込む代わりに被害riskを負う戦略が成立する。

一方、他国領土への不当な誘導、施設数による無限重複、確率overflow、誘導後の防御順序を明示する必要がある。

## 既存作品との関係

- disastersが隕石eventの基礎発生を決める。
- map spaceが候補セルとhex distanceを提供する。
- facilitiesとitemsがtargeting sourceになる。
- modifiersがweight addまたはmultiplyを表す。
- event logが候補取得、誘導、重み付き抽選、被害、item付与を記録する。

## 暫定設計

隕石ごとに基礎候補セル集合を作り、各セルへbase weightを与える。有効な誘導sourceは射程内セルのweightを加算または倍率変更する。その後weight付き抽選で落下中心を選び、誤差分布を適用して最終落下セルを決める。

誘導は隕石の発生数を減らさない。発生率増加itemがある場合は、発生判定と位置誘導を別ステップ・別乱数streamにする。

## 処理フロー

```text
隕石発生判定
→ 基礎候補セル取得
→ 誘導source収集
→ セルごとのtarget weight計算
→ 上限・禁止領域を適用
→ 重み付き抽選
→ 誤差・散布を適用
→ 防御・被害処理
→ item付与抽選
→ 構造化event生成
```

抽選前後の候補全量をplayer logへ出す必要はないが、turn_runの再現に必要なseed、ruleset、source、選択結果を管理用に追跡する。

## target weight

暫定式の例:

```text
effectiveWeight = clamp(baseWeight + additiveGuidance, minimum, maximum)
effectiveWeight = effectiveWeight × multiplicativeGuidance
```

加算と倍率の順はmodifier共通規約に従う。weightは確率そのものではなく相対値であり、全候補weightの合計で正規化する。負値、NaN、overflowを許さない。全weightが0の場合のfallbackを定義する。

## 誘導source

- source coordinateとlayer。
- radiusまたはdistance decay。
- additiveまたはmultiplicative weight。
- owner nationと対象relation。
- max affected cells。
- stacking group、priority。
- active state、durability、energy cost。
- per-turn usage cap。

射程内を全て同じ加算にする方式と、距離で減衰する方式がある。初期は同一weightの小半径が説明しやすい。距離減衰は後からprofileとして追加できる。

## 他国・中立地への誘導

誘導対象を無制限にすると、国境沿いへ多数施設を置いて他国へ災害を押し付けられる。候補ルールは次の通り。

- 自国所有セルだけweightを増やす。
- 自国と中立だけを対象にする。
- 他国は外交上の攻撃commandとして明示し、防御・ログ・費用を付ける。
- 他国へ向かうweightは厳しい上限と距離減衰を設ける。

暫定推奨は、通常施設は自国領土のみ誘導し、他国誘導は別の明示的command・研究として将来検討する。新規保護国家と首都最低保証を迂回して大被害を与えない制約が必要である。

## 複数施設の重複

完全な線形加算は施設数が多い国家を圧倒的に有利にする。候補は、同じstacking groupの最大値のみ、2基目以降逓減、国家ごとの総weight cap、施設ごとのusage消費である。

暫定案はsourceを加算しつつnation×meteorのguidance contributionにcapを置く。複数国家の競合は同時に集約し、国家処理順で勝者を決めない。

## 射程・上限・誤差

- 射程はhex distanceで測る。
- 世界境界外や未生成セルを候補にしない。
- 首都そのものを誘導対象から除外するかは要決定。除外しなくても首都不変条件は守る。
- 誤差は選択中心からdistance別に分布させる。
- 誘導精度を研究やitemで上げても誤差0にできるかはbalanceで判断する。
- 1turnの隕石数、1cellへの集中数、総被害にcap候補を設ける。

## 防御施設との競合

順序は「誘導で位置を決定してから、防御と被害を解決」を推奨する。防御が隕石を破壊、分裂、軽減、逸らす場合は別eventとして処理する。

防御が再び位置を変えると誘導の再帰が起こるため、最大redirect回数を1回などに制限し、同じsourceを再適用しない。item付与率が被害軽減で変わるかも明示する。

## 代替案

- 隕石を完全無効化する: 単純だがriskとitem機会を両方消すため非推奨。
- 落下座標を施設へ固定する: 説明しやすいが確定誘導が強すぎる。
- 候補weightを変更する: 誤差、上限、複数sourceを表現でき暫定推奨。
- 発生地域だけを変更する: 大規模世界では高速だがセル単位の戦略が弱い。

## 利点

隕石を消さずriskとrewardの場所を操作でき、設置位置、射程、国家方針に意味を持たせられる。重み付き抽選は複数施設と誤差を同じ枠組みで扱える。

## 欠点

相対weightはplayerに直感的でなく、候補集合の作り方で実確率が変わる。他国誘導、施設stack、lootと防御の組合せが悪用されやすい。

## データモデル例

- targeting_sourceはfacilityまたはitem instanceを参照。
- targeting_profileはradius、weight operation、cap、relation filter、error profileを持つ。
- meteor_eventはbase seed、candidate policy、selected coordinate、final coordinateを持つ。
- targeting_contributionsはdebugまたは高重要eventだけに、sourceとcontributionを保持する。

候補セル全件を恒久保存すると大きいため、seedと安定algorithm versionから再計算できる設計を優先する。

## ゲームバランス上の懸念

- item獲得期待値と被害期待値を同時に表示・計測する。
- 広い領土が自然に多く被弾する面積biasをどう扱うか。
- 自国への誘導が最適行動になりすぎないrepair cost。
- 複数避雷針の逓減と維持資源。
- 他国攻撃へ転用する場合の可視性、宣戦、報復可能性。
- 防御と誘導の組合せで安全にlootだけ得るloopを防ぐ。

## 性能上の懸念

世界全セルを隕石ごとに候補化しない。災害profileが指定するchunkまたはregionを先にsampleし、その内部でweight計算する階層抽選を候補とする。誘導sourceはlayerと影響chunkで索引し、範囲内だけ取得する。

階層抽選がセル一様抽選と同じ分布になるか、chunkごとの有効セル数と総weightを正しく集約するtestが必要である。

## セキュリティ上の懸念

- client指定のweight、radius、対象relationを信用しない。
- 非公開施設を詳細eventで他国へ漏らさない。
- 不正な巨大weightや合計0をruleset公開時と実行時に拒否。
- seed公開時期を調整し、事前に落下地点を予測できないようにする。
- 管理者の隕石指定発生は監査し、通常eventと区別する。

## 未決定事項

- base candidateを世界一様、陸地一様、国家一様のどれにするか。
- 自国以外への誘導を将来許すか。
- weightの加算・倍率と国家cap。
- 誤差分布、redirect上限、防御順。
- 首都、新規保護、イベント領域のtarget可否。
- item付与率と被害軽減の関係。

## MVP縦切りで必要か

不要。隕石、基礎event、乱数stream、被害、loot、target weightを最初のMVP縦切りへ実装しない。隕石実装時に位置・被害・lootを段階分離し、後からtarget weight phaseを挿入できるようにする。

## 後回しにできるもの

他国誘導、距離減衰、複数redirect、高度な防御競合、誘導予測UI、研究・itemによる精度向上は後回しにできる。
