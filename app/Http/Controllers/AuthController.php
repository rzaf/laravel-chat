<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


/**
 * @group Auth
 *
 */
class AuthController extends Controller
{
    private function usernameRules()
    {
        return [
            'required',
            'string',
            'min:3',
            'max:30',
            'regex:/^[a-z][a-z0-9_-]*$/',
            'not_regex:/@/',
            'unique:users,username'
        ];
    }

    /**
     * login account to get new token.
     *
     * @bodyParam login string required username or email Example: test
     * @bodyParam password string required Example: 1234
     * 
     * @response 200
     * @response 401
     */
    public function login(Request $req)
    {
        $validated = $req->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);
        $login = strtolower(trim($validated['login']));
        $user = User::where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (!$user || !\Hash::check($validated['password'], $user->password)) {
            abort(401, "invalid username or password");
        }
        Auth::login($user);

        // $ok = Auth::attempt($validated);
        // abort_if(!$ok, 401, "invalid username or password");
        // $user = Auth::user();
        // DB::table('personal_access_tokens')->where(['tokenable_id' => $user->id])->delete();
        return response([
            'token' => $user->createToken('access token')->plainTextToken,
            'data' => $user,
        ], 200);
    }


    /**
     * new user register
     *
     * @bodyParam name string required Example: test
     * @bodyParam username string required Example: test
     * @bodyParam email string required Example: test@gmail.com
     * @bodyParam password string required Example: 1234
     * @bodyParam password_confirmation string required Example: 1234
     * 
     * 
     * @response 201
     * @response 401
     */
    public function register(Request $req)
    {
        $validated = $req->validate([
            'name' => ['required','string','max:100'],
            'username' => $this->usernameRules(),
            'email' => ['required','string','email','max:255','unique:users,email'],
            'password' => ['required','string','min:8','confirmed'],
        ]);
        // $validated = $req->validate([
        //     'name' => 'required|string',
        //     'username' => 'required|string',
        //     'email' => 'required|string|email',
        //     'password' => 'required|string|confirmed',
        //     'password_confirmation' => 'required|string',
        // ]);
        $validated['username'] = strtolower($validated['username']);
        try {
            $user = User::create($validated);
        } catch (UniqueConstraintViolationException $e) {
            $column = explode('.', $e->errorInfo[2])[1];
            abort(400, "duplicate $column");
        }

        return response([
            'message' => 'user created',
            'data' => $user,
            'token' => $user->createToken('access token')->plainTextToken,
        ], 201);
    }



    /**
     * Update profile
     * 
     * @response 200
     */
    public function update(Request $req)
    {
        $user = Auth::user();
        $validated = $req->validate([
            'name' => ['sometimes','string','max:100'],
            'username' => ['sometimes','string','min:3','max:30','regex:/^[a-z][a-z0-9_-]*$/','not_regex:/@/','unique:users,username,' . $user->id],
            'email' => ['sometimes','email','unique:users,email,' . $user->id],
        ]);

        if(isset($validated['username'])){
            $validated['username'] = strtolower($validated['username']);
        }
        $user->update($validated);

        return response([
            'message'=>'profile updated',
            'data'=>$user
        ]);
    }



    /**
     * Change password
     * 
     * @response 200
     */
    public function changePassword(Request $req)
    {
        $validated = $req->validate([
            'current_password'=>'required|string',
            'password'=>['required','string','min:8','confirmed']
        ]);

        $user = Auth::user();
        if(!\Hash::check(
            $validated['current_password'],
            $user->password
        )){
            abort(400,"current password incorrect");
        }
        $user->update([
            'password'=>Hash::make($validated['password'])
        ]);

        return response([
            'message'=>'password changed'
        ]);
    }

}
