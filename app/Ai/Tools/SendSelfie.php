<?php

namespace App\Ai\Tools;

use App\Ai\CompanionOutbox;
use App\Models\Companion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;
use Stringable;

class SendSelfie implements Tool
{
    public function __construct(private Companion $companion, private CompanionOutbox $outbox) {}

    /**
     * Get the name the model uses to call the tool.
     */
    public function name(): string
    {
        return 'send_selfie';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Take a brand new photo of yourself and send it. Describe the shot: where you are, what you are wearing, what you are doing, and your expression.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $description = $request->string('description')->trim()->toString();

        try {
            $image = Image::of($this->imagePrompt($description))
                ->attachments($this->referencePhotos())
                ->portrait()
                ->generate(
                    (string) config('services.companion.provider'),
                    (string) config('services.companion.image_model'),
                );
        } catch (AiException|RequestException|ConnectionException $exception) {
            report($exception);

            return 'Your camera glitched and the photo did not send.';
        }

        if ($image->images->isEmpty()) {
            return 'Your camera glitched and the photo did not send.';
        }

        $path = (string) $image->store("companions/{$this->companion->id}/selfies");

        if ($this->companion->avatar_path === null) {
            $this->companion->update(['avatar_path' => $path]);
        }

        $this->outbox->addPhoto($request->toolCallId(), $path, $description);

        return 'Photo sent.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()
                ->description('What the photo shows, e.g. "Mirror selfie in a cozy sweater, messy bun, playful smile".')
                ->required(),
        ];
    }

    /**
     * Describe the photo to the image model.
     */
    private function imagePrompt(string $description): string
    {
        $appearance = $this->companion->appearance ?: 'a woman in her mid-twenties';
        $prompt = "A candid, photorealistic smartphone photo of {$this->companion->name}: {$appearance}. {$description}. Natural lighting, casual and tasteful.";

        return $this->companion->avatar_path === null
            ? $prompt
            : $prompt.' Keep her face, hair, and features exactly the same as in the reference photo.';
    }

    /**
     * Use her profile picture so she looks like the same person every time.
     *
     * @return list<ImageFile>
     */
    private function referencePhotos(): array
    {
        return $this->companion->avatar_path === null
            ? []
            : [ImageFile::fromStorage($this->companion->avatar_path)];
    }
}
