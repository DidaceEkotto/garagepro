<?php

namespace App\Support;

/**
 * Utilitaire de masquage des clés secrètes dans les journaux et diagnostics.
 */
final class SecretMasker
{
    public static function mask(?string $secret, int $visiblePrefix = 3): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }

        if (strlen($secret) <= $visiblePrefix) {
            return str_repeat('*', strlen($secret));
        }

        return substr($secret, 0, $visiblePrefix).'****';
    }
}
