<?php

namespace Tests\Feature\Accounting;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\PaymentService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SyscohadaAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbalanced_journal_entry_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Compta', 'slug' => 'compta', 'email' => 'c@example.com']);
        $accounting = app(AccountingService::class);

        $this->expectException(InvalidArgumentException::class);

        $accounting->postEntry($tenant, 'OD', 'Écriture déséquilibrée', [
            ['account_code' => '571', 'debit' => 10000, 'credit' => 0],
            ['account_code' => '411', 'debit' => 0, 'credit' => 9000],
        ]);
    }

    public function test_invoice_and_payment_generate_balanced_syscohada_entries_and_reversal(): void
    {
        $tenant = Tenant::create(['name' => 'Garage OHADA', 'slug' => 'ohada', 'email' => 'o@example.com', 'vat_rate' => 19.25]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Douala', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $comptable = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Comptable',
            'email' => 'compta@ohada.cm',
            'password' => 'Secret123!',
        ]);
        $comptable->assignRole('comptable');

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client OHADA',
            'phone' => '699112233',
            'type' => 'entreprise',
        ]);

        $invoice = Invoice::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'FAC-2026-00001',
            'type' => Invoice::TYPE_INVOICE,
            'status' => Invoice::STATUS_ISSUED,
            'vat_rate' => 19.25,
            'subtotal_ht' => 100000,
            'discount_amount' => 0,
            'vat_amount' => 19250,
            'deposit_deducted' => 0,
            'total_ttc' => 119250,
            'paid_amount' => 0,
            'issued_at' => now(),
        ]);
        InvoiceItem::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'type' => 'main_oeuvre',
            'description' => 'Révision moteur',
            'quantity' => 1,
            'unit_price' => 100000,
            'total_price' => 100000,
        ]);

        $accounting = app(AccountingService::class);
        $entry = $accounting->recordInvoiceEntry($invoice);

        // Vérifie correspondance facture <-> écriture : Débit 411 = 119 250, Crédit 706 = 100 000 + Crédit 4431 = 19 250
        $this->assertSame(119250, $entry->lines->sum('debit'));
        $this->assertSame(119250, $entry->lines->sum('credit'));

        // Paiement Mobile Money -> écriture 522 (Débit) / 411 (Crédit)
        app(PaymentService::class)->recordOfflinePayment($invoice, 119250, 'mtn_momo', $comptable);

        $balance = $accounting->generalBalance($tenant)->keyBy('code');
        $this->assertSame(119250, (int) $balance['411']->total_debit);
        $this->assertSame(119250, (int) $balance['411']->total_credit);
        $this->assertSame(119250, (int) $balance['522']->total_debit);
        $this->assertSame(19250, (int) $balance['4431']->total_credit);

        // Contre-passation immuable
        $this->actingAs($comptable, 'web')
            ->post("http://ohada.mboacar.com/comptabilite/ecritures/{$entry->id}/contre-passer")
            ->assertSessionHas('success');

        $this->assertSame(JournalEntry::STATUS_REVERSED, $entry->fresh()->status);

        // Clôture d'exercice verrouille toute nouvelle écriture
        $fy = FiscalYear::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($comptable, 'web')
            ->post("http://ohada.mboacar.com/comptabilite/exercices/{$fy->id}/cloturer")
            ->assertSessionHas('success');

        $this->assertTrue($fy->fresh()->isClosed());

        $this->expectException(InvalidArgumentException::class);
        $accounting->postEntry($tenant, 'OD', 'Tentative après clôture', [
            ['account_code' => '571', 'debit' => 1000, 'credit' => 0],
            ['account_code' => '707', 'debit' => 0, 'credit' => 1000],
        ]);
    }
}
