<?php

namespace App\Http\Controllers;

use App\Actions\Companion\GenerateCompanionReply;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\MessageChannel;
use App\MessageRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MessageController extends Controller
{
    /**
     * Send her a message and return her reply.
     */
    public function store(StoreMessageRequest $request, GenerateCompanionReply $generateReply): JsonResponse
    {
        $companion = $request->user()->ensureCompanion();

        if ($request->filled('timezone')) {
            $companion->update(['timezone' => $request->validated('timezone')]);
        }

        $message = $companion->messages()->create([
            'role' => MessageRole::User,
            'channel' => $request->enum('channel', MessageChannel::class),
            'body' => $request->validated('body'),
            'image_path' => $request->file('image')?->store("chat/{$companion->id}"),
        ]);

        $reply = $generateReply->handle($companion, $message->channel);

        return response()->json([
            'sent' => new MessageResource($message),
            'replies' => MessageResource::collection($reply['replies']),
            'incoming_call' => $reply['startsCall'],
        ]);
    }

    /**
     * Delete the whole chat history.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $companion = $request->user()->ensureCompanion();

        $companion->messages()->delete();
        Storage::deleteDirectory("chat/{$companion->id}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chat history cleared.')]);

        return to_route('companion.edit');
    }
}
