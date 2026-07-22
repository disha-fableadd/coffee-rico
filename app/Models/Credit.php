<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Credit extends Model
{
    use HasFactory;

    protected $fillable = [
        'userId',
        'amount',
        'type',
        'description',
        'referenceId',
    ];

    /**
     * Get the user that owns the credit.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
