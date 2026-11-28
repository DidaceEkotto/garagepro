<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestion de la Vitrine publique (photos de l'établissement) et des Prestations (services avec image facultative).
 */
class ShowcaseController extends Controller
{
    public function index(TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant, 403);

        $photos = collect(is_array($tenant->showcase_photos) ? $tenant->showcase_photos : [])
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->values()
            ->map(fn (string $path, int $index) => [
                'index' => $index,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ])
            ->all();

        $services = TenantService::where('tenant_id', $tenant->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (TenantService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'description' => $s->description,
                'price' => $s->price,
                'duration_minutes' => $s->duration_minutes,
                'image_url' => $s->imageUrl(),
                'is_active' => $s->is_active,
            ])
            ->all();

        return Inertia::render('Tenant/Showcase/Index', [
            'photos' => $photos,
            'services' => $services,
            'publicGarageUrl' => route('public.garage.show', ['slug' => $tenant->slug]),
            'tenantSlug' => $tenant->slug,
            'categories' => [
                ['value' => 'entretien', 'label' => 'Entretien & Vidange'],
                ['value' => 'mecanique', 'label' => 'Mécanique générale'],
                ['value' => 'diagnostic', 'label' => 'Diagnostic électronique / OBD'],
                ['value' => 'carrosserie', 'label' => 'Carrosserie & Peinture'],
                ['value' => 'pneumatique', 'label' => 'Pneumatiques & Géométrie'],
                ['value' => 'climatisation', 'label' => 'Climatisation & Électricité'],
                ['value' => 'visite_technique', 'label' => 'Visite technique & Contrôle'],
                ['value' => 'depannage', 'label' => 'Dépannage & Remorquage'],
                ['value' => 'autre', 'label' => 'Autre prestation'],
            ],
        ]);
    }

    public function uploadPhotos(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant, 403);

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $existing = is_array($tenant->showcase_photos) ? array_values($tenant->showcase_photos) : [];

        foreach ($request->file('photos', []) as $file) {
            if (count($existing) >= 12) {
                break;
            }
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $path = $file->storeAs("tenants/{$tenant->id}/showcase", Str::random(32).'.'.$ext, 'public');
            $existing[] = $path;
        }

        $tenant->update(['showcase_photos' => $existing]);

        return back()->with('success', 'Photos de votre établissement ajoutées à votre vitrine publique.');
    }

    public function deletePhoto(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant, 403);

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:255'],
        ]);

        $existing = is_array($tenant->showcase_photos) ? array_values($tenant->showcase_photos) : [];
        $target = $validated['path'];

        if (! in_array($target, $existing, true)) {
            return back()->with('error', 'Photo introuvable.');
        }

        $updated = array_values(array_filter($existing, fn ($p) => $p !== $target));
        $tenant->update(['showcase_photos' => $updated]);

        if (str_starts_with($target, "tenants/{$tenant->id}/showcase/")) {
            Storage::disk('public')->delete($target);
        }

        return back()->with('success', 'Photo supprimée de votre vitrine.');
    }

    public function storeService(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1200'],
            'price' => ['required', 'integer', 'min:0', 'max:50000000'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $ext = strtolower($request->file('image')->getClientOriginalExtension() ?: 'jpg');
            $imagePath = $request->file('image')->storeAs("tenants/{$tenant->id}/services", Str::random(32).'.'.$ext, 'public');
        }

        TenantService::create([
            'tenant_id' => $tenant->id,
            'name' => trim($validated['name']),
            'category' => trim($validated['category']),
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'image_path' => $imagePath,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Prestation ajoutée avec succès.');
    }

    public function updateService(Request $request, TenantService $service, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant && (int) $service->tenant_id === (int) $tenant->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1200'],
            'price' => ['required', 'integer', 'min:0', 'max:50000000'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        $imagePath = $service->image_path;
        if (! empty($validated['remove_image']) && $imagePath) {
            Storage::disk('public')->delete($imagePath);
            $imagePath = null;
        }
        if ($request->hasFile('image')) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            $ext = strtolower($request->file('image')->getClientOriginalExtension() ?: 'jpg');
            $imagePath = $request->file('image')->storeAs("tenants/{$tenant->id}/services", Str::random(32).'.'.$ext, 'public');
        }

        $service->update([
            'name' => trim($validated['name']),
            'category' => trim($validated['category']),
            'description' => $validated['description'] ?? null,
            'price' => (int) $validated['price'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'image_path' => $imagePath,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $service->is_active,
        ]);

        return back()->with('success', 'Prestation mise à jour.');
    }

    public function deleteService(TenantService $service, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        abort_unless($tenant && (int) $service->tenant_id === (int) $tenant->id, 403);

        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }
        $service->delete();

        return back()->with('success', 'Prestation supprimée.');
    }
}
