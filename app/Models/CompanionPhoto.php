<?php

namespace App\Models;

use Database\Factories\CompanionPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $companion_id
 * @property string $path
 * @property string $caption
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Companion $companion
 */
#[Fillable(['path', 'caption'])]
class CompanionPhoto extends Model
{
    /** @use HasFactory<CompanionPhotoFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Companion, $this>
     */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }

    /**
     * Get a temporary URL for the photo.
     */
    public function url(): string
    {
        return Storage::temporaryUrl($this->path, now()->addDay());
    }
}
