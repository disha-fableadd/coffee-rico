<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'type',
        'content',
        'media_url',
        'media_type',
        'media_filename',
        'metadata',
        'status',
        'whatsapp_timestamp',
        'is_read'
    ];

    protected $casts = [
        'metadata' => 'array',
        'whatsapp_timestamp' => 'datetime',
        'is_read' => 'boolean'
    ];

    /**
     * Get the conversation that owns the message.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Check if message is within 24 hours window for free-form messaging.
     */
    public function isWithin24Hours(): bool
    {
        return $this->whatsapp_timestamp->diffInHours(now()) <= 24;
    }

    /**
     * Get formatted timestamp.
     */
    public function getFormattedTimestampAttribute(): string
    {
        return $this->whatsapp_timestamp->format('Y-m-d H:i:s');
    }
}
