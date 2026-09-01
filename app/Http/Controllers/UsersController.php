<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserContacts;
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
        $query = User::whereLike('username', "%$req->search%");
        if ($notInContacts) {
            $contactUserIds = UserContacts::where('owner', $req->user()->id)
                ->pluck('user_id');
            $query = $query->whereNotIn('id', $contactUserIds);
        }
        return $query->paginate(6);
    }

    /**
     * search users
     * 
     * @response 200
     * @response 401
     */
    public function get(Request $req)
    {
        return User::where('username', $req->username)->firstOrFail();
    }
}