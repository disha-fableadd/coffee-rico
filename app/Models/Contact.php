<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'tags',
        'groupIds',
        'status',
        'userId',
    ];

    protected $casts = [
        'tags' => 'array',
        'groupIds' => 'array',
    ];

    /**
     * Get the user that owns the contact.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Get the groups that the contact belongs to.
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'contact_group', 'contact_id', 'group_id');
    }

    /**
     * Get groups by groupIds array
     */
    public function getGroupsAttribute()
    {
        if (empty($this->groupIds)) {
            return collect([]);
        }
        
        return Group::whereIn('id', $this->groupIds)->get();
    }

}
