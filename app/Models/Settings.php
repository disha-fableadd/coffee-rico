<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    use HasFactory;

    protected $fillable = [
        'fb_app_id',
        'fb_app_secret',
        'phoneNumberId',
        'waba_id',
        'access_token',
        'webhook_url',
        'isActive',
        'expires_in',
        'expiry_date',
        'batch_size',
        'batch_delay_seconds',
        'enable_batch_processing',
    ];

    protected $casts = [
        'isActive' => 'boolean',
        'expires_in' => 'integer',
        'expiry_date' => 'datetime',
        'batch_size' => 'integer',
        'batch_delay_seconds' => 'integer',
        'enable_batch_processing' => 'boolean',
    ];
}
