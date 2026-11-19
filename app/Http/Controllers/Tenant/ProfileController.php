<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function index(Request $request, SecureUploadService $uploadService): Response
    {
        $user = $request->user('web');
        $employee = Employee::where('user_id', $user->id)->first();

        return Inertia::render('Tenant/Profile/Index', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo_url' => ($user->avatar_path ?? $employee?->photo_path)
                    ? $uploadService->signedUrl($user->avatar_path ?? $employee->photo_path, 60)
                    : null,
            ],
        ]);
    }

    public function update(
        Request $request,
        TenantContext $tenantContext,
        SecureUploadService $uploadService
    ): RedirectResponse {
        $user = $request->user('web');

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'file', 'max:5120'],
        ]);

        $photoPath = $user->avatar_path;
        if ($request->hasFile('photo')) {
            $photoPath = $uploadService->storeImage($request->file('photo'), $tenantContext->id(), 'professionals/photos');
        }

        $user->update([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'avatar_path' => $photoPath,
        ]);

        $employee = Employee::where('user_id', $user->id)->first();
        if ($employee) {
            $employee->update([
                'full_name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'photo_path' => $photoPath ?? $employee->photo_path,
            ]);
        }

        AuditLog::record('profile.updated', subject: $user, actor: $user, tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', 'Vos informations personnelles ont été mises à jour.');
    }

    public function updatePassword(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $user = $request->user('web');

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }

        $user->update(['password' => $validated['password']]);

        AuditLog::record('profile.password_updated', subject: $user, actor: $user, tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', 'Votre mot de passe a été modifié avec succès.');
    }
}
