<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\CommandQueueController;
use App\Http\Controllers\Api\SalePolicyController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/account/link/{provider}/redirect', [OAuthController::class, 'link'])->middleware('auth')->name('oauth.link');
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
Route::post('/logout', [OAuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/assets/hakoniwa-tiles/{filename}', AssetController::class)
    ->where('filename', '[A-Za-z0-9_-]+\.(?:gif|png|webp)');
Route::get('/assets/hakoniwa-original/{filename}', AssetController::class)
    ->where('filename', '[A-Za-z0-9_-]+\.(?:gif|png|webp)');

Route::prefix('api/v1')->middleware('auth')->group(function (): void {
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/worlds', [ApiController::class, 'worlds']);
    Route::get('/worlds/{world}/map-spaces', [ApiController::class, 'mapSpaces']);
    Route::get('/map-spaces/{mapSpace}/chunks/{chunkQ}/{chunkR}', [ApiController::class, 'chunk'])
        ->where(['chunkQ' => '-?\d+', 'chunkR' => '-?\d+']);
    Route::post('/nations', [ApiController::class, 'createNation']);
    Route::get('/nations/{nation}', [ApiController::class, 'nation']);
    Route::get('/me/nation', [ApiController::class, 'myNation']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-definitions', [CommandQueueController::class, 'definitions']);
    Route::get('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'index']);
    Route::post('/nations/{nation}/map-spaces/{mapSpace}/command-queue', [CommandQueueController::class, 'store']);
    Route::put('/nations/{nation}/map-spaces/{mapSpace}/command-queue/reorder', [CommandQueueController::class, 'reorder']);
    Route::delete('/nations/{nation}/map-spaces/{mapSpace}/command-queue/{item}', [CommandQueueController::class, 'cancel']);
    Route::get('/nations/{nation}/sale-policies', [SalePolicyController::class, 'index']);
    Route::put('/nations/{nation}/resources/{resourceDefinition}/sale-policy', [SalePolicyController::class, 'update']);
});

Route::view('/{path?}', 'app')->where('path', '.*');
