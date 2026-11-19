<?php

namespace Tests\Feature;

use App\Mail\ContactMessageMail;
use App\Models\Country;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactAndCountryTest extends TestCase
{
    use RefreshDatabase;

    public function test_countries_table_is_seeded_with_54_african_countries_and_dial_codes(): void
    {
        $this->assertGreaterThanOrEqual(54, Country::count());

        $cameroon = Country::where('code', 'CM')->first();
        $this->assertNotNull($cameroon);
        $this->assertSame('+237', $cameroon->dial_code);
        $this->assertTrue($cameroon->is_active);
    }

    public function test_structure_email_defaults_to_jeannotgates_and_contact_page_sends_mail(): void
    {
        Mail::fake();

        $this->assertSame('jeannotgates@gmail.com', PlatformSetting::structureEmail());

        $this->get('/contact')
            ->assertOk()
            ->assertSee('jeannotgates@gmail.com');

        $response = $this->post('/contact', [
            'name' => 'Jean Dupont',
            'email' => 'jean@garage.cm',
            'phone' => '+237 699001122',
            'subject' => 'Demande de démo',
            'message' => 'Bonjour, nous souhaitons utiliser GaragePro pour nos 2 ateliers.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) {
            return $mail->hasTo('jeannotgates@gmail.com');
        });
    }

    public function test_registration_enforces_password_complexity_rules(): void
    {
        // Manque caractère spécial (_, @, -)
        $res1 = $this->post('/inscription', [
            'garage_name' => 'Garage Test',
            'slug' => 'garage-pwd-1',
            'email' => 'pwd1@example.cm',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);
        $res1->assertSessionHasErrors('password');

        // Manque majuscule
        $res2 = $this->post('/inscription', [
            'garage_name' => 'Garage Test',
            'slug' => 'garage-pwd-2',
            'email' => 'pwd2@example.cm',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'password@123',
            'password_confirmation' => 'password@123',
        ]);
        $res2->assertSessionHasErrors('password');

        // Manque chiffre
        $res3 = $this->post('/inscription', [
            'garage_name' => 'Garage Test',
            'slug' => 'garage-pwd-3',
            'email' => 'pwd3@example.cm',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'Password@abc',
            'password_confirmation' => 'Password@abc',
        ]);
        $res3->assertSessionHasErrors('password');
    }
}
