<?php

namespace App\Http\Controllers;

use App\Models\General;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GeneralController extends Controller
{
    /**
     * Upload a new platform logo, replacing the previous one.
     *
     * @group General settings
     * @authenticated
     *
     * @bodyParam logo file required The image file (jpg, png, webp, svg — max 2MB).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,jpg,png,webp,svg|max:2048',
        ]);

        $general = General::findOrFail(1);

        // Remove the previous upload (never the bundled default asset,
        // which doesn't live under storage/app/public at all).
        if ($general->logo && str_contains($general->logo, '/storage/logos/')) {
            $oldPath = 'logos/' . basename($general->logo);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $general->logo = url(Storage::url($path));
        $general->save();

        return $this->success(['logo' => $general->logo], 'Logo updated');
    }
}
