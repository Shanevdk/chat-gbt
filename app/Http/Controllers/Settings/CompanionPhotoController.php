<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanionPhotoStoreRequest;
use App\Models\CompanionPhoto;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CompanionPhotoController extends Controller
{
    /**
     * Add a photo to her camera roll.
     */
    public function store(CompanionPhotoStoreRequest $request): RedirectResponse
    {
        $request->user()->ensureCompanion()->photos()->create([
            'path' => $request->file('photo')->store('companions/photos'),
            'caption' => $request->validated('caption'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo added.')]);

        return to_route('companion.edit');
    }

    /**
     * Remove a photo from her camera roll.
     */
    public function destroy(Request $request, CompanionPhoto $photo): RedirectResponse
    {
        abort_unless($photo->companion->user_id === $request->user()->id, 404);

        $photo->delete();

        if (Message::where('image_path', $photo->path)->doesntExist()) {
            Storage::delete($photo->path);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return to_route('companion.edit');
    }
}
