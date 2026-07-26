# 熟練度・大学・研究

## 目的

農業、工業、採掘、魔法、宇宙開発などの熟練度と、大学・研究所・研究treeを、人口の単純な置換ではなく長期成長の独立軸として設計する。現段階では数値balanceを確定しない。

## 背景

従来の箱庭系では、農場人口等がそのまま生産力へ強く結び付く。これは分かりやすいが、総人口の一定割合を各施設へ配分するだけになりやすく、後から研究、教育、専門化を足すと式が複雑になる。

新作では、労働人口、施設能力、熟練度、研究技術を別入力とし、同じ人口でも国家方針で効率が変わる余地を作る。

## 既存作品との関係

- resourcesの研究力は研究進捗へ投入されるflow資源候補。
- modifiersは研究成果・熟練度による生産倍率や必要人口減少を適用する。
- facilitiesは大学・研究所のcapacityと被害状態を持つ。
- commandsは研究選択、人員配分、施設復旧を予約する。
- turn eventsは研究開始・完了・中断・技術解禁を記録する。

## 暫定設計

労働人口、施設capacity、熟練度、研究技術を別の入力として扱う。実活動から熟練経験を得て、明示的な研究projectから技術を解禁する。初期は国家単位の熟練度と単一研究枠を候補とする。

## 分離する概念

### 熟練度

国家または施設群が、実際の活動から蓄積する経験。農業、工業、採掘、魔法、宇宙開発などdomainごとのstable keyを持つ。利用しない分野が自然減衰するかは未決定。

### 研究

明示的に研究力・資金・人員を投入し、technology nodeを解禁する計画。前提技術、施設、資源、世界layer等の条件を持つ。

### 教育・大学

熟練獲得、研究速度、必要労働人口、技術維持へ影響する施設・制度。大学そのものを研究treeの単一入口に固定しない。

## 代替案

| 方式 | 概要 | 長所 | 欠点 |
|---|---|---|---|
| 生産量倍率 | 同じ労働人口で出力を増やす | 理解しやすい | 高人口国家の複利が強い |
| 必要人口減少 | 同じ出力を少ない労働で得る | 余剰人口を他分野へ回せる | 人口配分UIとcapが必要 |
| 両方 | 熟練は効率、研究は自動化等 | 表現力が高い | 計算・説明・balanceが複雑 |
| 手動配分中心 | 毎turn人員を割り当てる | 戦略性が高い | micromanagementが増える |
| 自動配分中心 | 方針に従って自動化 | 操作負担が小さい | 結果の説明と期待制御が必要 |

暫定案は、施設へcapacityまで労働人口を自動配分し、国家方針で優先順位を変え、熟練度は小さな生産倍率、研究は必要人口減少や機能解禁を担う。プレイヤーは全施設へ人数を毎turn入力せず、分野比率と重要施設だけを調整する。

## 利点

人口規模だけでなく専門化と長期方針に意味を持たせ、同人口の国家に異なる強みを作れる。研究解禁とModifierを分けるため、新技術を段階的に追加しやすい。

## 欠点

成長軸が増えて新規playerとの差が拡大し、労働配分UIと計算説明も複雑になる。熟練・研究・施設levelが同じ効果を重ねると冗長になる。

## データモデル例

- proficiency_definitions: stable_key、ruleset、level curve、decay policy。
- nation_proficiencies: nation_id、definition_id、experience、level、updated_turn。
- technology_definitions: stable_key、research tree、prerequisites、cost profile、effects。
- nation_research_projects: nation_id、technology_id、state、progress、allocated_capacity、started_turn、completed_turn。
- nation_technologies: nation_id、technology_id、acquired_turn、status。
- workforce_policies: nation、sector priorities、minimum services、version。

technology effectは許可されたmodifier definitionまたは明示handlerを参照する。任意式やclass名をJSONから実行しない。

## 処理フロー

1. playerが前提を満たすtechnologyから研究projectを選ぶ。
2. command登録時に見積りを表示し、turn開始時に再検証する。
3. 大学・研究所の稼働capacity、研究者人口、研究力、維持状態を集計する。
4. 災害、封鎖、modifierによる速度を適用する。
5. progressを増加し、閾値到達時にTechnologyCompleted eventを作る。
6. 解禁されたmodifier、command、施設catalogを次の定義済み境界から有効にする。

同じturnの途中で解禁した技術を、そのturn前半の生産へ遡及適用しない。原則として完了後の次フェーズまたは次turnから有効にし、仕様を固定する。

## 雪だるま化への対策

- 研究施設の維持費。
- 研究者として割り当てた人口を直接生産から外す。
- 研究段階が進むほど必要研究量を増やす。
- 並列研究枠に上限を設ける。
- 普及期間を設け、完了直後に全国最大効果としない。
- 施設被害で速度低下するが、進捗全喪失は避ける。
- 技術時代ごとに前提となる地上・地下・宇宙投資を持たせる。

catch-up bonus、共同研究、技術拡散も候補だが、古参の成果を無意味にしない上限が必要である。

## 熟練度の獲得

経験は実生産、成功command、施設稼働など観測可能な活動から付与する。資源を生産して即廃棄するだけの不正な経験稼ぎを避けるため、有効需要、稼働費、逓減を検討する。

熟練levelを直接整数加算するのではなくexperienceを保持し、ruleset curveからlevelを導出する案が移行に強い。curve変更時に既存experienceから再計算できる。

## ゲームバランス上の懸念

- 早期専門化と多角化の両方が成立するようにする。
- 研究が全ての生産を単純倍率で強化しない。
- 低人口国家にも技術的な選択肢を残す。
- 災害による研究損失を長期離脱の原因にしない。
- 魔法と工業など排他的treeを設けるかは慎重に決める。
- 研究情報を公開する範囲は外交・諜報設計と合わせる。

## 性能上の懸念

毎セルに熟練度を持たせず、まずnation×domainで集約する。施設固有経験が必要になった時だけfacility instanceへ追加する。technology prerequisite graphは公開rulesetごとにcycle検査し、到達可能性をcacheする。

## セキュリティ上の懸念

- clientがprogress、experience、completedを直接送らない。
- 研究完了eventと技術付与を同じtransactionで行う。
- 管理者による付与・取消は監査する。
- prerequisite変更で取得済み技術を無言で失わせない。
- 非公開研究をAPI、event、Mariachang通知へ漏らさない。

## 代替案

- 人口だけを生産力とする: 単純だが将来構想を支えにくい。
- 研究だけで効率化する: 明確だが活動による成長がない。
- 熟練だけで解禁する: 自然だがplayerの長期目標を示しにくい。
- 熟練＋研究＋労働方針: 表現力は高いが段階導入が必要。暫定推奨。

## 未決定事項

- 熟練度の所有単位と自然減衰。
- workforceを実数で配分するか、抽象capacityにするか。
- 研究の並列枠、維持費、普及期間。
- 技術treeの分岐、排他、season持越し。
- 共同研究、技術取引、諜報の範囲。
- 災害時の進捗低下・施設被害の式。

## MVP縦切りで必要か

不要。研究tree、熟練度、efficiency、専用tableは最初のMVP縦切りへ実装しない。将来追加時にresourceとmodifierのstable keyを参照でき、人口modelを農場専用の意味へ固定しない境界だけを維持する。

## 後回しにできるもの

大学・研究所の複数種、並列研究、共同研究、技術取引、熟練減衰、複雑な自動配分、season持越しは後回しにできる。
