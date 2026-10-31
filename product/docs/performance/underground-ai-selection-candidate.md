# 未覚醒AI選択の再利用候補（BU-03）

確認基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。**同一行動の二重走査と局所削減可能性を確認済み。本PRでは未採用。**

`AlphaV1CombatModel::executeTurn()`は未覚醒playerに対し覚醒検出用の`ai->select(...,0)`を呼び、覚醒しない場合もwhileの初回に同じ状態で再選択する。`actionImpaired()`がtrueなら行動が終了し、falseなら状態を変えない。未覚醒の`useAwakeningTechnique()`も直ちにfalseを返すことを追加照合した。`PriorityCombatAi::select()`自体はstateを変異させない。

最初のnon-awakening候補だけを一行動内で使い、初回使用後に捨てる隔離prototypeは、lab32/solo8/PT8の計48戦でserialized result全体hashとRNG呼出数が全一致した。AI selectは9,427→6,232 / 150→100 / 567→356。単発時間は373.35→361.85 / 4.229→4.225 / 24.045→23.629 msであり、大きな時間改善を証明していない。証拠は調査成果の`perf-audit-20261031/battle-probe*`に保全している。

覚醒直後・無料skill後・jump/MP blocked/明示target・案内人固有の全分岐は未検証。既存lowest-HP選択共通化（b671717f）とは異なる候補である。戦闘modelを変える独立差分となり、未覚醒以外で結果を再利用してはならない。catalog最適化に混ぜず、追加の具体的なCPU根拠と分岐代表を揃えた時点で採否を判断する。

## English summary

An isolated prototype reused the initial non-awakening AI decision within one action. All 48 complete-result hashes and RNG call counts matched, while AI selection calls decreased. Single-run timing gains were small and several awakening/free-action branches remain unverified. Keep this separate from catalog reuse and from the existing lowest-HP refactor.
