<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert les fichiers privés (photos, signatures) uniquement via URL signée
 * et après vérification stricte de l'appartenance au tenant actif.
 */
class SecureFileController extends Controller
{
    public function show(Request $request, string $path, TenantContext $tenantContext): StreamedResponse
    {
        if (! $request->hasValidSignature() || ! $tenantContext->check()) {
            abort(403, 'Accès au fichier non autorisé.');
        }

        $decodedPath = base64_decode($path, true);
        $expectedPrefix = "tenants/{$tenantContext->id()}/";

        if ($decodedPath === false || str_contains($decodedPath, '..') || ! str_starts_with($decodedPath, $expectedPrefix)) {
            abort(403, 'Accès refusé.');
        }

        if (! Storage::disk('local')->exists($decodedPath)) {
            abort(404, 'Fichier introuvable.');
        }

        return Storage::disk('local')->response($decodedPath);
    }
}
