<?php

namespace App\Http\Requests\Tenant;

use App\Models\Quote;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Quote::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'repair_order_id' => ['nullable', 'integer', Rule::exists('repair_orders', 'id')->where('tenant_id', $tenantId)],
            'valid_until' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'deposit_required' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['main_oeuvre', 'piece', 'consommable', 'sous_traitance'])],
            'items.*.part_condition' => ['nullable', Rule::in(['neuve', 'occasion', 'adaptable'])],
            'items.*.urgency' => ['required', Rule::in(['urgent', 'recommande', 'optionnel'])],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ];
    }
}
