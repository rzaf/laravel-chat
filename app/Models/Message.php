<?php

namespace App\Models;

use App\Traits\HasMedia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes, HasMedia;

    protected $table = 'messages';

    protected $guarded = [];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class)->withTrashed();;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        $userId = auth()->id();
        return $this->belongsTo(Message::class, 'reply_to_id')
            ->withTrashed() // Keeps global soft-deletes so you can check if it was deleted for everyone
            ->when($userId, function ($query) use ($userId) {
                // Exclude parent messages deleted specifically for the current user
                $query->whereNotExists(function ($sub) use ($userId) {
                    $sub->select(\DB::raw(1))
                        ->from('message_user_deletions')
                        ->whereColumn('message_user_deletions.message_id', 'messages.id')
                        ->where('message_user_deletions.user_id', $userId);
                });
            });
    }

    public function forwardedFromMessage()
    {
        return $this->belongsTo(Message::class, 'forwarded_from_message_id')->withTrashed();
    }
}
