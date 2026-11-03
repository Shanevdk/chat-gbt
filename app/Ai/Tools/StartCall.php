<?php

namespace App\Ai\Tools;

use App\Ai\CompanionOutbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class StartCall implements Tool
{
    public function __construct(private CompanionOutbox $outbox) {}

    /**
     * Get the name the model uses to call the tool.
     */
    public function name(): string
    {
        return 'start_call';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Start a voice call with them. Their phone rings right after your texts are delivered.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $this->outbox->startsCall = true;

        return 'Calling them now. Their phone rings as soon as your texts are delivered.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why you are calling, in a few words.'),
        ];
    }
}
