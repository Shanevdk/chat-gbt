<?php

namespace App\Ai\Tools;

use App\Ai\CompanionOutbox;
use App\Models\Companion;
use App\Models\CompanionPhoto;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SendPhoto implements Tool
{
    public function __construct(private Companion $companion, private CompanionOutbox $outbox) {}

    /**
     * Get the name the model uses to call the tool.
     */
    public function name(): string
    {
        return 'send_photo';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        $cameraRoll = $this->companion->photos
            ->map(fn (CompanionPhoto $photo): string => "{$photo->id}: {$photo->caption}")
            ->implode("\n");

        return "Send one existing photo of yourself from your camera roll. Your camera roll:\n{$cameraRoll}";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $photo = $this->companion->photos->firstWhere('id', $request->integer('photo_id'));

        if ($photo === null) {
            return 'That photo is not in your camera roll.';
        }

        $this->outbox->addPhoto($request->toolCallId(), $photo->path, $photo->caption);

        return 'Photo sent.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'photo_id' => $schema->integer()->enum($this->companion->photos->modelKeys())->required(),
        ];
    }
}
