<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_private' => 'boolean',
        'is_readonly' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_members', 'chat_id', 'user_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'chat_id');
    }

    public function userSettings(): HasMany
    {
        return $this->hasMany(ChatUserSetting::class);
    }
}
