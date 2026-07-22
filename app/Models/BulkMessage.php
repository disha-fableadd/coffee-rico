<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BulkMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'userId',
        'name',
        'templateId',
        'variables',
        'headerVariables',
        'contactIds',
        'contactId',
        'scheduleAt',
        'status',
        'sendingDate',
        'sentStatus',
    ];

    protected $casts = [
        'variables' => 'array',
        'headerVariables' => 'array',
        'contactIds' => 'array',
        'sentStatus' => 'array',
        'scheduleAt' => 'datetime',
        'sendingDate' => 'datetime',
    ];

    /**
     * Get the user that owns the bulk message.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    /**
     * Get the template for the bulk message.
     */
    public function template()
    {
        return $this->belongsTo(Template::class, 'templateId');
    }

    /**
     * Get the contact for the bulk message.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contactId');
    }

    /**
     * Get the delivery statuses for the bulk message.
     */
    public function deliveryStatuses()
    {
        return $this->hasMany(MessageDeliveryStatus::class);
    }

    /**
     * Get delivery status for a specific contact
     */
    public function getDeliveryStatusForContact($contactId)
    {
        return $this->deliveryStatuses()->where('contact_id', $contactId)->first();
    }

    /**
     * Get delivery status summary
     */
    public function getDeliveryStatusSummary()
    {
        $statuses = $this->deliveryStatuses()->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'pending' => $statuses['pending'] ?? 0,
            'processing' => $statuses['processing'] ?? 0,
            'sent' => $statuses['sent'] ?? 0,
            'delivered' => $statuses['delivered'] ?? 0,
            'read' => $statuses['read'] ?? 0,
            'failed' => $statuses['failed'] ?? 0,
            'total' => array_sum($statuses)
        ];
    }
}
