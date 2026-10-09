<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;

class CustomerService
{
    /**
     * Find the customer behind a transfer's sender details, or register them.
     *
     * An existing customer keeps their name; blank location fields are filled
     * in from what the agent just typed, but nothing already recorded is
     * overwritten — the transfer itself keeps exactly what was entered.
     *
     * @param  array{sender_name:string,sender_phone:string,sender_country?:?string,sender_city?:?string,sender_province?:?string}  $data
     */
    public function resolve(array $data, User $by): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            ['phone' => Customer::normalizePhone($data['sender_phone'])],
            [
                'name' => $data['sender_name'],
                'display_phone' => $data['sender_phone'],
                'country' => $data['sender_country'] ?? null,
                'city' => $data['sender_city'] ?? null,
                'province' => $data['sender_province'] ?? null,
                'created_by' => $by->id,
            ],
        );

        foreach (['country', 'city', 'province'] as $field) {
            if (blank($customer->{$field}) && filled($data["sender_{$field}"] ?? null)) {
                $customer->{$field} = $data["sender_{$field}"];
            }
        }

        if ($customer->isDirty()) {
            $customer->save();
        }

        return $customer;
    }
}
