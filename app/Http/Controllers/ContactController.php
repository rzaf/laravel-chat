<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserContacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Validator;

/**
 * @authenticated
 * @group User Contacts
 *
 */
class ContactController extends Controller
{

    /**
     * get all contacts
     * 
     * 
     * @response 200
     * @response 401
     */
    public function index(Request $req)
    {
        $contacts = $req->user()->contacts()
            ->with([
                'user',
                'user.profilePhoto'
            ])
            ->get();
        return ContactResource::collection($contacts);
    }

    /**
     * create or update existing contacts
     * 
     * 
     * @response 200
     * @response 401
     * @response 404
     */
    public function save(Request $req, string $username)
    {
        abort_if($username == $req->user()->username, 400, 'cant add yourself to contact');
        $validated = $req->validate([
            'name' => 'nullable|string',
        ]);
        $user = User::firstWhere(['username' => $username]);

        throw_if($user == null, ValidationException::withMessages([
            'username' => 'username not found',
        ]));
        $contact = UserContacts::updateOrCreate([
            'owner' => $req->user()->id,
            'user_id' => $user->id,
        ], [
            'name' => $validated['name'] ?? null,
            'owner' => $req->user()->id,
            'user_id' => $user->id,
        ]);
        $contact->load(['user','user.profilePhoto']);
        return response()->json([
            'data' => new ContactResource($contact),
            'message' => 'contact saved',
        ], 200);
    }

    /**
     * remove specified contacts
     * 
     * 
     * @response 200
     * @response 401
     * @response 404
     */
    public function remove(Request $req, string $username)
    {
        $user = User::firstWhere(['username' => $username]);
        abort_if($user == null, 404, 'user not found');
        $ok = $req->user()->contacts()->where([
            'user_id' => $user->id,
        ])->delete();
        abort_if(!$ok, 400, 'user is not in your contact');
        return response()->json([
            'message' => 'user deleted successfully',
        ], 200);
    }
}
