<?php

namespace App\Ai\Tools;

use App\Models\Companion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class Remember implements Tool
{
    public function __construct(private Companion $companion) {}

    /**
     * Get the name the model uses to call the tool.
     */
    public function name(): string
    {
        return 'remember';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Save a fact about the person you are talking to in your long-term memory.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $fact = $request->string('fact')->trim()->toString();

        if ($fact !== '') {
            $this->companion->remember($fact);
        }

        return 'Saved.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fact' => $schema->string()->description('One short fact, e.g. "Has a job interview on Friday".')->required(),
        ];
    }
}
