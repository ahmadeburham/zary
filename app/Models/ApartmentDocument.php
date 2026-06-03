<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ApartmentDocument extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'apartment_id',
        'user_id',
        'path',
        'document_type',
        'status',
        'rejection_reason',
        'file_data',
        'mime_type',
        'file_size',
    ];

    // Never serialize raw binary into JSON responses (breaks UTF-8 encoding).
    protected $hidden = ['file_data'];

    protected $appends = ['file_url', 'file_data_base64'];

    public function getFileUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
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

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
