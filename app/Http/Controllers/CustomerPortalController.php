<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\GlobalCustomer;
use App\Services\GlobalCustomerService;
use App\Services\GreenApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function index(Request $request): Response
    {
        $globalId = $request->session()->get('global_customer_id');
        $global = $globalId ? GlobalCustomer::find($globalId) : null;

        if (! $global) {
            return Inertia::render('Public/CustomerPortal', [
                'authenticated' => false,
                'otpPendingPhone' => $request->session()->get('portal_otp_phone'),
            ]);
        }

        $linkedCustomers = Customer::withoutTenantScope()
            ->with([
                'tenant:id,name,slug,phone,city',
                'vehicles:id,tenant_id,customer_id,license_plate,brand,model,year,mileage,public_token,next_service_date,technical_inspection_expires_at,insurance_expires_at',
                'appointments' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('scheduled_at')->take(15),
                'repairOrders' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('id')->take(20),
                'invoices' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('id')->take(20),
            ])
            ->where('global_customer_id', $global->id)
            ->get();

        $garages = $linkedCustomers->map(fn (Customer $c) => [
            'id' => $c->tenant?->id,
            'name' => $c->tenant?->name ?? 'Garage',
            'city' => $c->tenant?->city,
            'phone' => $c->tenant?->phone,
        ])->filter(fn ($g) => $g['id'] !== null)->unique('id')->values();

        $vehicles = $linkedCustomers->flatMap(function (Customer $c) {
            $garageName = $c->tenant?->name ?? 'Garage';

            return $c->vehicles->map(fn ($v) => [
                'id' => $v->id,
                'garage_name' => $garageName,
                'license_plate' => $v->license_plate,
                'brand' => $v->brand,
                'model' => $v->model,
                'year' => $v->year,
                'mileage' => $v->mileage,
                'public_token' => $v->public_token,
                'next_service_date' => $v->next_service_date?->format('d/m/Y'),
                'technical_inspection_expires_at' => $v->technical_inspection_expires_at?->format('d/m/Y'),
                'insurance_expires_at' => $v->insurance_expires_at?->format('d/m/Y'),
            ]);
        })->values();

        $appointments = $linkedCustomers->flatMap(function (Customer $c) {
            $garageName = $c->tenant?->name ?? 'Garage';

            return $c->appointments->map(fn ($a) => [
                'id' => $a->id,
                'garage_name' => $garageName,
                'vehicle_plate' => $a->vehicle?->license_plate,
                'reason' => $a->reason,
                'status' => $a->status,
                'scheduled_at' => $a->scheduled_at?->format('d/m/Y H:i') ?? 'File sans RDV',
            ]);
        })->values();

        $repairOrders = $linkedCustomers->flatMap(function (Customer $c) {
            $garageName = $c->tenant?->name ?? 'Garage';

            return $c->repairOrders->map(fn ($ro) => [
                'id' => $ro->id,
                'garage_name' => $garageName,
                'order_number' => $ro->order_number,
                'vehicle_plate' => $ro->vehicle?->license_plate,
                'status' => $ro->status,
                'public_token' => $ro->public_token,
                'opened_at' => $ro->opened_at?->format('d/m/Y'),
            ]);
        })->values();

        $invoices = $linkedCustomers->flatMap(function (Customer $c) {
            $garageName = $c->tenant?->name ?? 'Garage';

            return $c->invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'garage_name' => $garageName,
                'invoice_number' => $inv->invoice_number,
                'vehicle_plate' => $inv->vehicle?->license_plate,
                'total_ttc' => (int) $inv->total_ttc,
                'paid_amount' => (int) $inv->paid_amount,
                'status' => $inv->status,
                'issued_at' => $inv->issued_at?->format('d/m/Y'),
            ]);
        })->values();

        return Inertia::render('Public/CustomerPortal', [
            'authenticated' => true,
            'profile' => [
                'id' => $global->id,
                'name' => $global->name,
                'first_name' => $global->first_name,
                'gender' => $global->gender ?? 'homme',
                'id_number' => $global->id_number,
                'phone_dial_code' => $global->phone_dial_code,
                'phone' => $global->phone,
                'whatsapp_phone' => $global->whatsapp_phone,
                'email' => $global->email,
                'profession' => $global->profession,
                'date_of_birth' => $global->date_of_birth?->format('Y-m-d'),
                'emergency_contact' => $global->emergency_contact,
                'description' => $global->description,
            ],
            'garages' => $garages,
            'vehicles' => $vehicles,
            'appointments' => $appointments,
            'repairOrders' => $repairOrders,
            'invoices' => $invoices,
        ]);
    }

    public function requestOtp(
        Request $request,
        GlobalCustomerService $service,
        GreenApiService $greenApi
    ): RedirectResponse {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:50'],
        ]);

        $global = $service->findByPhoneOrId($validated['phone']);
        if (! $global) {
            return back()->withErrors([
                'phone' => 'Aucun compte client MboaCar trouvé avec ce numéro WhatsApp.',
            ]);
        }

        $code = (string) random_int(10000, 99999);
        $global->update([
            'otp_code' => $code,
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        $targetPhone = $global->whatsapp_phone ?: $global->phone;

        try {
            if ($greenApi->isConfigured() && $targetPhone) {
                $greenApi->sendMessage(
                    $targetPhone,
                    "MboaCar Espace Client : votre code de connexion sécurisé est {$code} (valable 5 minutes)."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('CustomerPortal OTP WhatsApp failed (non-blocking)', ['error' => $e->getMessage()]);
        }

        $request->session()->put('portal_otp_phone', $validated['phone']);

        return back()->with('success', 'Code de connexion à 5 chiffres envoyé sur votre WhatsApp (valable 5 minutes).');
    }

    public function verifyOtp(Request $request, GlobalCustomerService $service): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'code' => ['required', 'string', 'size:5'],
        ]);

        $global = $service->findByPhoneOrId($validated['phone']);
        if (! $global || ! $global->otp_code || ! $global->otp_expires_at?->isFuture() || $global->otp_code !== $validated['code']) {
            return back()->withErrors([
                'code' => 'Code invalide ou expiré. Veuillez demander un nouveau code.',
            ]);
        }

        $global->update([
            'otp_code' => null,
            'otp_expires_at' => null,
            'last_login_at' => now(),
        ]);

        $request->session()->put('global_customer_id', $global->id);
        $request->session()->forget('portal_otp_phone');

        return back()->with('success', 'Connexion réussie à votre Espace Client MboaCar.');
    }

    public function updateProfile(Request $request, GlobalCustomerService $service): RedirectResponse
    {
        $globalId = $request->session()->get('global_customer_id');
        $global = $globalId ? GlobalCustomer::find($globalId) : null;
        if (! $global) {
            return back()->withErrors(['auth' => 'Session expirée, veuillez vous reconnecter.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['homme', 'femme', 'autre'])],
            'id_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'profession' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $global->update($validated);
        $service->syncGlobalToLinkedCustomers($global);

        return back()->with('success', 'Votre profil unique MboaCar a été mis à jour et synchronisé avec tous vos garages.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['global_customer_id', 'portal_otp_phone']);

        return back()->with('success', 'Vous êtes déconnecté de l\'Espace Client.');
    }
}
