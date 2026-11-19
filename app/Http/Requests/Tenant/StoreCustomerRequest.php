<?php

namespace App\Http\Requests\Tenant;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(['particulier', 'entreprise', 'flotte'])],
            'company_name' => ['nullable', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'credit_limit' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
