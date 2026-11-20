<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GlobalCustomer;

class GlobalCustomerService
{
    public function findByPhoneOrId(?string $phone, ?string $idNumber = null): ?GlobalCustomer
    {
        $cleanPhone = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $cleanPhone);

        if ($cleanPhone !== '') {
            $match = GlobalCustomer::where('whatsapp_phone', $cleanPhone)
                ->orWhere('phone', $cleanPhone)
                ->first();

            if ($match) {
                return $match;
            }

            if (strlen($digits) >= 8) {
                $tail = substr($digits, -9);
                $match = GlobalCustomer::where('whatsapp_phone', 'like', "%{$tail}")
                    ->orWhere('phone', 'like', "%{$tail}")
                    ->first();

                if ($match) {
                    return $match;
                }
            }
        }

        $cleanId = trim((string) $idNumber);
        if ($cleanId !== '') {
            return GlobalCustomer::where('id_number', $cleanId)->first();
        }

        return null;
    }

    public function syncFromLocalCustomer(Customer $customer): GlobalCustomer
    {
        if ($customer->global_customer_id) {
            $existing = GlobalCustomer::find($customer->global_customer_id);
            if ($existing) {
                return $existing;
            }
        }

        $phone = $customer->whatsapp_phone ?: $customer->phone;
        $global = $this->findByPhoneOrId($phone, $customer->id_number);

        if (! $global) {
            $global = GlobalCustomer::create([
                'name' => $customer->name,
                'first_name' => $customer->first_name,
                'gender' => $customer->gender,
                'id_number' => $customer->id_number,
                'phone_dial_code' => $customer->phone_dial_code,
                'phone' => $customer->phone,
                'whatsapp_phone' => $customer->whatsapp_phone ?: $customer->phone,
                'email' => $customer->email,
                'profession' => $customer->profession,
                'date_of_birth' => $customer->date_of_birth,
                'emergency_contact' => $customer->emergency_contact,
                'description' => $customer->description,
            ]);
        } else {
            // Compléter les champs encore vides sur le profil global
            $updates = [];
            foreach (['first_name', 'gender', 'id_number', 'phone_dial_code', 'email', 'profession', 'date_of_birth', 'emergency_contact'] as $field) {
                if (empty($global->{$field}) && ! empty($customer->{$field})) {
                    $updates[$field] = $customer->{$field};
                }
            }
            if (! empty($updates)) {
                $global->update($updates);
            }
        }

        if ((int) $customer->global_customer_id !== (int) $global->id) {
            $customer->global_customer_id = $global->id;
            $customer->saveQuietly();
        }

        return $global;
    }

    public function syncGlobalToLinkedCustomers(GlobalCustomer $global): void
    {
        Customer::withoutTenantScope()
            ->where('global_customer_id', $global->id)
            ->update([
                'name' => $global->name,
                'first_name' => $global->first_name,
                'gender' => $global->gender,
                'id_number' => $global->id_number,
                'phone_dial_code' => $global->phone_dial_code,
                'phone' => $global->phone,
                'whatsapp_phone' => $global->whatsapp_phone,
                'email' => $global->email,
                'profession' => $global->profession,
                'date_of_birth' => $global->date_of_birth,
                'emergency_contact' => $global->emergency_contact,
            ]);
    }
}
