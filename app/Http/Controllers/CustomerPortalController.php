<?php

namespace App\Http\Controllers;

use App\Mail\CustomerProfileUpdatedMail;
use App\Models\Country;
use App\Models\Customer;
use App\Models\GlobalCustomer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\GlobalCustomerService;
use App\Services\GreenApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function index(Request $request): Response
    {
        $countries = Country::active()
            ->orderBy('name')
            ->get(['code', 'name', 'dial_code'])
            ->map(fn (Country $c) => [
                'code' => $c->code,
                'name' => $c->name,
                'dial_code' => $c->dial_code,
            ])
            ->values()
            ->all();

        if ($countries === []) {
            $countries = Country::defaultAfricanCountries();
        }

        $globalId = $request->session()->get('global_customer_id');
        $global = $globalId ? GlobalCustomer::find($globalId) : null;

        if (! $global) {
            return Inertia::render('Public/CustomerPortal', [
                'authenticated' => false,
                'otpPendingPhone' => $request->session()->get('portal_otp_phone'),
                'otpPendingDialCode' => $request->session()->get('portal_otp_dial_code', '+237'),
                'countries' => $countries,
            ]);
        }

        $linkedCustomers = Customer::withoutTenantScope()
            ->with([
                'tenant:id,name,slug,phone,city,type',
                'vehicles:id,tenant_id,customer_id,license_plate,brand,model,year,mileage,public_token,next_service_date,technical_inspection_expires_at,insurance_expires_at',
                'appointments' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('scheduled_at')->take(15),
                'repairOrders' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('id')->take(20),
                'invoices' => fn ($q) => $q->with('vehicle:id,license_plate')->latest('id')->take(20),
            ])
            ->where('global_customer_id', $global->id)
            ->get();

        $garages = $linkedCustomers->map(fn (Customer $c) => [
            'id' => $c->tenant?->id,
            'name' => $c->tenant?->name ?? 'Établissement',
            'city' => $c->tenant?->city,
            'phone' => $c->tenant?->phone,
            'tenant_type' => $c->tenant?->type ?? 'garage',
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
                'opened_at' => $ro->created_at?->format('d/m/Y'),
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

        $customerIds = $linkedCustomers->pluck('id')->all();
        $quotes = Quote::withoutTenantScope()
            ->with(['tenant:id,name', 'vehicle:id,license_plate'])
            ->whereIn('customer_id', $customerIds)
            ->latest('id')
            ->take(25)
            ->get()
            ->map(function (Quote $q) {
                $token = $q->ensurePublicToken();

                return [
                    'id' => $q->id,
                    'garage_name' => $q->tenant?->name ?? 'Garage',
                    'quote_number' => $q->quote_number,
                    'version' => (int) $q->version,
                    'vehicle_plate' => $q->vehicle?->license_plate,
                    'total_ttc' => (int) $q->total_ttc,
                    'status' => $q->status,
                    'public_token' => $token,
                    'public_url' => route('public.quote.show', ['token' => $token]),
                    'valid_until' => $q->valid_until?->format('d/m/Y'),
                    'signed_at' => $q->signed_at?->format('d/m/Y H:i'),
                    'rejection_reason' => $q->rejection_reason,
                ];
            })
            ->values();

        return Inertia::render('Public/CustomerPortal', [
            'authenticated' => true,
            'countries' => $countries,
            'profile' => [
                'id' => $global->id,
                'name' => $global->name,
                'first_name' => $global->first_name,
                'gender' => $global->gender ?? 'homme',
                'id_number' => $global->id_number,
                'phone_dial_code' => $global->phone_dial_code ?: '+237',
                'phone' => $global->phone,
                'whatsapp_phone' => $global->whatsapp_phone,
                'photo_url' => $global->photoUrl(),
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
            'quotes' => $quotes,
            'invoices' => $invoices,
        ]);
    }

    private function combineDialAndPhone(string $rawPhone, ?string $dialCode): string
    {
        $trimmed = trim($rawPhone);
        if (str_starts_with($trimmed, '+')) {
            return preg_replace('/[^\d+]/', '', $trimmed) ?: $trimmed;
        }

        $cleanDial = trim((string) $dialCode);
        if ($cleanDial !== '') {
            $cleanDial = '+'.ltrim(preg_replace('/\D+/', '', $cleanDial), '+');
            $localDigits = preg_replace('/\D+/', '', $trimmed);
            $dialDigits = ltrim($cleanDial, '+');
            if ($dialDigits !== '' && str_starts_with($localDigits, $dialDigits) && strlen($localDigits) > strlen($dialDigits) + 5) {
                $localDigits = substr($localDigits, strlen($dialDigits));
            }

            return $cleanDial.$localDigits;
        }

        return $trimmed;
    }

    public function requestOtp(
        Request $request,
        GlobalCustomerService $service,
        GreenApiService $greenApi
    ): RedirectResponse {
        $validated = $request->validate([
            'phone_dial_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
        ]);

        $dialCode = $validated['phone_dial_code'] ?? '+237';
        $fullPhone = $this->combineDialAndPhone($validated['phone'], $validated['phone_dial_code'] ?? null);

        $global = $service->findByPhoneOrId($fullPhone);
        $request->session()->put('portal_otp_phone', $fullPhone);
        $request->session()->put('portal_otp_dial_code', $dialCode);

        if ($global) {
            $code = (string) random_int(10000, 99999);
            $global->update([
                'otp_code' => Hash::make($code),
                'otp_expires_at' => now()->addMinutes(5),
            ]);
            Cache::forget("portal_otp_attempts:{$global->id}");

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
        }

        // Réponse volontairement générique pour empêcher l'énumération des numéros clients
        return back()->with('success', 'Si un compte client MboaCar correspond à ce numéro, un code de connexion à 5 chiffres vient d\'être envoyé sur WhatsApp (valable 5 minutes).');
    }

    public function verifyOtp(Request $request, GlobalCustomerService $service): RedirectResponse
    {
        $validated = $request->validate([
            'phone_dial_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'code' => ['required', 'string', 'size:5'],
        ]);

        $fullPhone = $this->combineDialAndPhone($validated['phone'], $validated['phone_dial_code'] ?? null);

        $global = $service->findByPhoneOrId($fullPhone);
        if (! $global || ! $global->otp_code || ! $global->otp_expires_at?->isFuture()) {
            return back()->withErrors([
                'code' => 'Code invalide ou expiré. Veuillez demander un nouveau code.',
            ]);
        }

        $attemptsKey = "portal_otp_attempts:{$global->id}";
        $attempts = (int) Cache::get($attemptsKey, 0);

        if ($attempts >= 5) {
            $global->update(['otp_code' => null, 'otp_expires_at' => null]);
            Cache::forget($attemptsKey);

            return back()->withErrors([
                'code' => 'Trop de tentatives incorrectes. Veuillez demander un nouveau code.',
            ]);
        }

        if (! Hash::check($validated['code'], $global->otp_code)) {
            $newAttempts = $attempts + 1;
            Cache::put($attemptsKey, $newAttempts, now()->addMinutes(5));
            if ($newAttempts >= 5) {
                $global->update(['otp_code' => null, 'otp_expires_at' => null]);
                Cache::forget($attemptsKey);
            }

            return back()->withErrors([
                'code' => 'Code invalide ou expiré. Veuillez demander un nouveau code.',
            ]);
        }

        Cache::forget($attemptsKey);

        $global->update([
            'otp_code' => null,
            'otp_expires_at' => null,
            'last_login_at' => now(),
        ]);

        // Régénération de session contre la fixation de session
        $request->session()->regenerate();
        $request->session()->put('global_customer_id', $global->id);
        $request->session()->forget(['portal_otp_phone', 'portal_otp_dial_code']);

        return back()->with('success', 'Connexion réussie à votre Espace Client MboaCar.');
    }

    public function updateProfile(
        Request $request,
        GlobalCustomerService $service,
        GreenApiService $greenApi
    ): RedirectResponse {
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
            'phone_dial_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'min:6', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'profession' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_photo' => ['nullable', 'boolean'],
        ]);

        $updateData = [
            'name' => $validated['name'],
            'first_name' => $validated['first_name'] ?? null,
            'gender' => $validated['gender'],
            'id_number' => $validated['id_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'profession' => $validated['profession'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'emergency_contact' => $validated['emergency_contact'] ?? null,
            'description' => $validated['description'] ?? null,
        ];

        // Mise à jour optionnelle du téléphone WhatsApp avec indicatif
        if (! empty($validated['phone'])) {
            $dialCode = trim((string) ($validated['phone_dial_code'] ?? $global->phone_dial_code ?? '+237'));
            if ($dialCode !== '') {
                $dialCode = '+'.ltrim(preg_replace('/\D+/', '', $dialCode), '+');
            }
            $fullPhone = $this->combineDialAndPhone($validated['phone'], $dialCode ?: null);

            $conflict = GlobalCustomer::where('id', '!=', $global->id)
                ->where(function ($q) use ($fullPhone) {
                    $q->where('whatsapp_phone', $fullPhone)
                        ->orWhere('phone', $fullPhone);
                })
                ->exists();

            if ($conflict) {
                return back()->withErrors([
                    'phone' => 'Ce numéro WhatsApp est déjà utilisé par un autre compte client.',
                ]);
            }

            $updateData['phone_dial_code'] = $dialCode ?: ($global->phone_dial_code ?: '+237');
            $updateData['phone'] = $fullPhone;
            $updateData['whatsapp_phone'] = $fullPhone;
        }

        // Gestion de la photo de profil (dropzone)
        $photoUpdated = false;
        if ($request->hasFile('photo')) {
            if ($global->photo_path && Storage::disk('public')->exists($global->photo_path)) {
                Storage::disk('public')->delete($global->photo_path);
            }
            $updateData['photo_path'] = $request->file('photo')->store('customers/avatars', 'public');
            $photoUpdated = true;
        } elseif ($request->boolean('remove_photo')) {
            if ($global->photo_path && Storage::disk('public')->exists($global->photo_path)) {
                Storage::disk('public')->delete($global->photo_path);
            }
            $updateData['photo_path'] = null;
        }

        $global->update($updateData);
        $hasRealChanges = $global->wasChanged([
            'name', 'first_name', 'gender', 'id_number', 'phone_dial_code',
            'phone', 'whatsapp_phone', 'photo_path', 'email', 'profession',
            'date_of_birth', 'emergency_contact', 'description',
        ]) || $photoUpdated;

        $global->refresh();
        $service->syncGlobalToLinkedCustomers($global);

        // Notifier par Email et WhatsApp UNIQUEMENT si des informations ont réellement changé,
        // avec cooldown anti-spam de 5 minutes, et uniquement aux établissements où il est client.
        if ($hasRealChanges && Cache::add("portal_profile_notify:{$global->id}", true, now()->addMinutes(5))) {
            $this->notifyLinkedEstablishments($global, $greenApi, $photoUpdated);
        }

        return back()->with('success', 'Votre profil unique MboaCar a été mis à jour et synchronisé avec vos établissements.');
    }

    private function notifyLinkedEstablishments(
        GlobalCustomer $global,
        GreenApiService $greenApi,
        bool $photoUpdated
    ): void {
        $linkedTenants = Customer::withoutTenantScope()
            ->where('global_customer_id', $global->id)
            ->with('tenant:id,name,type,email,phone')
            ->get()
            ->pluck('tenant')
            ->filter(fn ($tenant) => $tenant instanceof Tenant)
            ->unique('id')
            ->values();

        if ($linkedTenants->isEmpty()) {
            return;
        }

        $fullName = trim(($global->first_name ? $global->first_name.' ' : '').$global->name);
        $phoneDisplay = $global->whatsapp_phone ?: $global->phone ?: 'Non renseigné';
        $emailDisplay = $global->email ?: 'Non renseigné';

        foreach ($linkedTenants as $tenant) {
            if (! empty($tenant->email)) {
                try {
                    Mail::to($tenant->email)->send(new CustomerProfileUpdatedMail($tenant, $global, $photoUpdated));
                } catch (\Throwable $e) {
                    Log::warning('CustomerPortal profile update email failed (non-blocking)', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($greenApi->isConfigured() && ! empty($tenant->phone)) {
                try {
                    $typeLabel = match ($tenant->type) {
                        Tenant::TYPE_TECHNICAL_CENTER => 'Centre de Visite Technique',
                        Tenant::TYPE_INSURANCE => 'Assurance',
                        default => 'Garage',
                    };
                    $msg = "MboaCar ({$typeLabel}) : votre client {$fullName} vient de mettre à jour son profil unique.\n"
                        ."• Téléphone WhatsApp : {$phoneDisplay}\n"
                        ."• E-mail : {$emailDisplay}\n"
                        .($photoUpdated ? "• Photo de profil : mise à jour\n" : '')
                        ."Sa fiche client chez {$tenant->name} a été synchronisée automatiquement.";

                    $greenApi->sendMessage($tenant->phone, $msg);
                } catch (\Throwable $e) {
                    Log::warning('CustomerPortal profile update WhatsApp failed (non-blocking)', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['global_customer_id', 'portal_otp_phone', 'portal_otp_dial_code']);

        return back()->with('success', 'Vous êtes déconnecté de l\'Espace Client.');
    }
}
