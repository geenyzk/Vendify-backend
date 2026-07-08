<?php

namespace App\Http\Controllers;

use App\Models\General;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    /**
     * Public, unauthenticated subset of General settings — just enough for
     * pages rendered before login (landing, auth screens) to show the real
     * configured brand name/logo/page-title instead of a hardcoded default.
     * Never expose the rest of General here (bank/BVN details etc.).
     *
     * @group Branding
     */
    public function show(): JsonResponse
    {
        $general = General::find(1);

        return $this->success([
            'app_name' => $general?->app_name ?: 'Laravel',
            'logo' => $general?->app_logo,
            'meta_title' => $general?->meta_title ?: ($general?->app_name ?: 'Laravel'),
            'meta_description' => $general?->meta_description,
            // Public-facing contact details (shown in the landing footer) —
            // still nothing sensitive from General (bank/BVN stays private).
            'app_email' => $general?->app_email,
            'app_phone' => $general?->app_phone,
        ]);
    }
}
