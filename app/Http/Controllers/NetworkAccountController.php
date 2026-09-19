<?php

namespace App\Http\Controllers;

use App\Models\NetworkAccount;
use App\Models\Router;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Services\Network\NetworkOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class NetworkAccountController extends Controller
{
    public function index()
    {
        return view('network.accounts', ['accounts' => NetworkAccount::where('tenant_id', Auth::user()->tenant_id)->with('router')->latest()->get(), 'routers' => Router::where('tenant_id', Auth::user()->tenant_id)->get()]);
    }

    public function store(Request $request, NetworkOperationService $operations)
    {
        $data = $request->validate(['router_id' => ['required', 'integer', 'exists:routers,id'], 'username' => ['required', 'string', 'max:255'], 'profile' => ['required', 'string', 'max:255']]);
        $router = Router::findOrFail($data['router_id']);
        Gate::authorize('operate', $router);
        $result = $operations->createAccount($router, $data, Auth::user());
        if (! $result->successful) {
            return back()->withErrors(['router_id' => $result->message])->withInput();
        }
        NetworkAccount::create(array_merge($data, ['tenant_id' => Auth::user()->tenant_id, 'status' => 'active']));

        return back()->with('status', $result->message);
    }

    public function status(NetworkAccount $account, string $status, NetworkOperationService $operations)
    {
        $this->authorizeAccount($account);
        abort_unless(in_array($status, ['active', 'disabled'], true), 404);
        $result = $operations->changeStatus($account, $status, Auth::user());

        return $result->successful ? back()->with('status', $result->message) : back()->withErrors(['account' => $result->message]);
    }

    public function profile(Request $request, NetworkAccount $account, NetworkOperationService $operations)
    {
        $this->authorizeAccount($account);
        $data = $request->validate(['profile' => ['required', 'string', 'max:255']]);
        $result = $operations->changeProfile($account, $data['profile'], Auth::user());

        return $result->successful ? back()->with('status', $result->message) : back()->withErrors(['profile' => $result->message]);
    }

    public function disconnect(NetworkAccount $account, NetworkOperationService $operations)
    {
        $this->authorizeAccount($account);
        $result = $operations->disconnect($account, Auth::user());

        return $result->successful ? back()->with('status', $result->message) : back()->withErrors(['account' => $result->message]);
    }

    public function manage(Request $request, NetworkAccount $account, ManagedAccountLifecycleService $lifecycle)
    {
        abort_unless($account->tenant_id === Auth::user()->tenant_id, 403);
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:128']]);
        $decision = $lifecycle->manage($account, Auth::user(), $data['confirmation']);

        return $decision->allowed
            ? back()->with('status', 'Local managed authorization granted.')
            : back()->withErrors(['account' => $decision->errorCode]);
    }

    public function revokeManagement(NetworkAccount $account, ManagedAccountLifecycleService $lifecycle)
    {
        abort_unless($account->tenant_id === Auth::user()->tenant_id, 403);
        $decision = $lifecycle->revoke($account, Auth::user());

        return $decision->allowed
            ? back()->with('status', 'Local managed authorization revoked.')
            : back()->withErrors(['account' => $decision->errorCode]);
    }

    private function authorizeAccount(NetworkAccount $account): void
    {
        abort_unless($account->tenant_id === Auth::user()->tenant_id, 403);
        // Same capability boundary as every other device write: a tenant-scoped
        // account row is not enough, the actor must be a network operator.
        Gate::authorize('operate', $account->router);
        abort_unless(! data_get($account->metadata, 'adopted_from_discovery', false), 422, 'Adopted accounts are read-only.');
    }
}
