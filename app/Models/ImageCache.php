<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImageCache extends Model
{
    /** @use HasFactory<\Database\Factories\ImageCacheFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'original_url',
        'cached_path',
        'disk',
        'mime_type',
        'file_size_bytes',
        'width',
        'height',
        'last_fetched_at',
        'fetch_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'last_fetched_at' => 'datetime',
            'fetch_count' => 'integer',
        ];
    }

    public function getCachedUrlAttribute(): ?string
    {
        if (! $this->cached_path) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->cached_path);
    }

    public function incrementFetchCount(): void
    {
        $this->increment('fetch_count');
        $this->update(['last_fetched_at' => now()]);
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->cached_path);
    }

    public function deleteFile(): bool
    {
        if ($this->fileExists()) {
            return Storage::disk($this->disk)->delete($this->cached_path);
        }

        return true;
    }
}
