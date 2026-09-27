# やまにてぃ API

## route一覧

全routeは auth:sanctum、maintenance、cookie/session、FrameGuard、throttle:apiを通る（app/routes/api.php:21-47）。

| Method / path | Controller | 用途 |
| --- | --- | --- |
| GET /api/islands/{island_id} | Api/Islands/DetailController::get | 最新turnの公開島情報・全地形 |
| PATCH /api/islands/{island_id} | DetailController::patch | 所有島名/owner名変更 |
| PUT /api/islands/{island_id}/plans | PlansController::put | 計画JSON全置換 |
| POST /api/islands/{island_id}/comments | CommentsController::post | 所有島紹介更新 |
| POST /api/islands/{island_id}/bbs | Bbs/IndexController::post | 公開/非公開投稿 |
| DELETE /api/islands/{island_id}/bbs/{bbs_id} | Bbs/DetailController::delete | 投稿削除 |

島取得も認証必須であり、公開観光画面はAPIではなくWeb Controllerから直接DB取得する。独立したmap、log、ranking、turn、管理APIはない。

## request/response

Form RequestとAPI Resourceは存在しない。ControllerがValidator facadeを使い、配列を手組みする。

- 共通成功は200 JSON。
- bad_requestは400、forbiddenは403、not_foundは404、maintenanceは503（WebApi.php:4-43）。
- validation詳細は多くのAPIで返さず、既定codeのみ。
- 改名はlack_of_funds、not_changed、重複名codeを返す。
- pagination、cursor、ETag、cache header、response envelope versionはない。

GET islandはid/name/owner_name/commentとterrains全225件を返す（Api DetailController.php:19-40）。Status、logs、rankingは返さない。各terrainにはimage_path/infoが重複して含まれるため、payloadは永続JSONより大きい。

## 認証・CSRF・rate limit

Sanctumはweb session guardを参照し、API routeがcookie/session middlewareを明示的に含む（config/sanctum.php:18-36、routes/api.php:21-30）。VerifyCsrfTokenの除外は空である（VerifyCsrfToken.php:7-16）。同一Laravel Web UIからのAxios呼出しを前提とする。

rate:

- web/api: user IDまたはIPごと60/分。
- comment、register、BBS: user IDまたはIPごと60/時。

根拠: RouteServiceProvider.php:50-70。

## 認可

Policyはない。改名、計画、紹介はログインUserのisland.idとpath island_idをControllerで比較する。BBS投稿は島保有だけを要求し、削除Controllerが投稿者/対象島との関係を検査する。認可規則の一元化はない。

## 計画validationの注意

PUT plansはplanがstringであることとPlans::fromJsonで例外が出ないことだけを検査する（Api PlansController.php:14-49、PlanService.php:43-51）。最大30件、座標範囲、amount、対象島、解放条件、payload sizeをroute境界で明示しない。未知keyはarray参照/matchで例外になり400へ変換されるが、schema error表現は安定していない。

## 共有世界APIへの再設計

島ID全件取得を矩形またはchunk取得へ置換する必要がある。候補:

- GET /worlds/{world}/layers/{layer}/chunks?keys=...
- GET /worlds/{world}/layers/{layer}/cells?min_x=...&max_x=...&min_y=...&max_y=...&as_of_turn=...
- responseにworld_version、turn、chunk_version、definition_versionを含める。
- owner/nation、capital、visible metadataを検索列からDTO化する。
- ETag/If-None-Match、複数chunk batch、圧縮、最大viewport制限を使う。
- 非公開情報はPolicy/Resourceで除外し、EntityのisPrivate booleanだけに依存しない。

レスポンスサイズ上限とrateは実測が必要だが、今回はサーバーを起動していないため数値は未測定である。
