# やまにてぃ ライセンス・第三者素材

## 確認結果

対象コミットのリポジトリ直下およびapp直下にLICENSE、LICENCE、COPYING、NOTICEファイルは見つからなかった。READMEにも独自コード・画像素材の利用条件や出典記載はない。

app/composer.json:6にはlicense: MITとある。ただしname、description、CHANGELOGもLaravel application skeleton由来のままである（composer.json:2-6、app/CHANGELOG.md）。このmetadataだけから、リポジトリの独自ゲームコード全体にMITが明示許諾されているとは断定しない。

app/package.jsonとroot package.jsonにlicense fieldはない。検索で独自source headerのcopyright/license表記は確認できなかった。

## 画像

app/public/imgには98ファイル、そのうち app/public/img/hakoniwa/hakogif に95ファイルがある。Cell classはland*.gif、monster*.gif、monument*.gif等を直接参照する。README・source内にhakogifの作者、取得元、利用条件、改変条件の記載は見つからない。

Google sign-in button、Yahoo iconも含まれる。これらは各providerのbrand guidelineが適用され得て、コードlicenseとは別に扱う必要がある。

## 第三者要素

Web topはTAITOの「しまにてぃ」の要素を取り入れたと説明する（pages/index.blade.php:23-30）。名称、ゲームルール、画像、文言の権利関係はこのrepositoryだけでは確定できない。

Composer/npm依存は各package自身のlicenseに従う。lock fileを使ってSBOM/license inventoryを生成する作業は将来の依存導入環境で別途必要だが、今回は実行していない。

## 判定

| 対象 | 現時点の扱い |
| --- | --- |
| やまにてぃ独自PHP/Vueコード | 構造・挙動の参考に限定。直接copy許可を断定しない |
| composer.jsonのMIT表示 | Laravel skeleton/project metadataの記載として記録。適用範囲不明 |
| hakogif画像群 | 出典・条件不明。新作へcopyしない |
| Google/Yahoo画像 | provider brand assetとして別条件を確認 |
| 一般的な設計概念 | 独自に再設計・再実装する |

## 必要な追加確認

1. GitHub repository pageのlicense表示と作者の明示意図。
2. 過去のREADME、release、blog、issueにあるlicense説明。
3. hakogif各画像の原作者・配布元・改変/再配布条件。
4. TAITO作品の名称・表現・画像との関係。
5. commitごとの外部contributorとDCO/CLA有無。
6. Google/Yahoo brand assetsの現行guideline。

これらは法的結論ではない。新作は独自コード、独自文言、権利が明確な画像だけを使い、参考物のファイルをproductへコピーしない。
