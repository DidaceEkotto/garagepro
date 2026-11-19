<?php

namespace App\Http\Requests\Tenant;

use App\Models\RepairOrder;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRepairOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', RepairOrder::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'reception_id' => ['nullable', 'integer', Rule::exists('vehicle_receptions', 'id')->where('tenant_id', $tenantId)],
            'assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'priority' => ['nullable', Rule::in(['normale', 'urgente'])],
            'diagnostic_action' => ['nullable', Rule::in(['start', 'finish'])],
            'fault_codes' => ['nullable', 'array'],
            'fault_codes.*' => ['string', 'max:50'],
            'diagnostic_notes' => ['nullable', 'string', 'max:3000'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.bay_name' => ['nullable', 'string', 'max:60'],
            'tasks.*.estimated_minutes' => ['required', 'integer', 'min:10', 'max:2400'],
            'tasks.*.assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'tasks.*.priority' => ['nullable', Rule::in(['normale', 'haute', 'urgente'])],
        ];
    }
}
