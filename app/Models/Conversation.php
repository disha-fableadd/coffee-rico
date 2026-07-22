<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_id',
        'phone_number',
        'contact_name',
        'user_id',
        'status',
        'last_message_at',
        'last_message_preview',
        'is_unread',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'is_unread' => 'boolean'
    ];

    /**
     * Get the user that owns the conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the messages for the conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('whatsapp_timestamp', 'asc');
    }

    /**
     * Get the latest message for the conversation.
     */
    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('whatsapp_timestamp');
    }

    /**
     * Get unread messages count.
     */
    public function getUnreadCountAttribute(): int
    {
        return $this->messages()->where('direction', 'inbound')->where('is_read', false)->count();
    }

    /**
     * Mark conversation as read.
     */
    public function markAsRead(): void
    {
        $this->update(['is_unread' => false]);
        $this->messages()->where('direction', 'inbound')->update(['is_read' => true]);
    }

    /**
     * Get the contact associated with this conversation by phone number.
     */
    public function contact()
    {
        return $this->hasOne(Contact::class, 'phone', 'phone_number')
            ->where('userId', $this->user_id);
    }
}
