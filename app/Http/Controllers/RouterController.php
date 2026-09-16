<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Network\NetworkOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class RouterController extends Controller
{
    public function index()
    {
        return view('routers.index', ['routers' => Router::where('tenant_id', Auth::user()->tenant_id)->latest()->get()]);
    }

    public function create()
    {
        return view('routers.form', ['router' => new Router]);
    }

    public function show(Router $router)
    {
        Gate::authorize('view', $router);

        return view('routers.show', compact('router'));
    }

    public function edit(Router $router)
    {
        Gate::authorize('update', $router);

        return view('routers.form', compact('router'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'host' => ['required', 'string', 'max:255'], 'api_port' => ['required', 'integer', 'between:1,65535'], 'username' => ['required', 'string', 'max:255'], 'password' => ['nullable', 'string', 'max:255']]);
        $router = new Router(array_merge($data, ['tenant_id' => Auth::user()->tenant_id, 'driver' => config('network.driver'), 'status' => 'available']));
        if (isset($data['password'])) {
            $router->setPassword($data['password']);
            unset($router->password);
        }
        $router->save();

        return redirect()->route('routers.index')->with('status', 'Router created.');
    }

    public function update(Request $request, Router $router)
    {
        Gate::authorize('update', $router);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'host' => ['required', 'string', 'max:255'], 'api_port' => ['required', 'integer', 'between:1,65535'], 'username' => ['required', 'string', 'max:255'], 'password' => ['nullable', 'string', 'max:255']]);
        $password = $data['password'] ?? null;
        unset($data['password']);
        $router->update($data);
        if ($password) {
            $router->setPassword($password);
            $router->save();
        }

        return redirect()->route('routers.index')->with('status', 'Router updated.');
    }

    public function destroy(Router $router)
    {
        Gate::authorize('delete', $router);
        $router->delete();

        return redirect()->route('routers.index')->with('status', 'Router deleted.');
    }

    public function test(Router $router, NetworkOperationService $operations)
    {
        Gate::authorize('operate', $router);
        $result = $operations->testConnection($router, Auth::user());
        if ($result->successful) {
            $router->update(['status' => 'available', 'last_seen_at' => now()]);

            return back()->with('status', $result->message);
        }
        $router->update(['status' => 'unavailable']);

        return back()->withErrors(['router' => $result->message]);
    }
}
