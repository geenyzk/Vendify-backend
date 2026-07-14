<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Distribution of the native Vendify mobile app (the Expo WebView build).
 *
 * Admins upload a signed APK here; the public "Download app" page and the
 * in-app update check both read /app/latest and pull the binary from
 * /app/download. Binaries live on the private 'local' disk and are only ever
 * served through the download endpoint, so we control the mime type and can
 * count installs — they are never exposed via the /storage symlink.
 *
 * @group App Distribution
 */
class AppReleaseController extends Controller
{
    /** Where APKs are stored on the private disk. */
    private const DISK = 'local';
    private const DIR = 'app-releases';

    /** Max upload size in KB (200 MB). APKs are large; see note in README. */
    private const MAX_KB = 204800;

    /**
     * Public: metadata for the current published build of a platform. The
     * mobile shell polls this to prompt users when a newer version_code exists.
     */
    public function latest(Request $request): JsonResponse
    {
        $platform = $request->query('platform', 'android');

        $release = AppRelease::query()
            ->where('platform', $platform)
            ->where('is_active', true)
            ->latest('version_code')
            ->first();

        if (!$release) {
            return $this->success(null, 'No published release yet');
        }

        return $this->success([
            'version_name' => $release->version_name,
            'version_code' => $release->version_code,
            'platform' => $release->platform,
            'notes' => $release->notes,
            'size' => $release->size,
            'size_label' => $release->size_label,
            'download_url' => $release->download_url,
            'released_at' => $release->created_at,
        ]);
    }

    /**
     * Public: stream a release binary. With no id, serves the current
     * published build for the platform. Counts each install.
     */
    public function download(Request $request, ?int $id = null): StreamedResponse|JsonResponse
    {
        $query = AppRelease::query();

        if ($id) {
            $release = $query->find($id);
        } else {
            $platform = $request->query('platform', 'android');
            $release = $query->where('platform', $platform)
                ->where('is_active', true)
                ->latest('version_code')
                ->first();
        }

        if (!$release || !Storage::disk(self::DISK)->exists($release->file_path)) {
            return $this->fail(null, 'Release not found', 404);
        }

        $release->increment('downloads');

        return Storage::disk(self::DISK)->download(
            $release->file_path,
            $release->file_name,
            ['Content-Type' => 'application/vnd.android.package-archive']
        );
    }

    /**
     * Admin: list every uploaded release (newest first) for the manager UI.
     */
    public function index(): JsonResponse
    {
        return $this->success(
            AppRelease::query()->latest('version_code')->get()
        );
    }

    /**
     * Admin: upload a new build and make it the published one for its platform.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:' . self::MAX_KB,
            'version_name' => 'required|string|max:50',
            'version_code' => 'nullable|integer|min:1',
            'platform' => 'nullable|in:android,ios',
            'notes' => 'nullable|string|max:5000',
        ]);

        $platform = $request->input('platform', 'android');
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        // Guard the binary type ourselves: browsers send APKs as
        // application/octet-stream, so a mimes: rule is unreliable here.
        $allowedExt = $platform === 'ios' ? ['ipa'] : ['apk'];
        if (!in_array($ext, $allowedExt, true)) {
            return $this->fail(
                ['file' => ['Expected a .' . $allowedExt[0] . ' file for ' . $platform . '.']],
                'Invalid app binary',
                422
            );
        }

        // Auto-assign the next version_code when the admin omits it so the
        // update check always sees a strictly newer number.
        $versionCode = (int) $request->input('version_code', 0);
        if ($versionCode < 1) {
            $versionCode = (int) AppRelease::where('platform', $platform)->max('version_code') + 1;
        }

        $fileName = "vendify-{$platform}-{$versionCode}.{$ext}";
        $path = $file->storeAs(self::DIR, $fileName, self::DISK);

        // Only one active release per platform — retire the previous one.
        AppRelease::where('platform', $platform)->update(['is_active' => false]);

        $release = AppRelease::create([
            'version_name' => $request->input('version_name'),
            'version_code' => $versionCode,
            'platform' => $platform,
            'notes' => $request->input('notes'),
            'file_path' => $path,
            'file_name' => "vendify-{$request->input('version_name')}.{$ext}",
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'is_active' => true,
        ]);

        return $this->success($release, 'Release published', 201);
    }

    /**
     * Admin: delete a release and its binary. If it was the active one, the
     * newest remaining release for that platform is promoted automatically.
     */
    public function destroy(int $id): JsonResponse
    {
        $release = AppRelease::find($id);
        if (!$release) {
            return $this->fail(null, 'Release not found', 404);
        }

        Storage::disk(self::DISK)->delete($release->file_path);
        $wasActive = $release->is_active;
        $platform = $release->platform;
        $release->delete();

        if ($wasActive) {
            $next = AppRelease::where('platform', $platform)
                ->latest('version_code')
                ->first();
            $next?->update(['is_active' => true]);
        }

        return $this->success(null, 'Release deleted');
    }
}
