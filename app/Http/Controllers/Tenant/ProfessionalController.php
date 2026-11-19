<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfessionalController extends Controller
{
    public function index(Request $request, SecureUploadService $uploadService): Response
    {
        $this->authorize('viewAny', User::class);

        $employeesByUserId = Employee::all()->keyBy('user_id');

        $professionals = User::with(['roles', 'agencies', 'defaultAgency'])
            ->orderBy('name')
            ->get()
            ->map(function (User $u) use ($employeesByUserId, $uploadService) {
                $emp = $employeesByUserId->get($u->id);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'is_active' => $u->is_active,
                    'roles' => $u->getRoleNames()->values(),
                    'role' => $u->getRoleNames()->first(),
                    'default_agency_id' => $u->default_agency_id,
                    'default_agency' => $u->defaultAgency?->name,
                    'gender' => $emp?->gender,
                    'marital_status' => $emp?->marital_status,
                    'birth_date' => $emp?->birth_date?->format('Y-m-d'),
                    'birth_place' => $emp?->birth_place,
                    'base_salary' => $emp?->base_salary ?? 0,
                    'commission_rate' => $emp?->commission_rate ?? 0,
                    'photo_url' => $emp?->photo_path ? $uploadService->signedUrl($emp->photo_path, 60) : null,
                ];
            });

        return Inertia::render('Tenant/Professionals/Index', [
            'professionals' => $professionals,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'availableRoles' => [
                ['value' => 'gerant', 'label' => 'Gérant'],
                ['value' => 'receptionniste', 'label' => 'Réceptionniste'],
                ['value' => 'chef_atelier', 'label' => 'Chef d\'atelier'],
                ['value' => 'mecanicien', 'label' => 'Mécanicien'],
                ['value' => 'magasinier', 'label' => 'Magasinier'],
                ['value' => 'comptable', 'label' => 'Comptable'],
            ],
            'canManage' => $request->user('web')->can('users.manage'),
        ]);
    }

    public function store(
        Request $request,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $this->authorize('create', User::class);

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['gerant', 'receptionniste', 'chef_atelier', 'mecanicien', 'magasinier', 'comptable'])],
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'gender' => ['required', Rule::in(['M', 'F'])],
            'marital_status' => ['required', Rule::in(['celibataire', 'marie', 'divorce', 'veuf'])],
            'birth_date' => ['nullable', 'date'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'base_salary' => ['required', 'integer', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'photo' => ['nullable', 'file', 'max:5120'],
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $uploadService->storeImage($request->file('photo'), $tenantId, 'professionals/photos');
        }

        $roleLabels = [
            'gerant' => 'Gérant',
            'receptionniste' => 'Réceptionniste',
            'chef_atelier' => 'Chef d\'atelier',
            'mecanicien' => 'Mécanicien',
            'magasinier' => 'Magasinier',
            'comptable' => 'Comptable',
        ];

        $professional = DB::transaction(function () use ($validated, $tenantId, $photoPath, $roleLabels): User {
            $user = User::create([
                'tenant_id' => $tenantId,
                'default_agency_id' => $validated['agency_id'],
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'is_active' => true,
            ]);

            setPermissionsTeamId($tenantId);
            $user->assignRole($validated['role']);
            $user->agencies()->attach($validated['agency_id'], ['tenant_id' => $tenantId]);

            // Crée automatiquement la fiche RH liée au professionnel
            Employee::create([
                'tenant_id' => $tenantId,
                'agency_id' => $validated['agency_id'],
                'user_id' => $user->id,
                'full_name' => $validated['name'],
                'role_title' => $roleLabels[$validated['role']] ?? $validated['role'],
                'phone' => $validated['phone'] ?? null,
                'gender' => $validated['gender'],
                'marital_status' => $validated['marital_status'],
                'birth_date' => $validated['birth_date'] ?? null,
                'birth_place' => $validated['birth_place'] ?? null,
                'photo_path' => $photoPath,
                'base_salary' => $validated['base_salary'],
                'commission_rate' => $validated['commission_rate'] ?? 0,
                'is_active' => true,
                'hired_at' => now()->toDateString(),
            ]);

            return $user;
        });

        AuditLog::record(
            'professional.created',
            subject: $professional,
            actor: $request->user('web'),
            tenantId: $tenantId,
            metadata: ['role' => $validated['role']],
            request: $request
        );

        return back()->with('success', "Professionnel « {$professional->name} » ajouté avec sa fiche RH.");
    }

    public function update(
        Request $request,
        User $professional,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $this->authorize('update', $professional);

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($professional->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(['gerant', 'receptionniste', 'chef_atelier', 'mecanicien', 'magasinier', 'comptable'])],
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'gender' => ['required', Rule::in(['M', 'F'])],
            'marital_status' => ['required', Rule::in(['celibataire', 'marie', 'divorce', 'veuf'])],
            'birth_date' => ['nullable', 'date'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'base_salary' => ['required', 'integer', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'photo' => ['nullable', 'file', 'max:5120'],
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $uploadService->storeImage($request->file('photo'), $tenantId, 'professionals/photos');
        }

        $roleLabels = [
            'gerant' => 'Gérant',
            'receptionniste' => 'Réceptionniste',
            'chef_atelier' => 'Chef d\'atelier',
            'mecanicien' => 'Mécanicien',
            'magasinier' => 'Magasinier',
            'comptable' => 'Comptable',
        ];

        DB::transaction(function () use ($professional, $validated, $tenantId, $photoPath, $roleLabels): void {
            $professional->update([
                'default_agency_id' => $validated['agency_id'],
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
            ]);

            setPermissionsTeamId($tenantId);
            $professional->syncRoles([$validated['role']]);
            $professional->agencies()->syncWithoutDetaching([$validated['agency_id'] => ['tenant_id' => $tenantId]]);

            $employee = Employee::firstOrNew(['user_id' => $professional->id, 'tenant_id' => $tenantId]);
            $employee->fill([
                'agency_id' => $validated['agency_id'],
                'full_name' => $validated['name'],
                'role_title' => $roleLabels[$validated['role']] ?? $validated['role'],
                'phone' => $validated['phone'] ?? null,
                'gender' => $validated['gender'],
                'marital_status' => $validated['marital_status'],
                'birth_date' => $validated['birth_date'] ?? null,
                'birth_place' => $validated['birth_place'] ?? null,
                'base_salary' => $validated['base_salary'],
                'commission_rate' => $validated['commission_rate'] ?? 0,
            ]);
            if ($photoPath !== null) {
                $employee->photo_path = $photoPath;
            }
            $employee->save();
        });

        AuditLog::record(
            'professional.updated',
            subject: $professional,
            actor: $request->user('web'),
            tenantId: $tenantId,
            request: $request
        );

        return back()->with('success', "Informations de « {$professional->name} » mises à jour.");
    }

    public function toggleActive(Request $request, User $professional, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('update', $professional);

        if ((int) $professional->id === (int) $request->user('web')->id) {
            return back()->with('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        $newState = ! $professional->is_active;
        $professional->update(['is_active' => $newState]);
        Employee::where('user_id', $professional->id)->update(['is_active' => $newState]);

        AuditLog::record(
            'professional.toggled_active',
            subject: $professional,
            actor: $request->user('web'),
            tenantId: $tenantContext->id(),
            metadata: ['is_active' => $newState],
            request: $request
        );

        return back()->with('success', 'Statut du professionnel mis à jour.');
    }

    public function resetPassword(Request $request, User $professional, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('update', $professional);

        $professional->update([
            'password' => '00000000',
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        AuditLog::record(
            'professional.password_reset',
            subject: $professional,
            actor: $request->user('web'),
            tenantId: $tenantContext->id(),
            request: $request
        );

        return back()->with('success', "Le mot de passe de « {$professional->name} » a été réinitialisé à 00000000.");
    }
}
