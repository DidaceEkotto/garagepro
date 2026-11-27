<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\AiDiagnosticMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AutomotiveFileAnalyzerService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiAutomotiveFileAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private function createGarageGerant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => Tenant::TYPE_GARAGE,
            'email' => "{$slug}@ai-sec.test",
            'phone' => '+237690000999',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Atelier', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Chef Atelier',
            'email' => "chef@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_blocks_video_by_extension_and_disguised_video_magic_bytes(): void
    {
        [, , $user] = $this->createGarageGerant('ai-vid');
        config(['services.gemini.api_key' => 'test-key']);

        // 1) Vidéo par extension (.mp4)
        $mp4 = UploadedFile::fake()->createWithContent('moteur.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00");
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse cette vidéo moteur',
                'files' => [$mp4],
            ])
            ->assertSessionHasErrors('files');

        // 2) Vidéo déguisée en fichier ECU .bin (magic bytes ftyp)
        $disguisedMp4 = UploadedFile::fake()->createWithContent('fake_ecu.bin', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00");
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce dump ECU',
                'files' => [$disguisedMp4],
            ])
            ->assertSessionHasErrors('files');
    }

    public function test_blocks_compressed_archives_by_extension_and_disguised_zip_magic_bytes(): void
    {
        [, , $user] = $this->createGarageGerant('ai-zip');
        config(['services.gemini.api_key' => 'test-key']);

        foreach (['dump.zip', 'carto.rar', 'logs.7z', 'backup.tar', 'data.gz'] as $archiveName) {
            $file = UploadedFile::fake()->createWithContent($archiveName, 'fake archive content');
            $this->actingAs($user)
                ->post('/assistant-ia/messages', [
                    'prompt' => 'Analyse cette archive',
                    'files' => [$file],
                ])
                ->assertSessionHasErrors('files');
        }

        // Archive ZIP renommée en .bin (magic bytes PK\x03\x04)
        $disguisedZip = UploadedFile::fake()->createWithContent('carto_stage1.bin', "PK\x03\x04\x14\x00\x00\x00\x08\x00");
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce fichier bin',
                'files' => [$disguisedZip],
            ])
            ->assertSessionHasErrors('files');

        // Archive RAR renommée en .csv (magic bytes Rar!)
        $disguisedRar = UploadedFile::fake()->createWithContent('obd_log.csv', "Rar!\x1a\x07\x01\x00payload");
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce CSV',
                'files' => [$disguisedRar],
            ])
            ->assertSessionHasErrors('files');
    }

    public function test_blocks_executable_and_script_payloads_even_when_renamed(): void
    {
        [, , $user] = $this->createGarageGerant('ai-exec');
        config(['services.gemini.api_key' => 'test-key']);

        // Webshell PHP renommé en .log
        $phpPayload = UploadedFile::fake()->createWithContent('diag.log', '<?php system($_GET["c"]); ?>');
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce log',
                'files' => [$phpPayload],
            ])
            ->assertSessionHasErrors('files');

        // SVG XSS renommé en .xml
        $svgPayload = UploadedFile::fake()->createWithContent('tecdoc.xml', '<svg onload="alert(1)"></svg>');
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce XML',
                'files' => [$svgPayload],
            ])
            ->assertSessionHasErrors('files');

        // Exécutable Windows PE (MZ + PE\0\0) renommé en .bin
        $peBinary = 'MZ' . str_repeat("\x00", 58) . pack('V', 64) . "PE\x00\x00" . str_repeat("\x90", 32);
        $exePayload = UploadedFile::fake()->createWithContent('ecu_flash.bin', $peBinary);
        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce binaire',
                'files' => [$exePayload],
            ])
            ->assertSessionHasErrors('files');
    }

    public function test_obd_csv_pre_analyzer_extracts_dtc_and_sensor_stats_compactly(): void
    {
        Storage::fake('public');
        [, , $user] = $this->createGarageGerant('ai-csv');
        config(['services.gemini.api_key' => 'test-key']);

        $capturedPayload = null;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();

                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => "Diagnostic OBD-II : le code P0300 indique des ratés d'allumage multiples. Vérifiez bougies, bobines et pression carburant.",
                            ]],
                        ],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 310],
                ], 200);
            },
        ]);

        // Génère un CSV de 250 lignes (simule un gros datalog Torque/VCDS)
        $lines = ["Time,RPM,CoolantTemp,Boost_kPa,DTC"];
        for ($i = 0; $i < 250; $i++) {
            $rpm = 800 + ($i * 15);
            $temp = 85 + ($i % 10);
            $boost = 100 + ($i % 50);
            $dtc = $i === 120 ? 'P0300' : '';
            $lines[] = "{$i},{$rpm},{$temp},{$boost},{$dtc}";
        }
        $csvFile = UploadedFile::fake()->createWithContent('datalog_peugeot.csv', implode("\n", $lines));

        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce datalog OBD-II',
                'files' => [$csvFile],
            ])
            ->assertRedirect();

        $this->assertNotNull($capturedPayload, 'Gemini API should have been called');
        $sentJson = json_encode($capturedPayload);
        $this->assertStringContainsString('P0300', $sentJson);
        $this->assertStringContainsString('RPM', $sentJson);
        // Vérifie la compression anti-tokens : le payload envoyé doit rester compact (< 8000 caractères)
        $this->assertLessThan(8000, strlen($sentJson));

        // Le fichier brut CSV ne doit jamais être écrit sur le disque public
        $this->assertEmpty(Storage::disk('public')->allFiles());

        // Vérifie que les métadonnées du fichier sont bien enregistrées sur le message utilisateur
        $userMsg = AiDiagnosticMessage::where('role', 'user')->latest()->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('file', $userMsg->attachments[0]['type'] ?? null);
        $this->assertSame('csv', $userMsg->attachments[0]['ext'] ?? null);
    }

    public function test_ecu_bin_pre_analyzer_extracts_ascii_identifiers_without_sending_raw_binary(): void
    {
        Storage::fake('public');
        [, , $user] = $this->createGarageGerant('ai-ecu');
        config(['services.gemini.api_key' => 'test-key']);

        $capturedPayload = null;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();

                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => 'Calculateur identifié : Bosch EDC17C60 (SW 10SW012345). Vérifiez le checksum avant flashage.',
                            ]],
                        ],
                    ]],
                    'usageMetadata' => ['totalTokenCount' => 240],
                ], 200);
            },
        ]);

        // Simule un dump ECU binaire contenant des identifiants Bosch + données binaires aléatoires
        $binary = str_repeat("\xFF\x00\xA5\x5A", 2048)
            . "BOSCH EDC17C60 HW:0281032456 SW:10SW012345 VF3LPHNYHGS123456\x00"
            . str_repeat("\x12\x34\x56\x78", 2048);
        $binFile = UploadedFile::fake()->createWithContent('peugeot_308_ori.bin', $binary);

        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Quel est ce calculateur ?',
                'files' => [$binFile],
            ])
            ->assertRedirect();

        $this->assertNotNull($capturedPayload);
        $sentJson = json_encode($capturedPayload);
        $this->assertStringContainsString('BOSCH EDC17C60', $sentJson);
        $this->assertStringContainsString('10SW012345', $sentJson);
        $this->assertLessThan(6500, strlen($sentJson));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_off_topic_non_automotive_file_is_flagged_as_hors_sujet(): void
    {
        [, , $user] = $this->createGarageGerant('ai-offtopic');
        config(['services.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => "[HORS_SUJET] Ce fichier contient une recette de cuisine et n'a aucun rapport avec le domaine automobile.",
                        ]],
                    ],
                ]],
                'usageMetadata' => ['totalTokenCount' => 110],
            ], 200),
        ]);

        $recipe = UploadedFile::fake()->createWithContent('recette_ndole.txt', "Ingrédients : feuilles de ndolè, arachides, crevettes, huile de palme.");

        $this->actingAs($user)
            ->post('/assistant-ia/messages', [
                'prompt' => 'Analyse ce fichier',
                'files' => [$recipe],
            ])
            ->assertRedirect();

        $assistantMsg = AiDiagnosticMessage::where('role', 'assistant')->latest()->first();
        $this->assertNotNull($assistantMsg);
        $this->assertTrue((bool) $assistantMsg->is_off_topic);
        $this->assertStringNotContainsString('[HORS_SUJET]', $assistantMsg->content);
    }

    public function test_analyzer_service_caps_summary_length_to_save_tokens(): void
    {
        $service = app(AutomotiveFileAnalyzerService::class);
        $hugeLog = str_repeat("2026-11-27 10:00:00 INFO CAN_BUS_OK RPM=1200\n", 1500);
        $file = UploadedFile::fake()->createWithContent('huge_session.log', $hugeLog);

        $validation = $service->validateFile($file);
        $this->assertTrue($validation['ok']);

        $result = $service->extractCompactSummary($file, $validation['ext'], $validation['category']);
        $this->assertIsString($result['summary']);
        $this->assertLessThanOrEqual(4600, mb_strlen($result['summary']));
    }
}
