<?php

namespace App\Http\Controllers;

use App\Models\ChatMembers;
use App\Models\Media;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @authenticated
 * @group Medias
 *
 * 
 */
class MediaController extends Controller
{


    /**
     * retrieve file 
     * 
     * @response 200 {}
     */
    public function get(Request $req)
    {
        $currentUser = $req->user();
        $media = Media::where('id', $req->id)->firstOrFail();
        if ($media->model_type == Message::class) {
            $message = Message::where('id', $media->model_id)->firstOrFail();
            if (!ChatMembers::where('chat_id', $message->chat_id)->where('user_id', $currentUser->id)->exists()) {
                abort(403, "Not authorized!");
            }
        }

        return Storage::disk($media->disk)->response($media->path);
    }
}
