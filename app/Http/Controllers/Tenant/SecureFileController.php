<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\InsuranceClaim;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert les fichiers privés (photos, signatures) uniquement via URL signée
 * et après vérification stricte de l'appartenance au tenant actif (ou sinistre partagé).
 */
class SecureFileController extends Controller
{
    public function show(Request $request, string $path, TenantContext $tenantContext): StreamedResponse
    {
        if (! $request->hasValidSignature() || ! $tenantContext->check()) {
            abort(403, 'Accès au fichier non autorisé.');
        }

        $decodedPath = base64_decode($path, true);
        $tenantId = (int) $tenantContext->id();
        $expectedPrefix = "tenants/{$tenantId}/";

        if ($decodedPath === false || str_contains($decodedPath, '..') || str_contains($decodedPath, "\0")) {
            abort(403, 'Accès refusé.');
        }

        $authorized = str_starts_with($decodedPath, $expectedPrefix);

        // Autorise la lecture des photos d'un sinistre partagé entre Garage et Assurance partenaire
        if (! $authorized && preg_match('#^tenants/(\d+)/insurance-claims/#', $decodedPath, $m)) {
            $ownerTenantId = (int) $m[1];
            $authorized = InsuranceClaim::withoutTenantScope()
                ->where('tenant_id', $ownerTenantId)
                ->where(function ($q) use ($tenantId) {
                    $q->where('insurance_tenant_id', $tenantId)
                        ->orWhere('garage_tenant_id', $tenantId);
                })
                ->whereNotNull('photos')
                ->get(['photos'])
                ->contains(fn (InsuranceClaim $c) => in_array($decodedPath, (array) ($c->photos ?? []), true));
        }

        if (! $authorized) {
            abort(403, 'Accès refusé.');
        }

        if (! Storage::disk('local')->exists($decodedPath)) {
            abort(404, 'Fichier introuvable.');
        }

        return Storage::disk('local')->response($decodedPath);
    }
}
