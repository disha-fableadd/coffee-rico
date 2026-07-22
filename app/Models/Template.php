<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'content',
        'type',
        'variables',
        'components',
        'language',
        'category',
        'status',
        'userId',
    ];

    protected $casts = [
        'variables' => 'array',
        'components' => 'array',
    ];

    /**
     * Get the user that owns the template.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Get the bulk messages for the template.
     */
    public function bulkMessages()
    {
        return $this->hasMany(BulkMessage::class, 'templateId');
    }

    /**
     * Get the isCustom attribute.
     */
    public function getIsCustomAttribute()
    {
        return $this->type === 'custom';
    }

    /**
     * Get the isRequest attribute.
     */
    public function getIsRequestAttribute()
    {
        return $this->type === 'request';
    }
}
