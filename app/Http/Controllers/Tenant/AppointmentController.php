<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreAppointmentRequest;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\AppointmentLink;
use App\Models\Country;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentReminderService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $tenant = $tenantContext->getTenant();
        $defaultDialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';

        $appointments = Appointment::with(['customer', 'vehicle', 'mechanic', 'agency'])
            ->latest('id')
            ->paginate(30);

        // Expirer automatiquement les liens actifs dépassant 24h
        AppointmentLink::where('status', AppointmentLink::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update(['status' => AppointmentLink::STATUS_EXPIRED]);

        $appointmentLinks = AppointmentLink::with(['agency:id,name', 'customer:id,name,phone', 'appointment:id,status,scheduled_at'])
            ->latest('id')
            ->take(30)
            ->get()
            ->map(function (AppointmentLink $link) {
                return [
                    'id' => $link->id,
                    'token' => $link->token,
                    'public_url' => route('public.appointment.show', ['token' => $link->token]),
                    'label' => $link->label,
                    'status' => $link->status,
                    'expires_at' => $link->expires_at?->toIso8601String(),
                    'expires_at_formatted' => $link->expires_at?->format('d/m/Y H:i'),
                    'submitted_at_formatted' => $link->submitted_at?->format('d/m/Y H:i'),
                    'confirmed_at_formatted' => $link->confirmed_at?->format('d/m/Y H:i'),
                    'agency_id' => $link->agency_id,
                    'agency' => $link->agency,
                    'customer' => $link->customer,
                    'submitted_payload' => $link->submitted_payload,
                ];
            });

        return Inertia::render('Tenant/Appointments/Index', [
            'appointments' => $appointments,
            'appointmentLinks' => $appointmentLinks,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::orderBy('license_plate')->get(['id', 'customer_id', 'license_plate', 'brand', 'model']),
            'mechanics' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultDialCode' => $defaultDialCode,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
        ]);
    }

    public function store(StoreAppointmentRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $data = $request->validated();

        $appointment = DB::transaction(function () use ($data, $tenantContext, $request) {
            $customerId = $data['customer_id'] ?? null;

            // Création automatique de la fiche client si nouveau client
            if (! $customerId) {
                $tenant = $tenantContext->getTenant();
                $dialCode = trim((string) ($data['new_customer_dial_code'] ?? ''));
                if ($dialCode === '') {
                    $dialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';
                }

                $rawPhone = trim((string) $data['new_customer_phone']);
                $dialDigits = preg_replace('/\D+/', '', $dialCode);
                $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
                if (str_starts_with($rawPhone, '+')) {
                    $formattedPhone = $rawPhone;
                } elseif ($dialDigits !== '' && str_starts_with($phoneDigits, $dialDigits)) {
                    $formattedPhone = '+'.$phoneDigits;
                } else {
                    $formattedPhone = $dialCode.' '.$phoneDigits;
                }

                $photoPath = $request->hasFile('new_customer_photo')
                    ? Storage::disk('public')->putFile('images/customers', $request->file('new_customer_photo'))
                    : null;

                $customer = Customer::create([
                    'tenant_id' => $tenantContext->id(),
                    'agency_id' => $data['agency_id'],
                    'name' => $data['new_customer_name'],
                    'first_name' => $data['new_customer_first_name'] ?? null,
                    'gender' => $data['new_customer_gender'] ?? null,
                    'id_number' => $data['new_customer_id_number'] ?? null,
                    'phone_dial_code' => $dialCode,
                    'phone' => $formattedPhone,
                    'whatsapp_phone' => $formattedPhone,
                    'photo_path' => $photoPath,
                    'description' => $data['new_customer_description'] ?? null,
                    'profession' => $data['new_customer_profession'] ?? null,
                    'date_of_birth' => $data['new_customer_date_of_birth'] ?? null,
                    'emergency_contact' => $data['new_customer_emergency_contact'] ?? null,
                    'type' => 'particulier',
                ]);
                $customerId = $customer->id;
            }

            $status = $data['channel'] === 'sans_rdv'
                ? Appointment::STATUS_WALK_IN_QUEUE
                : Appointment::STATUS_CONFIRMED;

            return Appointment::create([
                'tenant_id' => $tenantContext->id(),
                'agency_id' => $data['agency_id'],
                'customer_id' => $customerId,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'assigned_mechanic_id' => $data['assigned_mechanic_id'] ?? null,
                'bay_name' => $data['bay_name'] ?? null,
                'channel' => $data['channel'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? 60,
                'status' => $status,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        if ($appointment->status === Appointment::STATUS_CONFIRMED && $appointment->scheduled_at) {
            app()->terminating(function () {
                app(AppointmentReminderService::class)->triggerAutomaticCheck();
            });
        }

        return back()->with('success', 'Rendez-vous enregistré avec succès.');
    }

    public function sendWhatsappReminder(Request $request, Appointment $appointment, AppointmentReminderService $reminderService): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $validated = $request->validate([
            'custom_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $appointment->loadMissing(['customer', 'vehicle', 'tenant']);
        $sent = $reminderService->sendSingleReminder(
            $appointment,
            '2h',
            app()->getLocale(),
            $validated['custom_message'] ?? null
        );

        if (! $sent) {
            return back()->with('warning', 'Le message WhatsApp n\'a pas pu être remis pour le moment (le rendez-vous reste actif).');
        }

        $appointment->update(['reminder_sent_at' => now()]);

        return back()->with('success', 'Relance WhatsApp envoyée au client.');
    }

    public function generateLink(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'label' => ['nullable', 'string', 'max:150'],
        ]);

        $agencyId = $validated['agency_id'] ?? Agency::where('is_active', true)->value('id');

        AppointmentLink::create([
            'tenant_id' => $tenantId,
            'agency_id' => $agencyId,
            'created_by' => $request->user('web')?->id,
            'token' => bin2hex(random_bytes(32)),
            'label' => $validated['label'] ?? null,
            'status' => AppointmentLink::STATUS_ACTIVE,
            'expires_at' => now()->addDay(),
        ]);

        return back()->with('success', 'Lien de rendez-vous sécurisé généré (valable 24h, usage unique).');
    }

    public function confirmLinkSubmission(
        Request $request,
        AppointmentLink $appointmentLink,
        TenantContext $tenantContext,
        AppointmentReminderService $reminderService
    ): RedirectResponse {
        $this->authorize('create', Appointment::class);

        if ($appointmentLink->status !== AppointmentLink::STATUS_SUBMITTED || empty($appointmentLink->submitted_payload)) {
            return back()->withErrors(['link' => 'Ce lien n\'est pas en attente de validation ou a déjà été confirmé.']);
        }

        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'create_customer' => ['boolean'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'bay_name' => ['nullable', 'string', 'max:60'],
            'assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment = DB::transaction(function () use ($appointmentLink, $validated, $tenantId) {
            $payload = $appointmentLink->submitted_payload;
            $customerId = $validated['customer_id'] ?? null;

            if (! $customerId && ($validated['create_customer'] ?? true)) {
                $customer = Customer::create([
                    'tenant_id' => $tenantId,
                    'agency_id' => $validated['agency_id'],
                    'name' => $payload['name'] ?? 'Client',
                    'first_name' => $payload['first_name'] ?? null,
                    'gender' => $payload['gender'] ?? 'homme',
                    'id_number' => $payload['id_number'] ?? null,
                    'phone_dial_code' => $payload['dial_code'] ?? '+237',
                    'phone' => $payload['phone'] ?? '',
                    'whatsapp_phone' => $payload['phone'] ?? '',
                    'description' => $payload['description'] ?? null,
                    'profession' => $payload['profession'] ?? null,
                    'date_of_birth' => $payload['date_of_birth'] ?? null,
                    'emergency_contact' => $payload['emergency_contact'] ?? null,
                    'type' => 'particulier',
                ]);
                $customerId = $customer->id;
            }

            if (! $customerId) {
                abort(422, 'Veuillez choisir un client existant ou activer la création du compte client.');
            }

            $vehicleId = null;
            if (! empty($payload['vehicle_plate'])) {
                $plate = strtoupper(trim((string) $payload['vehicle_plate']));
                $vehicle = Vehicle::firstOrCreate(
                    ['tenant_id' => $tenantId, 'license_plate' => $plate],
                    [
                        'customer_id' => $customerId,
                        'brand' => $payload['vehicle_brand'] ?? null,
                        'model' => $payload['vehicle_model'] ?? null,
                    ]
                );
                $vehicleId = $vehicle->id;
            }

            $appointment = Appointment::create([
                'tenant_id' => $tenantId,
                'agency_id' => $validated['agency_id'],
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'assigned_mechanic_id' => $validated['assigned_mechanic_id'] ?? null,
                'bay_name' => $validated['bay_name'] ?? null,
                'channel' => 'en_ligne',
                'scheduled_at' => $validated['scheduled_at'],
                'duration_minutes' => $validated['duration_minutes'] ?? 60,
                'status' => Appointment::STATUS_CONFIRMED,
                'reason' => $payload['reason'] ?? 'entretien',
                'notes' => $validated['notes'] ?? ($payload['notes'] ?? null),
            ]);

            $appointmentLink->update([
                'status' => AppointmentLink::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'appointment_id' => $appointment->id,
            ]);

            return $appointment;
        });

        $appointment->loadMissing(['customer', 'vehicle', 'tenant']);
        $whatsappSent = $reminderService->sendConfirmationMessage($appointment, app()->getLocale());

        $msg = $whatsappSent
            ? 'Rendez-vous confirmé, compte client créé et message WhatsApp de confirmation envoyé ! Le lien est désormais désactivé.'
            : 'Rendez-vous confirmé et compte client créé ! Le lien est désormais désactivé (WhatsApp non remis).';

        return back()->with('success', $msg);
    }

    public function cancelLink(AppointmentLink $appointmentLink): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        if ($appointmentLink->status === AppointmentLink::STATUS_ACTIVE) {
            $appointmentLink->update(['status' => AppointmentLink::STATUS_CANCELLED]);
        }

        return back()->with('success', 'Lien de rendez-vous désactivé.');
    }

    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_RECEIVED,
                Appointment::STATUS_RESCHEDULED,
                Appointment::STATUS_CANCELLED,
            ])],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $appointment->update([
            'status' => $validated['status'],
            'scheduled_at' => $validated['scheduled_at'] ?? $appointment->scheduled_at,
        ]);

        return back()->with('success', 'Statut du rendez-vous mis à jour.');
    }
}
