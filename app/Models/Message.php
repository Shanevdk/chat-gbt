<?php

namespace App\Models;

use App\MessageChannel;
use App\MessageRole;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $companion_id
 * @property MessageRole $role
 * @property MessageChannel $channel
 * @property string|null $body
 * @property string|null $image_path
 * @property string|null $image_caption
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Companion $companion
 */
#[Fillable(['role', 'channel', 'body', 'image_path', 'image_caption'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'channel' => MessageChannel::class,
        ];
    }

    /**
     * @return BelongsTo<Companion, $this>
     */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }

    /**
     * Get a temporary URL for the attached image.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path === null
            ? null
            : Storage::temporaryUrl($this->image_path, now()->addDay());
    }
}
