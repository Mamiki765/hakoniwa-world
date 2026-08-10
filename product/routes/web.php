<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\CommandQueueController;
use App\Http\Controllers\Api\MessageBoardController;
use App\Http\Controllers\Api\NationProfileController;
use App\Http\Controllers\Api\PlayerEventController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\SalePolicyController;
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
Route::get('/assets/hakoniwa-original/{filename}', AssetController::class)
    ->where('filename', '[A-Za-z0-9_-]+\.(?:gif|png|webp)');

Route::get('/manual/{section?}', ManualController::class)
    ->where('section', 'beginner|intermediate|advanced');
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
        Route::get('/worlds/{world}/events', [PublicApiController::class, 'events']);
        Route::get('/worlds/{world}/map-spaces', [PublicApiController::class, 'mapSpaces']);
        Route::get('/nations/{nation}', [PublicApiController::class, 'nation']);
        Route::get('/nations/{nation}/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}', [PublicApiController::class, 'chunk'])
            ->where(['chunkX' => '-?\d+', 'chunkY' => '-?\d+']);
    });

Route::prefix('api/v1/admin')
    ->middleware([PrivateApiResponse::class, RequireAnnouncementAdmin::class])
    ->group(function (): void {
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    });

Route::get('/api/v1/nations/{nation}/message-board', [MessageBoardController::class, 'show'])
    ->middleware(['throttle:60,1', PrivateApiResponse::class]);

Route::prefix('api/v1')->middleware(['auth', PrivateApiResponse::class])->group(function (): void {
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/worlds', [ApiController::class, 'worlds']);
    Route::get('/worlds/{world}/map-spaces', [ApiController::class, 'mapSpaces']);
    Route::get('/map-spaces/{mapSpace}/chunks/{chunkX}/{chunkY}', [ApiController::class, 'chunk'])
        ->where(['chunkX' => '-?\d+', 'chunkY' => '-?\d+']);
    Route::post('/nations', [ApiController::class, 'createNation']);
    Route::get('/nations/{nation}', [ApiController::class, 'nation']);
    Route::post('/nations/{nation}/message-board', [MessageBoardController::class, 'storePublic']);
    Route::post('/nations/{nation}/message-board/secret', [MessageBoardController::class, 'storeSecret']);
    Route::patch('/nations/{nation}/profile', [NationProfileController::class, 'update']);
    Route::get('/me/nation', [ApiController::class, 'myNation']);
    Route::get('/nations/{nation}/events', [PlayerEventController::class, 'index']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-definitions', [CommandQueueController::class, 'definitions']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'index']);
    Route::post('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'store']);
    Route::put('/nations/{nation}/map-spaces/{mapSpace}/command-queue/reorder', [CommandQueueController::class, 'reorder']);
    Route::patch('/nations/{nation}/map-spaces/{mapSpace}/command-queue/{item}', [CommandQueueController::class, 'update']);
    Route::delete('/nations/{nation}/map-spaces/{mapSpace}/command-queue/{item}', [CommandQueueController::class, 'cancel']);
    Route::get('/nations/{nation}/sale-policies', [SalePolicyController::class, 'index']);
    Route::put('/nations/{nation}/resources/{resourceDefinition}/sale-policy', [SalePolicyController::class, 'update']);
});

Route::view('/{path?}', 'app')->where('path', '.*');
