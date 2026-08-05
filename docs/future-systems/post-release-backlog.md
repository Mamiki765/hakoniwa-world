# 初回公開後backlog

PR23は箱庭諸島２S＋の初回production baselineを固定する。以下は初回公開へ含めず、別roadmapとowner decisionまでextension boundaryだけを維持する。

## 賞

- turn賞
- 繁栄賞
- 平和賞
- 討伐賞
- 災難賞

threshold、繰返し受賞、取消、公開表示、historical backfillはAWARD-01で決める。PR23は既存の討伐統計を維持するがawardを生成しない。

## Combatと報復

- 報復・反撃system
- dormant territoryへの攻撃・占領
- Capital周辺保護と防壁

PR22のpublic/private missile visibilityとactive Nation限定targetを維持し、報復予約やhidden metadataを先行追加しない。

## Lifecycle

- 30日経過による休眠状態遷移Job
- detailed dormancy、復帰、領土解放、沈没
- player放棄、取消期間、再認証、cooldown、再入植

PR23の登録turn、経過turn、連続資金繰り回数は表示だけに使い、state transitionを起こさない。T-02はOpenのまま維持する。

## 資源と施設

- 石油resource stockpile
- 万barrel単位
- 油田からの石油resource生産
- その他の追加resource
- その他の追加facility

現在の海底油田による直接資金収入と枯渇は維持する。石油を新しい保有resourceとして追加する変更は別roadmapとする。

## UIと運用

- measured UI redesign
- tooltip改善
- 高度なaccessibility polish
- moderation通報管理画面、期限管理、高度appeal workflow、自動禁止語判定
- アカウント・Nation・turnの停止、島沈没、人口被害、強制地形変更などの管理・天罰system
- stale-running自動回収、backoff付きretry、retry上限、外部通知
- continuous WAL archive、PITR、RPO 15分以内
- event archive、集約、削除（100万eventまたは実測問題で再判断）

PR23は初回公開に必要な安全性とoperator手順だけを実装し、これらの将来機能を空schemaや未使用hookとして先行させない。
