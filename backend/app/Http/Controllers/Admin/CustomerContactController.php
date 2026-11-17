<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Actions\SaveCustomerContact;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerContactRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Kontak kios (15 §5 `admin.customers.contacts.*`, T-074), `customers.manage` dalam scope. Kontak
 * di-bind sebagai anak kios (`scopeBindings`): kontak kios lain = 404, kios di luar scope = 404.
 */
class CustomerContactController extends Controller
{
    public function store(CustomerContactRequest $request, Customer $customer, SaveCustomerContact $action): RedirectResponse
    {
        $action->handle($customer, null, $request->contactData(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kontak ditambahkan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'kontak']);
    }

    public function update(CustomerContactRequest $request, Customer $customer, CustomerContact $contact, SaveCustomerContact $action): RedirectResponse
    {
        $action->handle($customer, $contact, $request->contactData(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kontak disimpan.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'kontak']);
    }

    public function destroy(Request $request, Customer $customer, CustomerContact $contact, SaveCustomerContact $action): RedirectResponse
    {
        Gate::authorize('manageContacts', $customer);
        $action->remove($customer, $contact);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kontak dihapus.']);

        return to_route('admin.customers.show', ['customer' => $customer->id, 'tab' => 'kontak']);
    }
}
