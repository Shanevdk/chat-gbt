<?php

namespace App\Http\Controllers;

use App\Actions\Companion\GenerateCompanionReply;
use App\Http\Requests\EndCallRequest;
use App\Http\Requests\StartCallRequest;
use App\Http\Resources\MessageResource;
use App\MessageChannel;
use App\MessageRole;
use Illuminate\Http\JsonResponse;

class CallController extends Controller
{
    /**
     * Connect a voice call and return her greeting.
     */
    public function start(StartCallRequest $request, GenerateCompanionReply $generateReply): JsonResponse
    {
        $user = $request->user();
        $companion = $user->ensureCompanion();

        if ($request->filled('timezone')) {
            $companion->update(['timezone' => $request->validated('timezone')]);
        }

        $event = $companion->messages()->create([
            'role' => MessageRole::Event,
            'channel' => MessageChannel::Call,
            'body' => $request->boolean('incoming')
                ? "{$companion->name} called {$user->name}"
                : "{$user->name} called {$companion->name}",
        ]);

        $reply = $generateReply->handle($companion, MessageChannel::Call);

        return response()->json([
            'event' => new MessageResource($event),
            'replies' => MessageResource::collection($reply['replies']),
        ]);
    }

    /**
     * Hang up the voice call.
     */
    public function end(EndCallRequest $request): JsonResponse
    {
        $seconds = (int) $request->validated('duration');

        $event = $request->user()->ensureCompanion()->messages()->create([
            'role' => MessageRole::Event,
            'channel' => MessageChannel::Call,
            'body' => sprintf('Call ended · %d:%02d', intdiv($seconds, 60), $seconds % 60),
        ]);

        return response()->json([
            'event' => new MessageResource($event),
        ]);
    }
}
