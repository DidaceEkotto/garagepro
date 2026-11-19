<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SpaceSettingsController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, SecureUploadService $uploadService): Response
    {
        if (! $request->user('web')->can('settings.manage')) {
            abort(403, 'Accès réservé aux paramètres de l\'espace.');
        }

        $tenant = $tenantContext->getTenant();
        $settings = $tenant->settings ?? [];
        $logoPath = $settings['logo_path'] ?? null;

        return Inertia::render('Tenant/Settings/Index', [
            'space' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address' => $tenant->address,
                'city' => $tenant->city,
                'vat_rate' => (float) $tenant->vat_rate,
                'hourly_rate' => (int) $tenant->hourly_rate,
                'legal_mentions' => $settings['legal_mentions'] ?? '',
                'logo_url' => $logoPath ? $uploadService->signedUrl($logoPath, 60) : null,
                'work_start_time' => $settings['work_start_time'] ?? '08:00',
                'work_end_time' => $settings['work_end_time'] ?? '17:30',
                'work_days' => $settings['work_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'late_penalty_per_hour' => (int) ($settings['late_penalty_per_hour'] ?? 1000),
                'payroll_day_of_month' => isset($settings['payroll_day_of_month']) ? (int) $settings['payroll_day_of_month'] : 28,
            ],
        ]);
    }

    public function update(
        Request $request,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        if (! $request->user('web')->can('settings.manage')) {
            abort(403, 'Accès réservé aux paramètres de l\'espace.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'hourly_rate' => ['required', 'integer', 'min:0'],
            'legal_mentions' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'file', 'max:5120'],
            'work_start_time' => ['required', 'date_format:H:i'],
            'work_end_time' => ['required', 'date_format:H:i'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => [Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'late_penalty_per_hour' => ['required', 'integer', 'min:0'],
            'payroll_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
        ]);

        $tenant = $tenantContext->getTenant();
        $settings = $tenant->settings ?? [];
        $settings['legal_mentions'] = $validated['legal_mentions'] ?? ($settings['legal_mentions'] ?? '');
        $settings['work_start_time'] = $validated['work_start_time'];
        $settings['work_end_time'] = $validated['work_end_time'];
        $settings['work_days'] = array_values(array_unique($validated['work_days']));
        $settings['late_penalty_per_hour'] = (int) $validated['late_penalty_per_hour'];
        $settings['payroll_day_of_month'] = ! empty($validated['payroll_day_of_month']) ? (int) $validated['payroll_day_of_month'] : null;

        if ($request->hasFile('logo')) {
            $settings['logo_path'] = $uploadService->storeImage($request->file('logo'), $tenant->id, 'branding');
        }

        $tenant->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'vat_rate' => $validated['vat_rate'],
            'hourly_rate' => $validated['hourly_rate'],
            'settings' => $settings,
        ]);

        AuditLog::record(
            'space_settings.updated',
            subject: $tenant,
            actor: $request->user('web'),
            tenantId: $tenant->id,
            request: $request
        );

        return back()->with('success', 'Paramètres de l\'espace et horaires de travail mis à jour.');
    }
}
