<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'channel' => $this->channel,
            'body' => $this->body,
            'image_url' => $this->imageUrl(),
            'image_caption' => $this->image_caption,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
