<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageDeliveryStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'bulk_message_id',
        'contact_id',
        'status',
        'whatsapp_message_id',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the bulk message that owns the delivery status.
     */
    public function bulkMessage()
    {
        return $this->belongsTo(BulkMessage::class);
    }

    /**
     * Get the contact that owns the delivery status.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by bulk message
     */
    public function scopeByBulkMessage($query, $bulkMessageId)
    {
        return $query->where('bulk_message_id', $bulkMessageId);
    }

    /**
     * Update status with timestamp
     */
    public function updateStatus($status, $metadata = null)
    {
        $updateData = ['status' => $status];

        switch ($status) {
            case 'sent':
                $updateData['sent_at'] = now();
                break;
            case 'delivered':
                $updateData['delivered_at'] = now();
                break;
            case 'read':
                $updateData['read_at'] = now();
                break;
            case 'failed':
                $updateData['failed_at'] = now();
                break;
        }

        if ($metadata) {
            // Extract whatsapp_message_id from metadata if present
            if (isset($metadata['whatsapp_message_id']) && !empty($metadata['whatsapp_message_id'])) {
                $updateData['whatsapp_message_id'] = $metadata['whatsapp_message_id'];
            }
            
            // Extract error_message from metadata if present
            if (isset($metadata['error']) && !empty($metadata['error'])) {
                $updateData['error_message'] = is_array($metadata['error']) ? json_encode($metadata['error']) : $metadata['error'];
            }
            
            $updateData['metadata'] = $metadata;
        }

        $this->update($updateData);
    }
}
