<?php

namespace Database\Seeders;

use App\Models\AfterSalesCase;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Part;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccountingService;
use App\Services\SyscohadaSeederService;
use App\Services\TenantRoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Jeu de données de démonstration complet (sans aucun secret ni donnée sensible).
 */
class DemoSeeder extends Seeder
{
    public function run(
        TenantRoleService $roleService,
        SyscohadaSeederService $syscohada,
        AccountingService $accounting
    ): void {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-douala'],
            [
                'name' => 'Garage Excellence Douala (Démo)',
                'email' => 'demo@garagepro.app',
                'city' => 'Douala',
                'status' => Tenant::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays(7),
            ]
        );

        $agencyMain = Agency::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'AG01'],
            ['name' => 'Agence Bonanjo', 'city' => 'Douala', 'is_main' => true, 'is_active' => true]
        );

        $agencySecond = Agency::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'AG02'],
            ['name' => 'Agence Bastos', 'city' => 'Yaoundé', 'is_main' => false, 'is_active' => true]
        );

        $roleService->provisionForTenant($tenant);
        $syscohada->provisionForTenant($tenant);

        setPermissionsTeamId($tenant->id);
        $user = User::withoutTenantScope()->firstOrCreate(
            ['email' => 'gerant-demo@garagepro.app'],
            [
                'tenant_id' => $tenant->id,
                'default_agency_id' => $agencyMain->id,
                'name' => 'Gérant Démo',
                'password' => Hash::make(Str::random(24)),
                'is_active' => true,
            ]
        );
        $user->assignRole('gerant');
        $user->agencies()->syncWithoutDetaching([
            $agencyMain->id => ['tenant_id' => $tenant->id],
            $agencySecond->id => ['tenant_id' => $tenant->id],
        ]);

        $customer = Customer::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'phone' => '699000001'],
            ['agency_id' => $agencyMain->id, 'name' => 'Jean-Paul Mbarga', 'type' => 'particulier', 'city' => 'Douala']
        );

        $vehicle = Vehicle::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'license_plate' => 'LT 458 CM'],
            ['customer_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Hilux', 'year' => 2021, 'mileage' => 68500]
        );

        Appointment::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'reason' => 'entretien'],
            [
                'agency_id' => $agencyMain->id,
                'vehicle_id' => $vehicle->id,
                'bay_name' => 'Pont 1',
                'channel' => 'whatsapp',
                'scheduled_at' => now()->addHours(2),
                'status' => Appointment::STATUS_CONFIRMED,
                'notes' => 'Vidange complète 70 000 km',
            ]
        );

        $order = RepairOrder::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'order_number' => 'OR-2026-00001'],
            [
                'agency_id' => $agencyMain->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'assigned_mechanic_id' => $user->id,
                'status' => RepairOrder::STATUS_IN_PROGRESS,
                'priority' => 'normale',
                'subtotal_ht' => 85000,
                'vat_amount' => 16363,
                'total_ttc' => 101363,
            ]
        );

        RepairTask::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'repair_order_id' => $order->id, 'title' => 'Remplacement plaquettes de frein avant'],
            [
                'assigned_mechanic_id' => $user->id,
                'bay_name' => 'Pont 1',
                'estimated_minutes' => 90,
                'actual_minutes' => 45,
                'status' => 'en_cours',
            ]
        );

        $quote = Quote::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'quote_number' => 'DEV-2026-00001', 'version' => 1],
            [
                'agency_id' => $agencyMain->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'repair_order_id' => $order->id,
                'status' => Quote::STATUS_ACCEPTED,
                'valid_until' => now()->addDays(15),
                'vat_rate' => 19.25,
                'subtotal_ht' => 85000,
                'vat_amount' => 16363,
                'total_ttc' => 101363,
            ]
        );

        QuoteItem::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'quote_id' => $quote->id, 'description' => 'Jeu de plaquettes avant Toyota'],
            [
                'type' => 'piece',
                'part_condition' => 'neuve',
                'urgency' => 'urgent',
                'quantity' => 1,
                'unit_price' => 55000,
                'total_price' => 55000,
                'is_accepted' => true,
            ]
        );

        Supplier::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'CFAO Motors Cameroun'],
            ['city' => 'Douala', 'phone' => '233420000', 'lead_time_days' => 2]
        );

        Part::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'agency_id' => $agencyMain->id, 'sku' => 'DEMO-FIL-01'],
            [
                'name' => 'Filtre à huile Toyota',
                'category' => 'piece',
                'condition' => 'neuve',
                'location' => 'Rayon A1',
                'quantity_on_hand' => 15,
                'min_threshold' => 3,
                'purchase_price' => 3500,
                'selling_price' => 6500,
            ]
        );

        $invoice = Invoice::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_number' => 'FAC-2026-00001'],
            [
                'agency_id' => $agencyMain->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'repair_order_id' => $order->id,
                'type' => Invoice::TYPE_INVOICE,
                'status' => Invoice::STATUS_ISSUED,
                'vat_rate' => 19.25,
                'subtotal_ht' => 85000,
                'vat_amount' => 16363,
                'total_ttc' => 101363,
                'paid_amount' => 0,
                'issued_at' => now(),
            ]
        );

        InvoiceItem::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'description' => 'Main d\'œuvre & freinage'],
            ['type' => 'main_oeuvre', 'quantity' => 1, 'unit_price' => 85000, 'total_price' => 85000]
        );

        if ($invoice->wasRecentlyCreated) {
            $accounting->recordInvoiceEntry($invoice);
        }

        Employee::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'full_name' => 'Moussa Bello'],
            [
                'agency_id' => $agencyMain->id,
                'role_title' => 'Chef d\'atelier',
                'phone' => '677000002',
                'base_salary' => 220000,
                'commission_rate' => 5.0,
                'is_active' => true,
            ]
        );

        AfterSalesCase::withoutTenantScope()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'type' => 'satisfaction'],
            [
                'agency_id' => $agencyMain->id,
                'vehicle_id' => $vehicle->id,
                'rating' => 5,
                'status' => 'resolu',
                'description' => 'Client très satisfait de la rapidité de prise en charge.',
            ]
        );
    }
}
