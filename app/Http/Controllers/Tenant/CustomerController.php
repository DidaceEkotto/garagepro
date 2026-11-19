<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreCustomerRequest;
use App\Mail\ClientReminderMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->with('vehicles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
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
        ]);
    }

    public function store(StoreCustomerRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $customer = Customer::create([
            ...$request->validated(),
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
}
