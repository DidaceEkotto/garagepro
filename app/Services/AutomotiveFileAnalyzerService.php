<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pré-analyseur local (0 token) pour fichiers techniques automobiles :
 * - Bloque strictement vidéos, archives compressées et exécutables/scripts (extension + magic bytes).
 * - Extrait un résumé technique ultra-compact (max 4 500 caractères) mis en cache par SHA-256
 *   pour économiser 85 à 98 % de tokens Gemini.
 */
class AutomotiveFileAnalyzerService
{
    public const MAX_FILE_BYTES = 15 * 1024 * 1024; // 15 Mo

    public const MAX_SUMMARY_CHARS = 4500;

    private const FORBIDDEN_VIDEO_EXTS = [
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v', '3gp', '3g2', 'mpeg', 'mpg', 'ts', 'ogv',
    ];

    private const FORBIDDEN_ARCHIVE_EXTS = [
        'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'lz', 'zst', 'cab', 'iso', 'dmg', 'jar', 'apk',
    ];

    private const FORBIDDEN_EXECUTABLE_EXTS = [
        'exe', 'dll', 'msi', 'bat', 'cmd', 'sh', 'ps1', 'vbs', 'php', 'phtml', 'phar',
        'jsp', 'asp', 'aspx', 'svg', 'html', 'htm', 'js', 'mjs', 'swf', 'com', 'scr',
    ];

    public const CATEGORY_MAP = [
        // 1. ECU / Tuning
        'bin' => 'ECU / Cartographie',
        'ori' => 'ECU / Fichier origine',
        'mod' => 'ECU / Fichier modifié',
        'ols' => 'ECU / WinOLS',
        'kp' => 'ECU / Map Pack WinOLS',
        'mappack' => 'ECU / Map Pack',
        'hex' => 'ECU / Intel HEX',
        's19' => 'ECU / Motorola S-Record',
        'srec' => 'ECU / Motorola S-Record',
        'a2l' => 'ECU / Description ASAM A2L',
        // 2. Réseaux CAN / LIN / FlexRay
        'dbc' => 'Réseau CAN (DBC)',
        'ldf' => 'Réseau LIN (LDF)',
        'arxml' => 'Architecture AUTOSAR (ARXML)',
        'blf' => 'Log réseau CAN (BLF)',
        'asc' => 'Log réseau CAN (ASC)',
        'trc' => 'Log réseau CAN (TRC)',
        // 3. Diagnostic OBD-II & Télémétrie
        'csv' => 'Datalogging / Capteurs OBD-II (CSV)',
        'mdf' => 'Mesure & Télémétrie (MDF)',
        'mf4' => 'Mesure & Télémétrie (MF4)',
        'log' => 'Journal diagnostic (LOG)',
        'vbo' => 'Télémétrie circuit (VBO)',
        'gpx' => 'Trace GPS / Télémétrie (GPX)',
        // 4. Bases de données & Catalogues
        'xml' => 'Données structurées / Catalogue (XML)',
        'json' => 'Données structurées / Rapport (JSON)',
        'sql' => 'Export SQL atelier',
        'sqlite' => 'Base SQLite atelier',
        'db' => 'Base de données atelier',
        // Documents texte / PDF classiques
        'txt' => 'Document texte',
        'pdf' => 'Document PDF',
    ];

