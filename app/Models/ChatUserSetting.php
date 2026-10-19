<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUserSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_muted' => 'boolean',
        'is_archived' => 'boolean',
        'muted_until' => 'datetime',
        'last_read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}