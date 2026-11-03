<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanionAvatarUpdateRequest;
use App\Http\Requests\Settings\CompanionUpdateRequest;
use App\Models\CompanionPhoto;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanionController extends Controller
{
    /**
     * Show the companion settings page.
     */
    public function edit(Request $request): Response
    {
        $companion = $request->user()->ensureCompanion();

        return Inertia::render('settings/companion', [
            'companion' => [
                'name' => $companion->name,
                'personality' => $companion->personality,
                'appearance' => $companion->appearance,
                'voice_name' => $companion->voice_name,
                'avatar_url' => $companion->avatarUrl(),
                'memories' => $companion->memories ?? [],
            ],
            'photos' => $companion->photos()->latest('id')->get()->map(fn (CompanionPhoto $photo): array => [
                'id' => $photo->id,
                'caption' => $photo->caption,
                'url' => $photo->url(),
            ]),
        ]);
    }

    /**
     * Update her name, personality, and voice.
     */
    public function update(CompanionUpdateRequest $request): RedirectResponse
    {
        $request->user()->ensureCompanion()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('companion.edit');
    }

    /**
     * Replace her profile picture.
     */
    public function updateAvatar(CompanionAvatarUpdateRequest $request): RedirectResponse
    {
        $companion = $request->user()->ensureCompanion();
        $previousAvatarPath = $companion->avatar_path;

        $companion->update(['avatar_path' => $request->file('avatar')->store('companions/avatars')]);

        if ($previousAvatarPath !== null && Message::where('image_path', $previousAvatarPath)->doesntExist()) {
            Storage::delete($previousAvatarPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('companion.edit');
    }

    /**
     * Make her forget everything she has saved about the user.
     */
    public function destroyMemories(Request $request): RedirectResponse
    {
        $request->user()->ensureCompanion()->update(['memories' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Memories cleared.')]);

        return to_route('companion.edit');
    }
}
