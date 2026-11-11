<?php

use App\Http\Controllers\OAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMemberController;
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
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/user', [AuthController::class, 'update']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
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
    // direct chats — addressed by the other user's username
    Route::get('/direct/{username}', [MessageController::class, 'getDirectMessages']);
    Route::post('/direct/{username}', [MessageController::class, 'storeDirectMessage']);

    // groups & channels — addressed by the chat slug
    Route::get('/chats/{slug}', [MessageController::class, 'getChatMessages']);
    Route::post('/chats/{slug}', [MessageController::class, 'storeChatMessage']);
    Route::post('/bulkForward', [MessageController::class, 'bulkForward']);

    // any chat type
    Route::put('/{id}', [MessageController::class, 'update']);
    Route::delete('/{id}', [MessageController::class, 'destroy']);
    Route::put('/{id}/read', [MessageController::class, 'markAsReadMessage']);

    Route::post('/{id}/reactions', [MessageController::class, 'addReaction']);
    Route::delete('/{id}/reactions', [MessageController::class, 'removeReaction']);
    Route::get('/{id}/reactions/users', [MessageController::class, 'reactionUsers']);
});

Route::group(['prefix' => 'chats', 'middleware' => ['auth:sanctum']], function () {
    Route::get('/', [ChatController::class, 'index']);
    Route::post('/', [ChatController::class, 'store']);                       // create group / channel

    // ---- fixed paths MUST be declared before the `/{slug}` wildcard ----
    Route::get('/public', [ChatController::class, 'discover']);               // search public groups / channels
    Route::get('/public/{slug}', [ChatController::class, 'preview']);         // info for a public chat (not joined yet)
    Route::post('/join/{inviteCode}', [ChatController::class, 'joinByInvite']);

    Route::get('/{slug}', [ChatController::class, 'getChat']);
    Route::delete('/{id}', [ChatController::class, 'destroy']);               // delete / leave
    Route::delete('/{id}/clear', [ChatController::class, 'clearHistory']);

    Route::put('/{chatId}', [ChatController::class, 'update'])->whereNumber('chatId');          // edit group / channel
    Route::post('/{chatId}/join', [ChatController::class, 'join'])->whereNumber('chatId');      // join a public chat
    Route::post('/{chatId}/invite-link', [ChatController::class, 'regenerateInvite'])->whereNumber('chatId');

    // Media from direct chats (username), groups, and channels (slug)
    Route::get('/{slug}/media', [ChatController::class, 'getChatMedia']);

    // members of a group / channel
    Route::get('/{chatId}/members', [ChatMemberController::class, 'index'])->whereNumber('chatId');
    Route::get('/{chatId}/membersInTheContact', [ChatMemberController::class, 'membersInTheContact'])->whereNumber('chatId');
    Route::post('/{chatId}/members', [ChatMemberController::class, 'store'])->whereNumber('chatId');
    Route::put('/{chatId}/members/{userId}', [ChatMemberController::class, 'update'])->whereNumber(['chatId', 'userId']);
    Route::delete('/{chatId}/members/{userId}', [ChatMemberController::class, 'destroy'])->whereNumber(['chatId', 'userId']);

    // per-user settings (any chat type)
    Route::patch('/{chatId}/pin', [ChatController::class, 'togglePin']);
    Route::patch('/{chatId}/mute', [ChatController::class, 'setMute']);
    Route::patch('/{chatId}/archive', [ChatController::class, 'toggleArchive']);
    Route::put('/{chatId}/draft', [ChatController::class, 'updateDraft']);
    Route::put('/{chatId}/read', [ChatController::class, 'markAsReadChat']);

    // chat profile images
    Route::post('/{chatId}/profiles/upload', [ChatController::class, 'uploadProfile']);
    Route::delete('/{chatId}/profiles/{id}', [ChatController::class, 'deleteProfile']);
    Route::get('/{slug}/profiles', [ChatController::class, 'getProfiles']);
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
