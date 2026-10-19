<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasMedia;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
    ];

    protected $hidden = [
        // 'id',
        'password',
        'remember_token',
        'email',
        'email_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(UserContacts::class, 'owner');
    }

    public function chatsList(): HasMany
    {
        return $this->hasMany(ChatMembers::class, 'user_id');
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_members', 'user_id', 'chat_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function unseenMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id')
            ->where('read_at', null)
            ->whereHas('chat.members', function ($query) {
                $query->where('users.id', $this->id);
            });
    }


    public function profilePhoto()
    {
        // return $this->morphMany(Media::class, 'model');
        return $this->morphOne(Media::class, 'model')
            ->where('collection', 'profiles')
            ->latestOfMany();
    }

    public function chatSettings(): HasMany
    {
        return $this->hasMany(ChatUserSetting::class);
    }
}
