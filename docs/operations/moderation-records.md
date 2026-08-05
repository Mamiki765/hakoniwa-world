# Moderationの初期運用

## PR23の境界

禁止対象は、違法な内容、個人情報の掲載、なりすまし、差別・嫌がらせ・脅迫、明らかな荒らし行為である。player向けの方針はproduct/docs/community-guidelines.mdを正本とし、TOPと利用ルール画面から設定可能な外部連絡窓口へ案内する。

PR23はUser、Nation、turn、profile、人口、地形の状態を変更する停止・BAN・罰則を持たない。記録commandは対象を参照するだけで、gameplay dataを変更しない。

## External contact

productionではHAKONIWA_MODERATION_CONTACT_URLへoperatorが管理するHTTPSのformまたは案内pageを設定する。credential付きURLや個人用管理URLを設定しない。release preflightは絶対HTTP(S) URLでない場合に非ゼロ終了する。

固定の72時間対応義務は設けない。個人運営として確認可能な範囲で順次対応し、第三者の個人情報や認証情報を不要に収集しない。

## 記録command

server shell accessを持つoperatorだけが実行する。categoryはpolicy-violation、contact-received、reviewed、resolvedなど、事実の種類が分かる小文字識別子にする。summaryには罰則指示ではなく、確認できた事実を1行で記録する。

    php artisan hakoniwa:moderation-record policy-violation nation 42 \
      --operator=operator-name \
      --summary="公開プロフィールについて外部窓口から連絡を受領" \
      --confirm=policy-violation:nation:42

成功時はmoderation_recordsとadmin visibilityのaudit eventが同じtransactionへ追加される。失敗時は非ゼロ終了し、どちらにも途中記録を残さない。player APIやTOPニュースへsummary、operator identifier、admin eventを公開しない。

記録は自動削除しない。訂正が必要な場合も既存行を編集・削除せず、新しいcategoryで経緯を追記する。

## 公開後

アカウント停止、Nation停止、ターン停止、島沈没、人口被害、強制地形変更、Nation状態変更は、別の管理・天罰system PRで安全性とappealを設計してから実装する。
