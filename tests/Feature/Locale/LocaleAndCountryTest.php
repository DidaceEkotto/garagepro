<?php

namespace Tests\Feature\Locale;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleAndCountryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_locale_and_receive_translated_validation_errors(): void
    {
        $response = $this->post('/langue/en');
        $response->assertRedirect();
        $response->assertSessionHas('locale', 'en');

        $validationResponse = $this->withSession(['locale' => 'en'])
            ->post('/inscription', []);

        $validationResponse->assertSessionHasErrors(['garage_name', 'country']);
        $errors = session('errors')->getBag('default');
        $this->assertStringContainsString('required', $errors->first('garage_name'));
        $this->assertStringContainsString('African country', $errors->first('country'));
    }

    public function test_all_six_supported_locales_translate_login_validation_errors(): void
    {
        $expectedSnippets = [
            'fr' => 'obligatoire',
            'en' => 'required',
            'es' => 'obligatorio',
            'pt' => 'obrigatório',
            'ar' => 'مطلوب',
            'zh' => '不能为空',
        ];

        $i = 1;
        foreach ($expectedSnippets as $locale => $snippet) {
            $ip = "10.0.0.{$i}";
            $i++;
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post("/langue/{$locale}")->assertRedirect();

            $res = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->withSession(['locale' => $locale])
                ->post('/connexion', []);
            $res->assertSessionHasErrors(['email', 'password']);

            $emailError = session('errors')->getBag('default')->first('email');
            $this->assertStringContainsString($snippet, $emailError, "Locale {$locale} did not translate email error.");
        }
    }

    public function test_arabic_locale_sets_rtl_direction_in_html(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('lang="ar"', false);
    }

    public function test_registration_requires_valid_african_country_and_persists_it(): void
    {
        $invalidResponse = $this->post('/inscription', [
            'garage_name' => 'Garage Test',
            'slug' => 'garage-test-invalid',
            'email' => 'invalid@example.com',
            'country' => 'US',
            'agencies_count' => 1,
            'password' => 'Secret@Pass123',
            'password_confirmation' => 'Secret@Pass123',
        ]);

        $invalidResponse->assertSessionHasErrors('country');

        $validResponse = $this->post('/inscription', [
            'garage_name' => 'Garage Dakar Pro',
            'slug' => 'garage-dakar-pro',
            'email' => 'dakar@example.com',
            'country' => 'SN',
            'phone' => '771234567',
            'city' => 'Dakar',
            'agencies_count' => 1,
            'password' => 'Secret@Pass123',
            'password_confirmation' => 'Secret@Pass123',
        ]);

        $validResponse->assertSessionHasNoErrors();
        $tenant = Tenant::where('slug', 'garage-dakar-pro')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('SN', $tenant->country);
        $this->assertSame('+221 771234567', $tenant->phone);
    }
}
