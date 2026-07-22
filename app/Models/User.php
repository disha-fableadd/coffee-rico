<?php

namespace App\Models;

use App\Support\UploadPath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'profileImage',
        'number',
        'status',
        'bio',
        'companyName',
        'companyLogo',
        'companyAddress',
        'companyEmail',
        'companyMobile',
        'resetPasswordToken',
        'resetPasswordExpires',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'resetPasswordToken',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'resetPasswordExpires' => 'datetime',
    ];

    /**
     * Get the contacts for the user.
     */
    public function contacts()
    {
        return $this->hasMany(Contact::class, 'userId');
    }

    /**
     * Get the groups for the user.
     */
    public function groups()
    {
        return $this->hasMany(Group::class, 'userId');
    }

    /**
     * Get the bulk messages for the user.
     */
    public function bulkMessages()
    {
        return $this->hasMany(BulkMessage::class, 'userId');
    }

    /**
     * Get the active package for the user.
     */
    public function activePackage()
    {
        return $this->hasOne(ActivePackage::class, 'userId')->where('status', 1);
    }

    /**
     * Get the profile image URL with domain from APP_URL
     */
    public function getProfileImageUrlAttribute()
    {
        return UploadPath::url($this->profileImage, 'images/default-avatar.svg');
    }

    /**
     * Get the company logo URL with domain from APP_URL
     */
    public function getCompanyLogoUrlAttribute()
    {
        return UploadPath::url($this->companyLogo, 'images/default-company-logo.svg');
    }
}
