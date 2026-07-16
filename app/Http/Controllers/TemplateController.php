<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Support\TemplateVariables;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    /**
     * The catalogue of supported {{variables}} — global ones plus each event's
     * own — so the editor can offer them and warn about placeholders that would
     * otherwise render literally. Same source the AI Manager tools read.
     */
    public function variables(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => TemplateVariables::catalog(),
        ]);
    }

    /**
     * List templates, optionally filtered by type/event/enabled.
     * e.g. GET /admin/templates?type=event&event=register to find the
     * welcome-message template.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Template::query();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        if ($request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $templates = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $templates->toResourceCollection(),
        ]);
    }

    /**
     * Get a single template.
     */
    public function show($id): JsonResponse
    {
        $template = Template::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $template->toResource(),
        ]);
    }

    /**
     * Create a new template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'nullable|string|max:191|unique:templates,slug',
            'type' => 'required|in:event,broadcast',
            'event' => 'nullable|in:login,register,purchase,wallet_credit,wallet_debit',
            'subject' => 'nullable|string|max:255',
            'content' => 'required|string',
            'channels' => 'nullable|array',
            'channels.*' => 'in:email,sms,in_app,push',
            'enabled' => 'boolean',
        ]);

        $template = Template::create($validated);

        return response()->json([
            'success' => true,
            'data' => $template->toResource(),
            'message' => 'Template created successfully',
        ], 201);
    }

    /**
     * Update a template.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $template = Template::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:191',
            'slug' => 'nullable|string|max:191|unique:templates,slug,' . $id,
            'type' => 'in:event,broadcast',
            'event' => 'nullable|in:login,register,purchase,wallet_credit,wallet_debit',
            'subject' => 'nullable|string|max:255',
            'content' => 'string',
            'channels' => 'nullable|array',
            'channels.*' => 'in:email,sms,in_app,push',
            'enabled' => 'boolean',
        ]);

        $template->update($validated);

        return response()->json([
            'success' => true,
            'data' => $template->toResource(),
            'message' => 'Template updated successfully',
        ]);
    }

    /**
     * Delete a template.
     */
    public function destroy($id): JsonResponse
    {
        $template = Template::findOrFail($id);
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }
}
