@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) form · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">02</span><span class="eyebrow__sep">·</span>Customer</p>
            <h1>{{ $customer->exists ? 'Edit customer' : 'New customer' }}</h1>
            <p class="page-header__meta">
                @if ($customer->exists)
                    {{ $customer->customer_code }} · This form writes the customer record only. Connections, invoices and messages are managed from the customer page.
                @else
                    The customer code is assigned on save — CL followed by six digits.
                @endif
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="customer-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="customer-record">{{ $customer->exists ? $customer->customer_code : 'New customer' }}</h2>
            </div>
            <span class="panel__meta">{{ $customer->exists ? 'Editing' : 'Creating' }}</span>
        </div>
        <div class="panel__body">
            <form class="spec-form" method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
                @csrf
                @if ($customer->exists) @method('PUT') @endif

                <h3>Identity</h3>
                <label for="name">Name @error('name')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="name" name="name" value="{{ old('name', $customer->name) }}" required>

                <label for="phone">Phone @error('phone')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="phone" name="phone" value="{{ old('phone', $customer->phone) }}">

                <label for="email">Email @error('email')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="email" name="email" type="email" value="{{ old('email', $customer->email) }}">

                <h3>Site</h3>
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3">{{ old('address', $customer->address) }}</textarea>

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3">{{ old('notes', $customer->notes) }}</textarea>

                <h3>Lifecycle</h3>
                <label for="status">State @error('status')<em class="field-error">{{ $message }}</em>@enderror</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $customer->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $customer->status) === 'inactive')>Inactive</option>
                </select>

                <div class="spec-form__actions">
                    <button type="submit">{{ $customer->exists ? 'Save changes' : 'Create customer' }}</button>
                    <p class="console-note">{{ $customer->exists ? 'Changes are written to '.$customer->customer_code.' on save.' : 'New customers appear at the top of the customer register.' }}</p>
                </div>
            </form>
        </div>
    </section>
@endsection