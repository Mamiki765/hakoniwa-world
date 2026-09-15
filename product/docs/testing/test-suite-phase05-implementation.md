# Test suite再設計 Phase 5 実装・検証記録

Date: 2026-09-15

Branch: `release/4.2.0`

Base: `origin/main` `00182bd0eaee52f34194c7b40bc5e98712108718`

## 固定planとLPT

- plannerはscope全件を通したpassing evidenceのworker統合JUnitからtest file別case秒数を合算する。fixture別JUnitは同じcaseを含むため重複加算しない。Phase 2以前のfocused実行機能がなかったlegacy PASSは互換入力として扱う。現在のfocused run、失敗run、symlink、不正XML、現在のdiscovery外のfileは入力にしない。
- timingがあればLongest Processing Time first（LPT）で重いfileから現在の予測負荷が最小のworkerへ置く。同値はpathとworker indexで決定し、同じ入力から同じ割当を得る。
- 履歴のないfileは同じfixture profileの中央値を使い、そのprofileにも履歴がなければ全体中央値を使う。timingが1件もない環境ではpath sort後のround-robinへ戻る。
- run開始時にschema `hakoniwa.test-shard-plan.v1`のJSONを1回作る。scope、発見集合、割当、resolved weight/source、予測秒数、source/composer hashを保存する。作成後にdiscoveryが変わったplanは拒否する。
- DB managerは固定planのworker数と割当をmanifestへ入れる。runnerはDB準備、file選択、fixture区分、evidenceまで同じplanを使い、途中で再計算しない。evidenceへ`shard-plan.json`を保存する。
- Quality CIの各PHPUnit jobも固定planを作り、coverage確認、describe、files、profilesを同じplanから読む。CIにpassing historyがなければdeterministic fallbackになる。

## 増やした検証

配分機構には、同じ集合を一度ずつ実行する安全性に直接必要な3 casesだけを追加した。

1. LPTが重い4 filesを単純round-robinより均すこと。
2. 履歴欠落時にfixture profile中央値、次に全体中央値を使うこと。
3. scope全件を通したpassing JUnitだけを読み、focused PASSを除外し、固定後にdiscoveryが変わればplanを拒否すること。

Phase 4終了時1,031 casesから3 cases増え、再設計後は121 files / 1,034 casesになった。serialと4 shardのidentifierを列挙し、assigned / unionも1,034、duplicate / missing / unexpectedは0だった。

## focused実測

新しい分割fileのtimingを得るため、Phase 4の9 classesを4 workersで実行した。開始時のLPT予測は736.507 / 736.842 / 736.507 / 736.507秒、実行対象filterのworker wallは104 / 159 / 129 / 198秒だった。予測値はFull file集合の過去timing、実測値は111 casesのfocused filterであり、両者を直接比較しない。

focused runは新規分割fileの時間傾向を確認する証拠として残すが、file内の一部だけを選べる一般のfocused evidenceをFullの重みには採用しない。Phase 6の初回Full planは、利用可能なscope全件PASSとlegacy PASSだけを読み、新規fileをfixture profile中央値で補う。Phase 6のPASS後は、次回から再設計後の全121 filesを実測値で配分できる。

plannerの12 tests / 76 assertions、固定plan CLIのcreate/describe、runner統合をPASSした。最終Full4では初回planが99 / 121 filesのlegacy timingを使い、予測case秒数は696.867 / 695.270 / 696.289 / 695.270だった。実worker wallは1109 / 1012 / 1182 / 1045秒で、分割後fileを中央値で補った初回は最大170秒の偏りが残った。

Full PASS後の確認で、fixture別JUnitとworker統合JUnitを同時に読むとcase秒数を二重加算する不具合を検出した。統合JUnitだけを読むよう修正し、同じplanner caseへfixture別JUnitを無視するassertionを追加した。修正後の次回planは全121 filesを実測値で読み、予測case秒数を1086.401 / 1085.833 / 1086.081 / 1085.833へ配分した。次回所要時間の改善見込みであり、Phase 6の実測結果として扱わない。

最終Full4の件数、skip、cleanupと速度判断は[Phase 6記録](test-suite-phase06-verification.md)を参照する。
