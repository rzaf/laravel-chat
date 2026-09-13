<?php

namespace App\Http\Controllers;

use App\Http\Resources\MediaResource;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * @group Medias
 * 
 */
class MediaController extends Controller
{


    /**
     * Public media.
     * 
     * @response 200 {}
     */
    public function public(Request $request, int $id)
    {
        $media = Media::findOrFail($id);
        // enforce that this media is actually public.
        if ($media->model_type === Message::class) {
            $isPublic = Chat::query()
                ->where('is_private', false)
                ->whereHas('messages', function ($query) use ($media) {
                    $query->where('id', $media->model_id);
                })
                ->exists();
            if (!$isPublic)
                abort(404, 'Media not found.');
        }
        if ($media->model_type === User::class) {
            // todo: add private user profile setting
        }

        $mediaPath = $request->boolean('thumbnail', false) ? $media->thumb_path : $media->path;
        return Storage::disk($media->disk)->response($mediaPath);
    }

    /**
     * Private chat media.
     * 
     * @response 200 {}
     */
    public function private(Request $request, int $id)
    {
        $media = Media::findOrFail($id);
        // return Storage::disk($media->disk)->response($media->path);
        $mediaPath = $request->boolean('thumbnail', false) ? $media->thumb_path : $media->path;

        $path = Storage::disk($media->disk)->path($mediaPath);        
        // This helper handles 206 Partial Content and Range headers out of the box
        return response()->file($path, [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $media->mime_type,
        ]);
    }

    /**
     * Generate a temporary URL for private media.
     * @authenticated
     * 
     * @response 200 {}
     */
    public function generateUrl(Request $request, $id)
    {
        $media = Media::findOrFail($id);
        if ($media->model_type == Message::class) {
            $message = Message::findOrFail($media->model_id);
            $user = $request->user();
            if (
                !ChatMembers::where('chat_id', $message->chat_id)
                    ->where('user_id', $user->id)
                    ->exists()
            ) {
                abort(403, 'Not authorized!');
            }
        }
        // return URL::temporarySignedRoute(
        //     'media.private',
        //     now()->addMinutes(5),
        //     ['id' => $media->id]
        // );
        return new MediaResource($media);
    }


    // /**
    //  * retrieve file 
    //  * 
    //  * @response 200 {}
    //  */
    // public function get(Request $req)
    // {
    //     $currentUser = $req->user();
    //     $media = Media::where('id', $req->id)->firstOrFail();
    //     if ($media->model_type == Message::class) {
    //         $message = Message::where('id', $media->model_id)->firstOrFail();
    //         if (!ChatMembers::where('chat_id', $message->chat_id)->where('user_id', $currentUser->id)->exists()) {
    //             abort(403, "Not authorized!");
    //         }
    //     }

    //     return Storage::disk($media->disk)->response($media->path);
    // }
}
