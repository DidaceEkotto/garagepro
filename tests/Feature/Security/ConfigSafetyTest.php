<?php

namespace Tests\Feature\Security;

use App\Support\SecretMasker;
use Tests\TestCase;

class ConfigSafetyTest extends TestCase
{
    public function test_saspay_config_reads_from_env_with_null_default_and_no_hardcoded_secrets(): void
    {
        $content = file_get_contents(config_path('saspay.php'));
        $this->assertIsString($content);
        $this->assertStringContainsString("env('SASPAY_SECRET_KEY', null)", $content);
        $this->assertStringContainsString("env('SASPAY_WEBHOOK_SECRET', null)", $content);
        $this->assertStringNotContainsString('sk_live_', $content);
        $this->assertStringNotContainsString('sk_test_', $content);

        $this->assertSame('sk_****', SecretMasker::mask('sk_test_1234567890'));
        $this->assertSame('', SecretMasker::mask(null));
    }

    public function test_env_is_listed_in_gitignore(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertIsString($gitignore);
        $this->assertMatchesRegularExpression('/^\.env$/m', str_replace("\r\n", "\n", $gitignore));
    }
}
