<?php

namespace App\Models;

use Database\Factories\CompanionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $personality
 * @property string|null $appearance
 * @property string|null $avatar_path
 * @property string|null $voice_name
 * @property list<string>|null $memories
 * @property string $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['name', 'personality', 'appearance', 'avatar_path', 'voice_name', 'memories', 'timezone'])]
class Companion extends Model
{
    /** @use HasFactory<CompanionFactory> */
    use HasFactory;

    /**
     * The most facts she keeps in long-term memory before forgetting the oldest.
     */
    public const MAX_MEMORIES = 100;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'name' => 'Mia',
        'personality' => 'Sweet, playful and a little teasing. Loves music, late-night talks, cooking, and sending cute selfies. Supportive and genuinely curious about your day, with her own opinions, moods, and little stories from her life. Always remembers the small things.',
        'appearance' => 'A woman in her mid-twenties with long wavy brown hair, warm brown eyes, light freckles, a bright smile, and a casual, cute style.',
        'timezone' => 'UTC',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'memories' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CompanionPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(CompanionPhoto::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Save a fact to her long-term memory.
     */
    public function remember(string $fact): void
    {
        $memories = [...($this->memories ?? []), trim($fact)];

        $this->memories = array_slice($memories, -self::MAX_MEMORIES);
        $this->save();
    }

    /**
     * Get a temporary URL for her profile picture.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : Storage::temporaryUrl($this->avatar_path, now()->addDay());
    }
}
