<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * The number of recent messages loaded when the chat opens.
     */
    private const MESSAGES_SHOWN = 200;

    /**
     * Show the chat with the user's companion.
     */
    public function show(Request $request): Response
    {
        $companion = $request->user()->ensureCompanion();

        $messages = $companion->messages()
            ->latest('id')
            ->take(self::MESSAGES_SHOWN)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('chat', [
            'companion' => [
                'name' => $companion->name,
                'avatar_url' => $companion->avatarUrl(),
                'voice_name' => $companion->voice_name,
            ],
            'messages' => MessageResource::collection($messages)->resolve($request),
        ]);
    }
}
