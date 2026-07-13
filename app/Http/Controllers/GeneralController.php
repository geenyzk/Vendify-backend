<?php

namespace App\Http\Controllers;

use App\Models\General;
use App\Support\PerformanceCache;
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

        // Store the uploaded file under a single deterministic path so the
        // public logo URL stays stable across repeated uploads.
        $request->file('logo')->storeAs('logos', 'brand-logo', 'public');

        $general->logo = url('/branding/logo');
        $general->save();

        PerformanceCache::clearBranding();

        return $this->success(['logo' => $general->logo], 'Logo updated');
    }
}
