<?php

namespace App\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\User;

class OAuthController extends Controller
{
    public function redirect($provider)
    {
        $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
        return response([
            'url' => $url
        ]);
    }

    public function callback($provider)
    {
        try {
            $oauthUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            abort(400, 'OAuth failed: ' . $e->getMessage());
        }

        $user = User::where('email', $oauthUser->getEmail())->first();

        if (!$user) {
            $username = Str::lower(Str::slug($oauthUser->getNickname()?? $oauthUser->getName() ?? 'user'));
            if(!$username){
                $username = 'user';
            }
            $original = $username;
            $i = 1;
            while(User::where('username',$username)->exists()){
                $username = $original . $i;
                $i++;
            }

            $user = User::create([
                'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? 'No Name',
                'email' => $oauthUser->getEmail(),
                // 'username' => $oauthUser->getNickname() ?? Str::slug($oauthUser->getName()) ?? Str::random(8),
                'username;' => $username,
                'password' => bcrypt(Str::random(16)), // Random password
            ]);
        }

        return response([
            'token' => $user->createToken('access token')->plainTextToken,
            'data' => $user,
        ]);
    }
}
