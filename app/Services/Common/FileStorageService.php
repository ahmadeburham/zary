<?php

namespace App\Services\Common;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    /**
     * Upload a file to the specified disk and directory.
     */
    public function upload(UploadedFile $file, string $directory, ?string $filename = null, string $disk = 'public'): string
    {
        if (!$filename) {
            // Generate a secure random filename preserving extension
            $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
        }

        // Stores file as $directory/$filename on the chosen disk
        return $file->storeAs($directory, $filename, $disk);
    }

    /**
     * Specifically upload a user's profile picture under a structured path:
     * profile_pictures/user_{userId}/{secure_filename}
     */
    public function uploadProfilePicture(UploadedFile $file, string $userId, string $disk = 'public'): string
    {
        $directory = "profile_pictures/user_{$userId}";
        return $this->upload($file, $directory, null, $disk);
    }

    /**
     * Delete a file from the specified disk.
     */
    public function delete(?string $path, string $disk = 'public'): bool
    {
        if ($path && Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }
}