    /**
     * Valide la sécurité d'un fichier uploadé (extension, MIME, taille et magic bytes).
     *
     * @return array{ok: bool, error: ?string, ext: string, category: string}
     */
    public function validateFile(UploadedFile $file): array
    {
        $ext = strtolower(trim((string) $file->getClientOriginalExtension()));
        $mime = strtolower(trim((string) ($file->getMimeType() ?: $file->getClientMimeType())));
        $size = (int) $file->getSize();

        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Le fichier est vide.', 'ext' => $ext, 'category' => ''];
        }

        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'error' => 'Fichier trop volumineux (max 15 Mo).', 'ext' => $ext, 'category' => ''];
        }

        if (in_array($ext, self::FORBIDDEN_VIDEO_EXTS, true) || str_starts_with($mime, 'video/')) {
            return ['ok' => false, 'error' => 'Les vidéos ne sont pas autorisées dans l\'assistant IA.', 'ext' => $ext, 'category' => ''];
        }

        if (
            in_array($ext, self::FORBIDDEN_ARCHIVE_EXTS, true)
            || str_contains($mime, 'zip')
            || str_contains($mime, 'rar')
            || str_contains($mime, '7z')
            || str_contains($mime, 'x-tar')
            || str_contains($mime, 'gzip')
            || str_contains($mime, 'x-bzip')
            || str_contains($mime, 'compressed')
        ) {
            return ['ok' => false, 'error' => 'Les fichiers compressés (ZIP, RAR, 7Z, TAR, GZ...) ne sont pas autorisés.', 'ext' => $ext, 'category' => ''];
        }

        if (in_array($ext, self::FORBIDDEN_EXECUTABLE_EXTS, true)) {
            return ['ok' => false, 'error' => "Le format .{$ext} est bloqué pour des raisons de sécurité.", 'ext' => $ext, 'category' => ''];
        }

        // Inspection des magic bytes (empêche de contourner le filtre en renommant un .zip/.mp4/.exe/.php)
        $path = $file->getRealPath();
        $head = $path ? @file_get_contents($path, false, null, 0, 512) : '';
        if ($head === false) {
            $head = '';
        }

        $magicError = $this->detectForbiddenMagicBytes($head);
        if ($magicError !== null) {
            return ['ok' => false, 'error' => $magicError, 'ext' => $ext, 'category' => ''];
        }

        $category = self::CATEGORY_MAP[$ext] ?? ($ext !== '' ? "Fichier (.{$ext})" : 'Fichier technique');

        return ['ok' => true, 'error' => null, 'ext' => $ext, 'category' => $category];
    }

    private function detectForbiddenMagicBytes(string $head): ?string
    {
        if ($head === '') {
            return null;
        }

        // Archives compressées (ZIP, RAR, 7z, GZ, BZ2, XZ)
        if (
            str_starts_with($head, "PK\x03\x04")
            || str_starts_with($head, "PK\x05\x06")
            || str_starts_with($head, "PK\x07\x08")
            || str_starts_with($head, "Rar!\x1a\x07")
            || str_starts_with($head, "7z\xbc\xaf\x27\x1c")
            || str_starts_with($head, "\x1f\x8b")
            || str_starts_with($head, 'BZh')
            || str_starts_with($head, "\xfd7zXZ\x00")
        ) {
            return 'Archive compressée détectée (ZIP/RAR/7Z/GZ) : les fichiers compressés sont strictement interdits.';
        }

        // Vidéos (MP4/MOV ftyp, MKV/WEBM EBML, AVI RIFF, FLV)
        if (
            (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp')
            || str_starts_with($head, "\x1a\x45\xdf\xa3")
            || (str_starts_with($head, 'RIFF') && strlen($head) >= 12 && substr($head, 8, 4) === 'AVI ')
            || str_starts_with($head, "FLV\x01")
        ) {
            return 'Contenu vidéo détecté : les vidéos ne sont pas autorisées dans l\'assistant IA.';
        }

        // Exécutables Windows PE / Linux ELF / Scripts serveur PHP ou XSS SVG/HTML
        if (
            (str_starts_with($head, 'MZ') && str_contains($head, "PE\x00\x00"))
            || str_starts_with($head, "\x7fELF")
        ) {
            return 'Fichier exécutable détecté et bloqué par sécurité.';
        }

        $lowerHead = strtolower(ltrim($head));
        if (
            str_starts_with($lowerHead, '<?php')
            || str_starts_with($lowerHead, '<?=')
            || str_starts_with($lowerHead, '<script')
            || str_starts_with($lowerHead, '<svg')
            || str_starts_with($lowerHead, '<!doctype html')
            || str_starts_with($lowerHead, '<html')
        ) {
            return 'Script ou fichier HTML/SVG bloqué par sécurité.';
        }

        return null;
    }

    /**
     * Pré-analyse localement le fichier et renvoie un résumé compact (<= 4 500 caractères).
     * Utilise un cache SHA-256 pour éviter de re-parser le même fichier.
     *
     * @return array{summary: string, category: string, ext: string, sha256: string, size_kb: float}
     */
    public function extractCompactSummary(UploadedFile $file, string $ext, string $category): array
    {
        $realPath = $file->getRealPath();
        $originalName = $file->getClientOriginalName();
        $sizeBytes = (int) $file->getSize();
        $sizeKb = round($sizeBytes / 1024, 1);
        $sha256 = $realPath ? (string) @hash_file('sha256', $realPath) : sha1($originalName.$sizeBytes);

        $cacheKey = "ai_auto_file_summary:{$sha256}:{$ext}";

        $summary = Cache::remember($cacheKey, now()->addHours(6), function () use ($realPath, $originalName, $ext, $category, $sizeBytes, $sizeKb, $sha256): string {
            $header = "[FICHIER ANALYSÉ] Nom: {$originalName} | Type: {$category} (.{$ext}) | Taille: {$sizeKb} Ko | SHA256: ".substr($sha256, 0, 12);

            if (! $realPath || ! is_readable($realPath)) {
                return $header."\nContenu illisible.";
            }

            $body = match (true) {
                in_array($ext, ['bin', 'ori', 'mod', 'ols', 'kp', 'mappack', 'blf', 'mdf', 'mf4'], true) => $this->analyzeBinaryAutoFile($realPath, $ext, $sizeBytes),
                in_array($ext, ['hex', 's19', 'srec'], true) => $this->analyzeHexFlashFile($realPath),
                $ext === 'a2l' => $this->analyzeA2lFile($realPath),
                in_array($ext, ['dbc', 'ldf'], true) => $this->analyzeCanLinDatabase($realPath, $ext),
                in_array($ext, ['asc', 'trc'], true) => $this->analyzeCanTraceLog($realPath),
                $ext === 'csv' => $this->analyzeCsvDatalog($realPath),
                in_array($ext, ['sqlite', 'db'], true) => $this->analyzeSqliteDatabase($realPath),
                $ext === 'pdf' => $this->analyzePdfBasic($realPath),
                default => $this->analyzeTextOrStructuredFile($realPath, $ext),
            };

            return Str::limit($header."\n".$body, self::MAX_SUMMARY_CHARS, "\n…[tronqué pour économie de tokens]");
        });

        return [
            'summary' => $summary,
            'category' => $category,
            'ext' => $ext,
            'sha256' => $sha256,
            'size_kb' => $sizeKb,
        ];
    }

    private function analyzeBinaryAutoFile(string $path, string $ext, int $sizeBytes): string
    {
        $lines = [];

        if (in_array($ext, ['bin', 'ori', 'mod', 'ols'], true)) {
            $ecuHint = match (true) {
                $sizeBytes >= 500000 && $sizeBytes <= 530000 => '512 Ko (typique EDC15 / ME7 / SID801)',
                $sizeBytes >= 1000000 && $sizeBytes <= 1060000 => '1 Mo (typique EDC16 / MED9 / SIMOS)',
                $sizeBytes >= 2000000 && $sizeBytes <= 2120000 => '2 Mo (typique EDC16+ / EDC17 / SID206-208 / DCM3.5)',
                $sizeBytes >= 4000000 && $sizeBytes <= 4300000 => '4 Mo (typique EDC17C64 / MED17 / MD1 / MG1)',
                default => round($sizeBytes / 1024, 1).' Ko',
            };
            $lines[] = "Profil taille dump : {$ecuHint}";
        }

        // Lire au maximum les 2 premiers Mo pour extraire les chaînes ASCII identifiantes (HW, SW, VIN, fabricant)
        $chunk = @file_get_contents($path, false, null, 0, min($sizeBytes, 2 * 1024 * 1024));
        if ($chunk === false) {
            return implode("\n", $lines);
        }

        if ($ext === 'blf' && str_starts_with($chunk, 'LOGG')) {
            $lines[] = 'En-tête Vector BLF valide (LOGG).';
        } elseif (in_array($ext, ['mdf', 'mf4'], true) && str_starts_with($chunk, 'MDF')) {
            $lines[] = 'En-tête ASAM MDF valide : '.trim(substr($chunk, 0, 16));
        }

        // Extraction des chaînes ASCII lisibles >= 6 caractères
        preg_match_all('/[A-Za-z0-9_\-\.\/: ]{6,80}/', $chunk, $matches);
        $rawStrings = array_values(array_unique(array_map('trim', $matches[0] ?? [])));

        // Prioriser les chaînes contenant des mots-clés ECU / constructeurs / HW / SW / VIN
        $priorityPattern = '/(bosch|siemens|continental|delphi|denso|marelli|hitachi|valeo|visteon|edc|med17|me7|sid|dcm|md1|mg1|winols|can|ecu|hw|sw|0281|1037|03l|04l|psa|vag|bmw|mercedes|toyota|renault|peugeot|ford|vin)/i';
        $priority = [];
        $others = [];
        $vins = [];

        foreach ($rawStrings as $str) {
            if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $str)) {
                $vins[] = $str;
            }
            if (preg_match($priorityPattern, $str)) {
                $priority[] = $str;
            } elseif (count($others) < 20 && preg_match('/[A-Za-z]{3,}/', $str)) {
                $others[] = $str;
            }
        }

        if ($vins !== []) {
            $lines[] = 'VIN potentiel(s) détecté(s) : '.implode(', ', array_slice(array_unique($vins), 0, 3));
        }

        $selected = array_slice(array_unique(array_merge($priority, $others)), 0, 45);
        if ($selected !== []) {
            $lines[] = "Chaînes identifiantes extraites :\n- ".implode("\n- ", $selected);
        } else {
            $lines[] = 'Aucune chaîne ASCII lisible trouvée (fichier potentiellement chiffré ou compressé en interne).';
        }

        return implode("\n", $lines);
    }

    private function analyzeHexFlashFile(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return 'Fichier HEX/SREC illisible.';
        }

        $lineCount = 0;
        $sample = [];
        while (($line = fgets($handle, 4096)) !== false && $lineCount < 50000) {
            $lineCount++;
            if (count($sample) < 8) {
                $sample[] = trim($line);
            }
        }
        fclose($handle);

        return "Nombre d'enregistrements HEX/SREC : {$lineCount}\nExtrait initial :\n".implode("\n", $sample);
    }

    private function analyzeA2lFile(string $path): string
    {
        $content = @file_get_contents($path, false, null, 0, 512 * 1024) ?: '';
        preg_match_all('/\/begin\s+(MEASUREMENT|CHARACTERISTIC|AXIS_PTS)\s+([A-Za-z0-9_\.]+)/i', $content, $m);
        $items = [];
        foreach ($m[0] ?? [] as $idx => $full) {
            if (count($items) >= 40) {
                break;
            }
            $items[] = ($m[1][$idx] ?? '').': '.($m[2][$idx] ?? '');
        }

        return 'Variables/Cartes A2L trouvées ('.count($m[0] ?? []).' au total, extrait) :'
            ."\n- ".($items !== [] ? implode("\n- ", $items) : 'Aucune balise /begin détectée.');
    }

    private function analyzeCanLinDatabase(string $path, string $ext): string
    {
        $content = @file_get_contents($path, false, null, 0, 512 * 1024) ?: '';
        $lines = [];

        if ($ext === 'dbc') {
            preg_match_all('/^BO_\s+\d+\s+([A-Za-z0-9_]+)\s*:\s*\d+\s*([A-Za-z0-9_]*)/m', $content, $bo);
            preg_match_all('/^\s+SG_\s+([A-Za-z0-9_]+)/m', $content, $sg);
            $lines[] = 'Messages CAN (BO_) : '.count($bo[1] ?? []).' | Signaux (SG_) : '.count($sg[1] ?? []);
            if (! empty($bo[1])) {
                $lines[] = 'Extrait messages : '.implode(', ', array_slice(array_unique($bo[1]), 0, 30));
            }
            if (! empty($sg[1])) {
                $lines[] = 'Extrait signaux : '.implode(', ', array_slice(array_unique($sg[1]), 0, 40));
            }
        } else {
            $lines[] = 'Extrait LDF : '.Str::limit(preg_replace('/\s+/', ' ', $content), 2500);
        }

        return implode("\n", $lines);
    }

    private function analyzeCanTraceLog(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return 'Log CAN illisible.';
        }

        $totalLines = 0;
        $errorFrames = 0;
        $idCounts = [];
        $sample = [];

        while (($line = fgets($handle, 2048)) !== false && $totalLines < 20000) {
            $totalLines++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (stripos($trimmed, 'error') !== false) {
                $errorFrames++;
            }
            if (preg_match('/\b([0-7]?[0-9A-F]{3})\b/i', $trimmed, $m)) {
                $id = strtoupper($m[1]);
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            }
            if (count($sample) < 12) {
                $sample[] = $trimmed;
            }
        }
        fclose($handle);

        arsort($idCounts);
        $topIds = [];
        foreach (array_slice($idCounts, 0, 12, true) as $id => $cnt) {
            $topIds[] = "0x{$id} ({$cnt}x)";
        }

        return "Trames analysées : {$totalLines} | Trames d'erreur détectées : {$errorFrames}\n"
            .'Top IDs CAN : '.($topIds !== [] ? implode(', ', $topIds) : 'Aucun')."\n"
            ."Extrait :\n".implode("\n", $sample);
    }

    private function analyzeCsvDatalog(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return 'Fichier CSV illisible.';
        }

        $firstLine = fgets($handle, 8192);
        if ($firstLine === false) {
            fclose($handle);

            return 'CSV vide.';
        }

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $headers = array_map(fn ($h) => trim((string) $h, " \t\n\r\0\x0B\"'"), str_getcsv($firstLine, $delimiter));

        $stats = [];
        $rowCount = 0;
        $sampleRows = [];
        $dtcCodes = [];

        while (($line = fgets($handle, 8192)) !== false && $rowCount < 10000) {
            $rowCount++;
            if (preg_match_all('/\b[PBUC][0-3][0-9A-F]{3}\b/i', $line, $dm)) {
                foreach ($dm[0] as $code) {
                    $dtcCodes[strtoupper($code)] = true;
                }
            }

            $cols = str_getcsv($line, $delimiter);
            if ($rowCount <= 8) {
                $sampleRows[] = implode(' | ', array_slice($cols, 0, 10));
            }

            foreach ($cols as $idx => $val) {
                $colName = $headers[$idx] ?? "Col{$idx}";
                $numStr = str_replace(',', '.', trim((string) $val));
                if (is_numeric($numStr)) {
                    $num = (float) $numStr;
                    if (! isset($stats[$colName])) {
                        $stats[$colName] = ['min' => $num, 'max' => $num, 'sum' => $num, 'n' => 1];
                    } else {
                        $stats[$colName]['min'] = min($stats[$colName]['min'], $num);
                        $stats[$colName]['max'] = max($stats[$colName]['max'], $num);
                        $stats[$colName]['sum'] += $num;
                        $stats[$colName]['n']++;
                    }
                }
            }
        }
        fclose($handle);

        $out = ["Lignes de mesures : {$rowCount} | Colonnes : ".implode(', ', array_slice($headers, 0, 20))];
        if ($dtcCodes !== []) {
            $out[] = 'Codes défauts OBD-II détectés : '.implode(', ', array_slice(array_keys($dtcCodes), 0, 30));
        }
        if ($stats !== []) {
            $statLines = [];
            foreach (array_slice($stats, 0, 18, true) as $col => $s) {
                $avg = round($s['sum'] / max(1, $s['n']), 2);
                $statLines[] = "{$col}: min={$s['min']}, max={$s['max']}, moy={$avg}";
            }
            $out[] = "Statistiques capteurs :\n- ".implode("\n- ", $statLines);
        }
        if ($sampleRows !== []) {
            $out[] = "Échantillon (premières lignes) :\n".implode("\n", $sampleRows);
        }

        return implode("\n", $out);
    }

    private function analyzeSqliteDatabase(string $path): string
    {
        if (! class_exists(\SQLite3::class)) {
            return $this->analyzeBinaryAutoFile($path, 'db', (int) @filesize($path));
        }

        try {
            $db = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            $res = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' LIMIT 15");
            $tables = [];
            while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
                $name = (string) ($row['name'] ?? '');
                $count = (int) $db->querySingle('SELECT COUNT(*) FROM "'.str_replace('"', '""', $name).'"');
                $tables[] = "{$name} ({$count} lignes) — ".Str::limit((string) ($row['sql'] ?? ''), 160);
            }
            $db->close();

            return "Schéma base SQLite (lecture seule) :\n- ".($tables !== [] ? implode("\n- ", $tables) : 'Aucune table utilisateur.');
        } catch (\Throwable) {
            return $this->analyzeBinaryAutoFile($path, 'db', (int) @filesize($path));
        }
    }

    private function analyzePdfBasic(string $path): string
    {
        $raw = @file_get_contents($path, false, null, 0, 512 * 1024) ?: '';
        if (! str_starts_with($raw, '%PDF')) {
            return 'Fichier PDF non standard.';
        }

        preg_match_all('/\(([A-Za-z0-9À-ÿ ,.\-:\/_]{4,120})\)/u', $raw, $m);
        $snippets = array_slice(array_values(array_unique($m[1] ?? [])), 0, 40);
        preg_match_all('/\b[PBUC][0-3][0-9A-F]{3}\b/i', $raw, $dm);
        $dtcs = array_values(array_unique(array_map('strtoupper', $dm[0] ?? [])));

        $lines = [];
        if ($dtcs !== []) {
            $lines[] = 'Codes défauts OBD-II trouvés dans le PDF : '.implode(', ', array_slice($dtcs, 0, 30));
        }
        if ($snippets !== []) {
            $lines[] = 'Extrait texte PDF : '.implode(' | ', $snippets);
        } else {
            $lines[] = 'PDF scanné ou compressé (FlateDecode) : conseillez une photo/capture lisible si le texte est illisible.';
        }

        return implode("\n", $lines);
    }

    private function analyzeTextOrStructuredFile(string $path, string $ext): string
    {
        $content = @file_get_contents($path, false, null, 0, 512 * 1024) ?: '';
        preg_match_all('/\b[PBUC][0-3][0-9A-F]{3}\b/i', $content, $dm);
        $dtcs = array_values(array_unique(array_map('strtoupper', $dm[0] ?? [])));

        $lines = [];
        if ($dtcs !== []) {
            $lines[] = 'Codes défauts OBD-II détectés : '.implode(', ', array_slice($dtcs, 0, 40));
        }

        if ($ext === 'json') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $lines[] = 'Clés JSON racine : '.implode(', ', array_slice(array_keys($decoded), 0, 20));
            }
        }

        $clean = trim(preg_replace("/\r\n|\r/", "\n", $content));
        $lines[] = "Extrait contenu :\n".Str::limit($clean, 3200);

        return implode("\n", $lines);
    }

    /**
     * Comparateur binaire ECU local .ori vs .mod (0 token consommé).
     */
    public function compareEcuPair(UploadedFile $ori, UploadedFile $mod): array
    {
        $v1 = $this->validateFile($ori);
        if (! $v1['ok']) {
            return ['ok' => false, 'error' => 'Fichier origine (.ori) refusé : '.$v1['error']];
        }

        $v2 = $this->validateFile($mod);
        if (! $v2['ok']) {
            return ['ok' => false, 'error' => 'Fichier modifié (.mod) refusé : '.$v2['error']];
        }

        $p1 = $ori->getRealPath();
        $p2 = $mod->getRealPath();
        if (! $p1 || ! $p2 || ! is_readable($p1) || ! is_readable($p2)) {
            return ['ok' => false, 'error' => 'Impossible de lire les fichiers ECU.'];
        }

        $size1 = (int) filesize($p1);
        $size2 = (int) filesize($p2);
        $maxCompare = min($size1, $size2, 8 * 1024 * 1024);

        $oriSha = (string) hash_file('sha256', $p1);
        $modSha = (string) hash_file('sha256', $p2);
        $oriCrc = strtoupper((string) hash_file('crc32b', $p1));
        $modCrc = strtoupper((string) hash_file('crc32b', $p2));

        $fh1 = fopen($p1, 'rb');
        $fh2 = fopen($p2, 'rb');
        if (! $fh1 || ! $fh2) {
            if ($fh1) {
                fclose($fh1);
            }
            if ($fh2) {
                fclose($fh2);
            }

            return ['ok' => false, 'error' => 'Erreur d\'ouverture binaire des fichiers ECU.'];
        }

        $chunkSize = 65536;
        $offset = 0;
        $diffBytes = 0;
        $regions = [];
        $inRegion = false;
        $regionStart = 0;
        $regionEnd = 0;

        while ($offset < $maxCompare) {
            $toRead = min($chunkSize, $maxCompare - $offset);
            $b1 = fread($fh1, $toRead);
            $b2 = fread($fh2, $toRead);
            if ($b1 === false || $b2 === false || $b1 === '') {
                break;
            }
            $len = min(strlen($b1), strlen($b2));
            if ($b1 !== $b2) {
                for ($i = 0; $i < $len; $i++) {
                    if ($b1[$i] !== $b2[$i]) {
                        $diffBytes++;
                        $addr = $offset + $i;
                        if (! $inRegion) {
                            $inRegion = true;
                            $regionStart = $addr;
                            $regionEnd = $addr;
                        } elseif ($addr - $regionEnd <= 16) {
                            $regionEnd = $addr;
                        } else {
                            if (count($regions) < 25) {
                                $regions[] = [
                                    'start' => sprintf('0x%06X', $regionStart),
                                    'end' => sprintf('0x%06X', $regionEnd),
                                    'length' => $regionEnd - $regionStart + 1,
                                ];
                            }
                            $regionStart = $addr;
                            $regionEnd = $addr;
                        }
                    }
                }
            }
            $offset += $len;
        }

        if ($inRegion && count($regions) < 25) {
            $regions[] = [
                'start' => sprintf('0x%06X', $regionStart),
                'end' => sprintf('0x%06X', $regionEnd),
                'length' => $regionEnd - $regionStart + 1,
            ];
        }

        fclose($fh1);
        fclose($fh2);

        $diffBytes += abs($size1 - $size2);
        $baseSize = max(1, max($size1, $size2));
        $diffPercent = round(($diffBytes / $baseSize) * 100, 4);

        $lines = [
            '[COMPARATEUR ECU .ORI vs .MOD — PRÉ-ANALYSE LOCALE 0 TOKEN]',
            "Origine : {$ori->getClientOriginalName()} (".round($size1 / 1024, 1)." Ko | CRC32: {$oriCrc} | SHA256: ".substr($oriSha, 0, 12).')',
            "Modifié : {$mod->getClientOriginalName()} (".round($size2 / 1024, 1)." Ko | CRC32: {$modCrc} | SHA256: ".substr($modSha, 0, 12).')',
            $size1 !== $size2 ? "ATTENTION : Tailles différentes ({$size1} vs {$size2} octets) — risque d'incompatibilité flashage !" : 'Tailles identiques (cohérent pour flashage).',
            "Octets modifiés : {$diffBytes} / {$baseSize} ({$diffPercent}%)",
            'Zones mémoire modifiées détectées ('.count($regions).' blocs principaux) :',
        ];

        foreach ($regions as $idx => $r) {
            $lines[] = '  #'.($idx + 1)." {$r['start']} .. {$r['end']} ({$r['length']} octets)";
        }

        if ($diffBytes === 0) {
            $lines[] = 'Les deux fichiers sont strictement identiques (0 octet modifié).';
        }

        return [
            'ok' => true,
            'ori_name' => $ori->getClientOriginalName(),
            'mod_name' => $mod->getClientOriginalName(),
            'ori_size' => $size1,
            'mod_size' => $size2,
            'ori_crc32' => $oriCrc,
            'mod_crc32' => $modCrc,
            'ori_sha256' => substr($oriSha, 0, 16),
            'mod_sha256' => substr($modSha, 0, 16),
            'diff_bytes' => $diffBytes,
            'diff_percent' => $diffPercent,
            'regions_count' => count($regions),
            'regions' => $regions,
            'summary' => Str::limit(implode("\n", $lines), self::MAX_SUMMARY_CHARS, "\n…"),
        ];
    }
}
