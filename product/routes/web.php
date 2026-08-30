<?php

use App\Http\Controllers\Api\AdminInquiryController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\CommandQueueController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\MessageBoardController;
use App\Http\Controllers\Api\NationAbandonmentController;
use App\Http\Controllers\Api\NationDormancyController;
use App\Http\Controllers\Api\NationProfileController;
use App\Http\Controllers\Api\PlayerEventController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\SalePolicyController;
use App\Http\Controllers\Api\SecretaryController;
use App\Http\Controllers\Api\TradingPostController;
use App\Http\Controllers\Api\UndergroundEquipmentController;
use App\Http\Controllers\Api\UndergroundIntroController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\CommunityGuidelinesController;
use App\Http\Controllers\ManualController;
use App\Http\Middleware\PrivateApiResponse;
use App\Http\Middleware\PublicApiResponse;
use App\Http\Middleware\RequireAnnouncementAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/account/link/{provider}/redirect', [OAuthController::class, 'link'])->middleware('auth')->name('oauth.link');
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
Route::post('/logout', [OAuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/assets/hakoniwa-tiles/{filename}', AssetController::class)
    ->where('filename', '[A-Za-z0-9_-]+\.(?:gif|png|webp)');
Route::get('/assets/hakoniwa-tiles/{theme}/{filename}', AssetController::class)
    ->where(['theme' => 'snow|peridot', 'filename' => '[A-Za-z0-9_-]+\.(?:gif|png|webp)']);
Route::get('/assets/hakoniwa-original/{filename}', AssetController::class)
    ->where('filename', '[A-Za-z0-9_-]+\.(?:gif|png|webp)');

Route::get('/manual/{section?}', ManualController::class)
    ->where('section', 'beginner|intermediate|advanced|trading-post|secretary|underground');
Route::get('/community-guidelines', CommunityGuidelinesController::class);

Route::prefix('api/v1/public')
    ->middleware(['throttle:60,1', PublicApiResponse::class])
    ->group(function (): void {
        Route::get('/announcements/latest', [AnnouncementController::class, 'latest']);
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
        Route::get('/worlds', [PublicApiController::class, 'worlds']);
        Route::get('/worlds/{world}/summary', [PublicApiController::class, 'summary']);
        Route::get('/worlds/{world}/rankings', [PublicApiController::class, 'rankings']);
        Route::get('/worlds/{world}/major-news', [PublicApiController::class, 'majorNews']);
        Route::get('/worlds/{world}/events', [PublicApiController::class, 'events']);
        Route::get('/worlds/{world}/map-spaces', [PublicApiController::class, 'mapSpaces']);
        Route::get('/nations/{nation}/events', [PublicApiController::class, 'nationEvents']);
        Route::get('/nations/{nation}', [PublicApiController::class, 'nation']);
        Route::get('/nations/{nation}/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}', [PublicApiController::class, 'chunk'])
            ->where(['chunkX' => '-?\d+', 'chunkY' => '-?\d+']);
    });

Route::prefix('api/v1/admin')
    ->middleware([PrivateApiResponse::class, RequireAnnouncementAdmin::class])
    ->group(function (): void {
        Route::get('/inquiries/latest', [AdminInquiryController::class, 'latest']);
        Route::get('/inquiries', [AdminInquiryController::class, 'index']);
        Route::get('/inquiries/{inquiryId}', [AdminInquiryController::class, 'show'])
            ->whereNumber('inquiryId');
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    });

Route::get('/api/v1/nations/{nation}/message-board', [MessageBoardController::class, 'show'])
    ->middleware(['throttle:60,1', PrivateApiResponse::class]);
Route::get('/api/v1/secretaries/{secretary}', [SecretaryController::class, 'publicShow'])
    ->middleware(['throttle:60,1', PrivateApiResponse::class]);

Route::prefix('api/v1')->middleware(['auth', PrivateApiResponse::class])->group(function (): void {
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/me/secretary', [SecretaryController::class, 'show']);
    Route::post('/me/secretary/name', [SecretaryController::class, 'name']);
    Route::patch('/me/secretary/name', [SecretaryController::class, 'rename']);
    Route::patch('/me/secretary/profile', [SecretaryController::class, 'updateProfile']);
    Route::post('/me/secretary/main-image', [SecretaryController::class, 'storeMainImage']);
    Route::patch('/me/secretary/main-image', [SecretaryController::class, 'updateMainImageMetadata']);
    Route::patch('/me/secretary/image-preferences', [SecretaryController::class, 'updateImagePreferences']);
    Route::prefix('/me/underground')->middleware('throttle:60,1')->group(function (): void {
        Route::get('/', [UndergroundIntroController::class, 'show']);
        Route::post('/entry', [UndergroundIntroController::class, 'enter']);
        Route::post('/story/advance', [UndergroundIntroController::class, 'advance']);
        Route::post('/tutorial', [UndergroundIntroController::class, 'tutorial']);
        Route::post('/shopkeeper/name', [UndergroundIntroController::class, 'nameShopkeeper']);
        Route::post('/scripted-loss', [UndergroundIntroController::class, 'scriptedLoss']);
        Route::post('/contract', [UndergroundIntroController::class, 'contract']);
        Route::post('/growth-path', [UndergroundIntroController::class, 'growthPath']);
        Route::get('/main', [UndergroundIntroController::class, 'main']);
        Route::post('/explore', [UndergroundIntroController::class, 'explore']);
        Route::post('/inn/rest', [UndergroundIntroController::class, 'restAtInn']);
        Route::post('/bank/transfer', [UndergroundIntroController::class, 'bankTransfer']);
        Route::get('/equipment/shop', [UndergroundEquipmentController::class, 'shop']);
        Route::post('/equipment/shop/purchase', [UndergroundEquipmentController::class, 'purchase']);
        Route::post('/equipment/items/{itemId}/sell', [UndergroundEquipmentController::class, 'sell'])
            ->whereNumber('itemId');
        Route::get('/equipment/vault', [UndergroundEquipmentController::class, 'vault']);
        Route::put('/equipment/equipped', [UndergroundEquipmentController::class, 'equip']);
        Route::delete('/equipment/equipped/{slot}', [UndergroundEquipmentController::class, 'unequip']);
        Route::post('/status/stp', [UndergroundIntroController::class, 'allocateStp']);
        Route::post('/skills/acquire', [UndergroundIntroController::class, 'acquireSkill']);
        Route::put('/skills/loadout', [UndergroundIntroController::class, 'updateActiveLoadout']);
        Route::get('/playtest', [UndergroundIntroController::class, 'playtestOptions']);
        Route::post('/playtest', [UndergroundIntroController::class, 'playtest']);
        Route::get('/battles', [UndergroundIntroController::class, 'battles']);
        Route::get('/battles/{battleRequestId}', [UndergroundIntroController::class, 'battle'])
            ->whereUuid('battleRequestId');
    });
    Route::get('/me/secretary/equipment/{slot}/options', [SecretaryController::class, 'equipmentOptions'])
        ->where('slot', '-?\d+');
    Route::put('/me/secretary/equipment/{slot}', [SecretaryController::class, 'updateEquipment'])
        ->where('slot', '-?\d+');
    Route::post('/me/secretary/items/{item}/sell', [SecretaryController::class, 'sellItem'])
        ->where('item', '-?\d+');
    Route::post('/inquiries', [InquiryController::class, 'store'])->middleware('throttle:3,1');
    Route::get('/worlds', [ApiController::class, 'worlds']);
    Route::get('/worlds/{world}/trading-post', [TradingPostController::class, 'index']);
    Route::get('/worlds/{world}/map-spaces', [ApiController::class, 'mapSpaces']);
    Route::get('/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}', [ApiController::class, 'chunk'])
        ->where(['chunkX' => '-?\d+', 'chunkY' => '-?\d+']);
    Route::post('/nations', [ApiController::class, 'createNation']);
    Route::get('/nations/{nation}', [ApiController::class, 'nation']);
    Route::post('/nations/{nation}/message-board', [MessageBoardController::class, 'storePublic']);
    Route::post('/nations/{nation}/message-board/secret', [MessageBoardController::class, 'storeSecret']);
    Route::patch('/nations/{nation}/profile', [NationProfileController::class, 'update']);
    Route::post('/nations/{nation}/abandon', [NationAbandonmentController::class, 'store']);
    Route::post('/nations/{nation}/dormancy', [NationDormancyController::class, 'store']);
    Route::get('/me/nation', [ApiController::class, 'myNation']);
    Route::get('/nations/{nation}/events', [PlayerEventController::class, 'index']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-definitions', [CommandQueueController::class, 'definitions']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'index']);
    Route::post('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'store']);
    Route::post('/nations/{nation}/map-spaces/{mapSpace}/command-queue/bulk', [CommandQueueController::class, 'bulk']);
    Route::delete('/nations/{nation}/map-spaces/{mapSpace}/command-queue/from', [CommandQueueController::class, 'cancelFrom']);
    Route::put('/nations/{nation}/map-spaces/{mapSpace}/command-queue/reorder', [CommandQueueController::class, 'reorder']);
    Route::patch('/nations/{nation}/map-spaces/{mapSpace}/command-queue/{item}', [CommandQueueController::class, 'update']);
    Route::delete('/nations/{nation}/map-spaces/{mapSpace}/command-queue/{item}', [CommandQueueController::class, 'cancel']);
    Route::get('/nations/{nation}/sale-policies', [SalePolicyController::class, 'index']);
    Route::put('/nations/{nation}/resources/{resourceDefinition}/sale-policy', [SalePolicyController::class, 'update']);
    Route::post('/nations/{nation}/trading-post/listings', [TradingPostController::class, 'store']);
    Route::post('/nations/{nation}/trading-post/listings/{auctionListing}/bids', [TradingPostController::class, 'bid']);
    Route::delete('/nations/{nation}/trading-post/listings/{auctionListing}', [TradingPostController::class, 'destroy']);
});

Route::view('/{path?}', 'app')->where('path', '.*');
