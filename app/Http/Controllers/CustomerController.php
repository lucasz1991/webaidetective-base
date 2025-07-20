<?php

// CustomerController
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::all();
        return view('customers.index', compact('customers'));
    }

    public function show(Customer $customer)
    {
        return view('customers.show', compact('customer'));
    }

    public function create()
    {
        if (Auth::check()) {
            return view('customers.create');
        }

        return redirect()->route('login')->with('error', 'Bitte melden Sie sich zuerst an.');
    }

    public function store(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Bitte melden Sie sich zuerst an.');
        }

        $validatedData = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:customers',
            'phone_number' => 'required|string',
            'street' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'postal_code' => 'required|string',
            'country' => 'required|string'
        ]);

        $user = Auth::user();
        $validatedData['user_id'] = $user->id;
        Customer::create($validatedData);

        return redirect()->route('customers.index')->with('success', 'Customer-Daten erfolgreich erstellt.');
    }

    public function edit(Customer $customer)
    {
        if (Auth::id() !== $customer->user_id) {
            abort(403, 'Zugriff verweigert.');
        }

        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        if (Auth::id() !== $customer->user_id) {
            abort(403, 'Zugriff verweigert.');
        }

        $validatedData = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:customers,username,' . $customer->id,
            'phone_number' => 'required|string',
            'street' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'postal_code' => 'required|string',
            'country' => 'required|string'
        ]);

        $customer->update($validatedData);

        return redirect()->route('customers.index')->with('success', 'Customer-Daten erfolgreich aktualisiert.');
    }

    public function destroy(Customer $customer)
    {
        if (Auth::id() !== $customer->user_id) {
            abort(403, 'Zugriff verweigert.');
        }

        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Customer-Daten erfolgreich gelöscht.');
    }
}

