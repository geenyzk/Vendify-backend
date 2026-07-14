<?php

namespace App\Http\Controllers;

use App\Models\General;
use App\Support\PerformanceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $branding = Cache::remember(PerformanceCache::BRANDING_KEY, now()->addMinutes(30), function () {
            $general = General::query()
                ->select(['id', 'app_name', 'logo', 'meta_title', 'meta_description', 'app_email', 'app_phone'])
                ->find(1);

            return [
                'app_name' => $general?->app_name ?: 'Laravel',
                // The endpoint is the single source of truth: it serves the
                // installed Vendify logo and any future admin replacement.
                'logo' => url('/api/branding/logo'),
                'meta_title' => $general?->meta_title ?: ($general?->app_name ?: 'Laravel'),
                'meta_description' => $general?->meta_description,
                // Public-facing contact details (shown in the landing footer) —
                // still nothing sensitive from General (bank/BVN stays private).
                'app_email' => $general?->app_email,
                'app_phone' => $general?->app_phone,
            ];
        });

        return $this->success($branding);
    }

    public function logo(): BinaryFileResponse
    {
        $path = 'logos/brand-logo';

        if (!Storage::disk('public')->exists($path)) {
            return response()->file(public_path('images/vendify-logo.png'), ['Content-Type' => 'image/png']);
        }

        $filePath = Storage::disk('public')->path($path);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return response()->file($filePath, ['Content-Type' => $mimeType]);
    }
}
