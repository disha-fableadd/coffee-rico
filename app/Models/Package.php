<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'packageName',
        'packageDesc',
        'day',
        'msgCount',
        'templateCount',
        'contactCount',
    ];

    /**
     * Get the active packages for the package.
     */
    public function activePackages()
    {
        return $this->hasMany(ActivePackage::class, 'packageId');
    }
}
