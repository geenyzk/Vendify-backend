<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    protected $fillable = [
        'version_name', 'version_code', 'platform', 'notes',
        'file_path', 'file_name', 'size', 'mime', 'is_active', 'downloads',
    ];

    protected $casts = [
        'version_code' => 'integer',
        'size' => 'integer',
        'downloads' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['download_url', 'size_label'];

    /** Public URL clients hit to fetch this specific build. */
    public function getDownloadUrlAttribute(): string
    {
        return url("/api/app/download/{$this->id}");
    }

    /** Human-friendly size (e.g. "24.6 MB") for admin UI + release pages. */
    public function getSizeLabelAttribute(): string
    {
        $bytes = (int) $this->size;
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
