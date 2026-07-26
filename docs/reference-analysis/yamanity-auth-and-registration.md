# やまにてぃ 認証と新規登録

## 認証方式

通常認証は外部ID連携である。

- Google: Laravel Socialite redirect/callback。provider=googleと外部IDをuser_authenticationsへ保存（Google CallbackController.php:19-44）。
- Yahoo! JAPAN: state/nonceをsessionへ保存し、YConnect SDKでcode、access token、ID tokenを検証。provider=yahooで保存（Yahoo RedirectController.php:17-37、CallbackController.php:20-56）。
- Debug: localかつAPP_DEBUG時だけuser IDでlogin。指定IDがなければUserとdebug認証を生成する意図（Debug LoginController.php:19-41）。

通常のメール/パスワード登録、password reset、ユーザー名入力はroute/controllerにない。User tableの最終schemaは実質idとtimestampsで、認証識別子を別tableに分離する。Sanctumはsession cookie認証に使う。

## UserとIsland

User::islandはhasOne、islands.user_idはuniqueなのでDB上も1ユーザー1島である（User.php:50-56、2023_03_30_112704_craete_islands_table.php:16-23）。Island ModelがUserへbelongsToする。

外部認証の初回callbackではUserのみ作り、島は自動作成しない。ログイン後、/registerで島名とowner名を入力して作成する。

## 登録処理

Register IndexController::postの確定順（app/app/Http/Controllers/Web/Register/IndexController.php:30-118）:

1. island_name、owner_nameを必須string、最大32文字で検証。
2. 既に島を持つUserをhomeへredirect。
3. DB transaction開始。
4. Island::lockForUpdate()->count()で島数を数え、MAX_ISLANDSを検査。
5. 島名、owner名の重複を検査。
6. IslandをUserへ紐付けてsave。
7. 最新Turnを取得。
8. Terrain::create()->init()で独立15×15島を生成し、JSON保存。
9. Status::initで初期状態を計算し、通常カラムへ保存。
10. CashFlow×30のPlansを保存。
11. IslandFoundLogを保存。
12. commit後、所有島plans画面へredirect。

名前unique制約が最終防衛になる。lockの対象は取得済み行であり、0件時のgapやDB isolationによる厳密性は実動確認が必要だが、unique user_id/name/owner_nameは重複commitを防ぐ。

## 初期状態

Terrain::initは中央付近の正規乱数位置へ計38セルを配置する。森4、荒地14、火山1、村2（各人口1000）、平地7、浅瀬10で、残りは海（Terrain.php:103-131）。

Status初期値:

- 発展点0
- 資金3,000
- 食料100,000
- 資源50,000
- 人口は初期村2つから2,000

根拠: Status.php:15-21,58-67。初期施設と呼べるのは村・森・火山で、農場、工場、首都はない。

## 登録失敗

validation失敗は403 JSONを返す一方、最大数/重複はback redirect with errorsであり、応答形式が統一されない。transaction内例外はrollbackされる。外部認証Userだけが残り、後から再登録可能である。

## 削除・休眠・管理権限

明示的放棄Planと、連続CashFlow回数がISLAND_ABANDONED_TURN以上の自動放棄がある。turn内で履歴・global logを作り、島を物理削除する（ExecuteTurn.php:224-231,359-363）。関連行にDB cascadeがないためforceDelete後のsnapshot等が残る可能性があり、挙動確認が必要である。

ユーザー停止、認証unlink、管理者role、強制削除UIはない。島人口0は削除ではなく、発展点半減と村生成で救済する（Terrain.php:404-424）。

## 新作要件との差

やまにてぃは共有世界座標、空き領域探索、首都、world拡張を持たない。同時登録lockの考え方と「島・初期snapshot・ログを1 transactionで作る」点は参考になるが、新作では候補領域とchunkをDB lockし、首都座標・初期領土・world expansion eventを同じtransactionで確定する必要がある。
