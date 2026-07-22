<?php

namespace App\Models;

use App\Support\UploadPath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'filename',
        'path',
        'url',
        'mime_type',
        'size',
        'extension',
        'description',
        'alt_text',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * Get the full URL for the media file (following profile image pattern).
     */
    public function getFullUrlAttribute(): string
    {
        return UploadPath::url($this->url, 'images/default-avatar.svg');
    }

    /**
     * Get the file size in human readable format.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if the media file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Get the media URL attribute (same as profile image URL pattern).
     */
    public function getMediaUrlAttribute(): string
    {
        return $this->getFullUrlAttribute();
    }

    /**
     * Delete the model and its associated file (following profile image pattern).
     */
    public function delete()
    {
        UploadPath::delete($this->path);

        return parent::delete();
    }
}
