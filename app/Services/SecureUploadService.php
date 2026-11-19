<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gère le stockage sécurisé des photos et signatures hors webroot :
 * - Vérification du type MIME réel (finfo)
 * - Taille maximale contrôlée
 * - Nom de fichier aléatoire UUID
 * - Accès exclusif par URL signée temporaire
 */
class SecureUploadService
{
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5_242_880; // 5 Mo

    public function storeImage(UploadedFile $file, int $tenantId, string $folder): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Le fichier dépasse la taille maximale autorisée de 5 Mo.');
        }

        // Vérification du type MIME réel sur le contenu du fichier (et non l'extension client).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file->getRealPath()) ?: '';

        if (! isset(self::ALLOWED_IMAGE_MIMES[$realMime])) {
            throw new InvalidArgumentException('Format de fichier non autorisé. Formats acceptés : JPEG, PNG, WebP.');
        }

        $extension = self::ALLOWED_IMAGE_MIMES[$realMime];
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = "tenants/{$tenantId}/{$folder}";

        return $file->storeAs($directory, $filename, 'local');
    }

    public function signedUrl(string $path, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('tenant.files.show', now()->addMinutes($minutes), [
            'path' => base64_encode($path),
        ]);
    }

    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }
}
