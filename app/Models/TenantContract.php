<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TenantContract extends Model
{
    use HasUuids;

    protected $table = 'tenants_contracts';

    protected $fillable = [
        'user_id',
        'apartment_id',
        'path',
        'type',
        'status',
        'rejection_reason',
        'move_in_date',
        'lease_duration',
        'occupants',
        'message',
        'file_data',
        'mime_type',
        'file_size',
    ];

    protected $appends = ['file_url', 'file_data_base64'];

    /** Binary PDF bytes must not be JSON-encoded directly (causes UTF-8 errors). */
    protected $hidden = ['file_data'];

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }
        
        // Use configurable asset URL or fall back to Storage URL
        $assetUrl = config('app.asset_url') ?? config('app.url');
        
        // If using S3 or external storage, return the full URL
        if (config('filesystems.default') !== 'local' && config('filesystems.default') !== 'public') {
            return Storage::disk(config('filesystems.default'))->url($this->path);
        }
        
        // For local/public storage, construct URL using configured asset URL
        if ($assetUrl) {
            return rtrim($assetUrl, '/') . '/storage/' . ltrim($this->path, '/');
        }
        
        return Storage::disk('public')->url($this->path);
    }

    /**
     * Get file data as base64 encoded string for API response
     */
    public function getFileDataBase64Attribute(): ?string
    {
        if ($this->file_data) {
            return base64_encode($this->file_data);
        }
        return null;
    }

    /**
     * Store file binary data from uploaded file
     */
    public function storeFileData($file): void
    {
        $this->file_data = file_get_contents($file->getRealPath());
        $this->mime_type = $file->getMimeType();
        $this->file_size = $file->getSize();
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}
