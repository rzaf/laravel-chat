<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageUserDelete extends Model
{
    protected $guarded = [];
    protected $table = 'message_user_deletions';

    protected $casts = [
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}