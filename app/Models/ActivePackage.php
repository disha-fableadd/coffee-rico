<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'userId',
        'packageId',
        'startDate',
        'endDate',
        'day',
        'status',
        'usedMsgCount',
        'lastMonthlyReset',
        'monthlyUsedMsgCount',
        'monthlyUsedTemplateCount',
        'monthlyUsedContactCount',
    ];

    protected $casts = [
        'startDate' => 'datetime',
        'endDate' => 'datetime',
        'lastMonthlyReset' => 'datetime',
    ];

    /**
     * Get the user that owns the active package.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Get the package for the active package.
     */
    public function package()
    {
        return $this->belongsTo(Package::class, 'packageId');
    }

    /**
     * Check if package needs manual reactivation after 1 year
     */
    public function needsManualReactivation()
    {
        $now = now();
        return $now->gt($this->endDate) && $this->status == 0;
    }

    /**
     * Check if package is in monthly renewal period (within 1 year)
     */
    public function isInMonthlyRenewalPeriod()
    {
        $now = now();
        return $now->lte($this->endDate) && $this->status == 1;
    }

    /**
     * Get days remaining until package expires
     */
    public function getDaysUntilExpiry()
    {
        $now = now();
        if ($now->gt($this->endDate)) {
            return 0; // Already expired
        }
        return $now->diffInDays($this->endDate);
    }
}
