<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreCustomerRequest;
use App\Mail\ClientReminderMail;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Customer;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $this->authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('q', ''));
        $tenant = $tenantContext->getTenant();
        $defaultDialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';

        $customers = Customer::query()
            ->with('vehicles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('vehicles', function ($vq) use ($search): void {
                            $vq->where('license_plate', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Tenant/Customers/Index', [
            'customers' => $customers,
            'search' => $search,
            'defaultDialCode' => $defaultDialCode,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
        ]);
    }

    public function store(StoreCustomerRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $data = $request->validated();
        unset($data['photo']);

        $tenant = $tenantContext->getTenant();
        $dialCode = trim((string) ($data['phone_dial_code'] ?? ''));
        if ($dialCode === '') {
            $dialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';
        }
        $data['phone_dial_code'] = $dialCode;

        $rawPhone = trim((string) $data['phone']);
        $dialDigits = preg_replace('/\D+/', '', $dialCode);
        $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
        if (str_starts_with($rawPhone, '+')) {
            $formattedPhone = $rawPhone;
        } elseif ($dialDigits !== '' && str_starts_with($phoneDigits, $dialDigits)) {
            $formattedPhone = '+'.$phoneDigits;
        } else {
            $formattedPhone = $dialCode.' '.$phoneDigits;
        }

        $data['phone'] = $formattedPhone;
        $data['whatsapp_phone'] = $formattedPhone;

        if ($request->hasFile('photo')) {
            $data['photo_path'] = Storage::disk('public')->putFile('images/customers', $request->file('photo'));
        }

        $customer = Customer::create([
            ...$data,
            'tenant_id' => $tenantContext->id(),
            'agency_id' => $request->session()->get('active_agency_id', $request->user('web')->default_agency_id),
        ]);

        AuditLog::record('customer.created', subject: $customer, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Client « {$customer->name} » enregistré.");
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->load(['vehicles', 'appointments', 'receptions.vehicle']);

        return Inertia::render('Tenant/Customers/Show', [
            'customer' => $customer,
        ]);
    }

    public function remind(Request $request, Customer $customer, TenantContext $tenantContext): RedirectResponse
    {
        $this->authorize('view', $customer);

        if (empty($customer->email)) {
            return back()->with('error', "Le client « {$customer->name} » n'a pas d'adresse e-mail enregistrée.");
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $tenant = $tenantContext->getTenant();
        Mail::to($customer->email)->send(new ClientReminderMail(
            garageName: $tenant?->name ?? config('app.name', 'GaragePro'),
            clientName: $customer->name,
            subjectLine: $data['subject'],
            bodyText: $data['message'],
        ));

        AuditLog::record('customer.reminded', subject: $customer, actor: $request->user('web'), tenantId: $tenantContext->id(), request: $request);

        return back()->with('success', "Relance envoyée par e-mail à « {$customer->name} » ({$customer->email}).");
    }

    public function remindWhatsapp(
        Request $request,
        Customer $customer,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $this->authorize('view', $customer);

        $phone = $customer->whatsapp_phone ?: $customer->phone;
        if (empty($phone)) {
            return back()->with('error', "Le client « {$customer->name} » n'a pas de numéro WhatsApp enregistré.");
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $res = $greenApi->sendMessage($phone, $data['message']);
        if (! $res['ok']) {
            return back()->with('error', $res['error'] ?? 'Impossible d\'envoyer le message WhatsApp.');
        }

        AuditLog::record(
            action: 'customer.reminded_whatsapp',
            subject: $customer,
            actor: $request->user('web'),
            tenantId: $tenantContext->id(),
            metadata: ['phone' => $phone],
            request: $request
        );

        return back()->with('success', "Message WhatsApp envoyé à « {$customer->name} » ({$phone}).");
    }
}
