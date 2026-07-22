<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'userId',
        'status',
    ];

    /**
     * Get the user that owns the group.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Get the contacts for the group.
     */
    public function contacts()
    {
        return $this->belongsToMany(Contact::class);
    }

    /**
     * Get contacts by groupIds array
     */
    public function getContactsAttribute()
    {
        // Try both integer and string versions of the group ID
        $contacts = Contact::whereJsonContains('groupIds', $this->id)
            ->orWhereJsonContains('groupIds', (string)$this->id)
            ->get();
        
        return $contacts;
    }
}
