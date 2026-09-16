<?php

namespace App\Http\Controllers;

use App\Models\InternetPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class InternetPackageController extends Controller
{
    public function index()
    {
        return view('packages.index', ['packages' => InternetPackage::where('tenant_id', Auth::user()->tenant_id)->latest()->get()]);
    }

    public function create()
    {
        return view('packages.form', ['package' => new InternetPackage(['status' => 'active'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        InternetPackage::create($data + ['tenant_id' => Auth::user()->tenant_id]);

        return redirect()->route('packages.index')->with('status', 'Package created.');
    }

    public function show(InternetPackage $package)
    {
        Gate::authorize('view', $package);

        return view('packages.show', compact('package'));
    }

    public function edit(InternetPackage $package)
    {
        Gate::authorize('update', $package);

        return view('packages.form', compact('package'));
    }

    public function update(Request $request, InternetPackage $package)
    {
        Gate::authorize('update', $package);
        $package->update($this->validated($request));

        return redirect()->route('packages.show', $package)->with('status', 'Package updated.');
    }

    public function destroy(InternetPackage $package)
    {
        Gate::authorize('update', $package);
        abort_if($package->connections()->exists(), 422, 'Packages used by connections cannot be deleted.');
        $package->update(['status' => 'inactive']);

        return redirect()->route('packages.index')->with('status', 'Package deactivated.');
    }

    public function status(InternetPackage $package, string $status)
    {
        Gate::authorize('update', $package);
        abort_unless(in_array($status, ['active', 'inactive'], true), 404);
        $package->update(['status' => $status]);

        return back()->with('status', 'Package '.($status === 'active' ? 'activated.' : 'deactivated.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:50'], 'download_mbps' => ['required', 'integer', 'min:1'], 'upload_mbps' => ['required', 'integer', 'min:1'], 'monthly_price' => ['required', 'integer', 'min:0'], 'network_profile' => ['required', 'string', 'max:255'], 'status' => ['required', 'in:active,inactive'], 'description' => ['nullable', 'string']]);
    }
}
