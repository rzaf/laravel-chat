<?php

namespace App\Http\Controllers;

use App\Http\Resources\MediaResource;
use App\Http\Resources\UserResource;
use App\Models\Media;
use App\Models\User;
use App\Models\UserContacts;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @authenticated
 * @group Users
 *
 */
class UsersController extends Controller
{

    /**
     * search users
     * 
     * @queryParam notInContacts boolean Specify that searched users should not be in the current user's contacts. Example: true 
     * @queryParam page integer  Example: 1
     * 
     * @response 200
     * @response 401
     */
    public function search(Request $req)
    {
        $notInContacts = $req->boolean('notInContacts', false);
        $query = User::whereLike('username', "%$req->search%")->with('profilePhoto');
        if ($notInContacts) {
            $contactUserIds = UserContacts::where('owner', $req->user()->id)
                ->pluck('user_id');
            $query = $query->whereNotIn('id', $contactUserIds);
        }
        // return $query->paginate(6);
        return UserResource::collection($query->simplePaginate(6));
    }

    /**
     * get user
     * 
     * @urlParam username string Example: test
     * 
     * @response 200
     * @response 401
     */
    public function get(Request $req)
    {
        return new UserResource(User::where('username', $req->username)->with([
            'profilePhoto',
            'media'
        ])->firstOrFail());
    }

    /**
     * get user profiles
     * 
     * @urlParam username string Example: test
     * 
     * @response 200
     * @response 401
     */
    public function getProfiles(Request $req)
    {
        $user = User::where('username', $req->username)->firstOrFail();
        return MediaResource::collection($user->media);
    }


    public static $mimes = [
        'jpg',
        'jpeg',
        'png',
    ];

    /**
     * upload a new profile photo
     * 
     * @bodyParam photo file optional First file attachment.
     * 
     * @response 200 {}
     */
    public function uploadProfile(Request $req, MediaService $mediaService)
    {
        $currentUser = $req->user();
        $validated = $req->validate([
            'photo' => 'required|file|max:102400|mimes:' . implode(',', static::$mimes),
        ]);

        try {
            DB::beginTransaction();
            $file = $req->file('photo');
            $mediaService->upload(
                model: $currentUser,
                file: $file,
                collection: 'profiles',
                disk: 'private' // Protected access
            );
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            abort(500, $th->getMessage());
        }
        $currentUser->load(['media']);

        return $currentUser;
    }


    /**
     * delete a profile photo
     * 
     * @response 200 {}
     */
    public function deleteProfile(Request $req, MediaService $mediaService)
    {
        $currentUser = $req->user();
        $mediaId = $req->id;
        $ok = Media::where('id', $mediaId)->where('model_id', $currentUser->id)->delete();
        if (!$ok) {
            return response()->json([
                'data' => null,
                'message' => 'failed to find profile!',
            ], 400);
        }

        $currentUser->load(['media']);

        return $currentUser;
    }
    
}