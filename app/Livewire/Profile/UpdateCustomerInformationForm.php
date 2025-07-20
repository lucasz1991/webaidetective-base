<?php

namespace App\Livewire\Profile;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class UpdateCustomerInformationForm extends Component
{
    public $first_name;
    public $last_name;
    public $username;
    public $profile_picture;
    public $phone_number;
    public $street;
    public $city;
    public $state;
    public $postal_code;
    public $country;

    public function mount()
    {
        $customer = Auth::user()->customer;

        if ($customer) {
            $this->first_name = $customer->first_name;
            $this->last_name = $customer->last_name;
            $this->username = $customer->username;
            $this->phone_number = $customer->phone_number;
            $this->street = $customer->street;
            $this->city = $customer->city;
            $this->state = $customer->state;
            $this->postal_code = $customer->postal_code;
            $this->country = $customer->country;
        }
    }

    public function save()
    {
        $this->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'street' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
        ]);

        $customer = Auth::user()->customer;

        if ($customer) {
            $customer->update([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'username' => $this->username,
                'phone_number' => $this->phone_number,
                'street' => $this->street,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ]);
        }
        $this->dispatch('saved');
        session()->flash('message', 'Customer information updated successfully!');
    }

    public function render()
    {
        return view('livewire.profile.update-customer-information-form');
    }
}
