<?php

use App\Http\Controllers\OAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\UsersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/auth/redirect/{provider}', [OAuthController::class, 'redirect']);
Route::post('/auth/callback/{provider}', [OAuthController::class, 'callback']);

Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register']);
Route::middleware('auth:sanctum')->group(function() {
    Route::put('/user',[AuthController::class,'update']);
    Route::put('/user/password',[AuthController::class,'changePassword']);
});

Route::group(['prefix' => 'users', 'middleware' => ['auth:sanctum']], function () {
    Route::get('/search/{search}', [UsersController::class, 'search']);
    Route::get('/{username}', [UsersController::class, 'get']);
    Route::get('/{username}/profiles/', [UsersController::class, 'getProfiles']);
    Route::post('profiles/upload', [UsersController::class, 'uploadProfile']);
    Route::delete('profiles/{id}', [UsersController::class, 'deleteProfile']);
});

Route::group(['prefix' => 'contacts', 'middleware' => ['auth:sanctum']], function () {
    Route::get('/', [ContactController::class, 'index']);
    Route::post('/users/{username}', [ContactController::class, 'save']);
    Route::delete('/users/{username}', [ContactController::class, 'remove']);
});

Route::group(['prefix' => 'messages', 'middleware' => ['auth:sanctum']], function () {
    Route::get('/direct/{username}', [MessageController::class, 'getDirectMessages']);
    Route::post('/direct/{username}', [MessageController::class, 'storeDirectMessage']);

    Route::put('/{id}', [MessageController::class, 'update']);
    Route::delete('/{id}', [MessageController::class, 'destroy']);
    Route::put('/{id}/read', [MessageController::class, 'markAsReadMessage']);
});

Route::group(['prefix' => 'chats', 'middleware' => ['auth:sanctum']], function () {
    Route::get('/', [ChatController::class, 'index']);
    Route::get('/{slug}', [ChatController::class, 'getChat']);
    Route::delete('/{id}', [ChatController::class, 'destroy']);
    Route::delete('/{id}/clear', [ChatController::class, 'clearHistory']);

    Route::patch('/{chatId}/pin', [ChatController::class, 'togglePin']);
    Route::patch('/{chatId}/mute', [ChatController::class, 'toggleMute']);
    Route::patch('/{chatId}/archive', [ChatController::class, 'toggleArchive']);
    Route::put('/{chatId}/draft', [ChatController::class, 'updateDraft']);
    Route::put('/{chatId}/read', [ChatController::class, 'markAsReadChat']);
});


// Route::group(['prefix' => 'medias', 'middleware' => ['auth:sanctum']], function () {
//     Route::get('/{id}', [MediaController::class, 'get'])->name('media.get');
// });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/medias/{id}/url', [MediaController::class, 'generateUrl'])
        ->name('media.url');
});

Route::group(['prefix' => 'medias'], function () {
    // Public media
    Route::get('/public/{id}', [MediaController::class, 'public'])->name('media.public');

    // Private media
    Route::get('/private/{id}', [MediaController::class, 'private'])->middleware('signed')->name('media.private');
});

