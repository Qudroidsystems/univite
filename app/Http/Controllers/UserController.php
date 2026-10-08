<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View users')->only(['index', 'show']);
        $this->middleware('permission:Add users')->only('store');
        $this->middleware('permission:Edit users')->only('update');
        $this->middleware('permission:Reset user passwords')->only('resetPassword');
        $this->middleware('permission:Disable or enable user accounts')->only('toggleStatus');
        $this->middleware('permission:Delete users')->only('destroy');
    }

    public function index(Request $request): View
    {
        $users = User::with('roles')
            ->when(trim((string) $request->query('q')) !== '', function ($query) use ($request) {
                $q = trim($request->query('q'));
                $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%"));
            })
            ->when($request->query('role') === '__none', fn ($query) => $query->doesntHave('roles'))
            ->when($request->filled('role') && $request->query('role') !== '__none', fn ($query) => $query->role($request->query('role')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', [
            'pagetitle' => 'User Management',
            'users'     => $users,
            'roles'     => $this->assignableRoles(),
            'allRoles'  => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function show(User $user): View
    {
        return view('users.show', [
            'pagetitle' => $user->name,
            'user'      => $user->load('roles.permissions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password'     => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles'        => ['nullable', 'array'],
            'roles.*'      => [Rule::in($this->assignableRoles())],
        ]);

        $plain = $data['password'] ?? Str::password(10, symbols: false);

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password'     => $plain,
        ]);
        $user->syncRoles($data['roles'] ?? []);

        $redirect = back()->with('success', "User {$user->name} created.");

        return empty($data['password'])
            ? $redirect->with('generated_password', ['email' => $user->email, 'password' => $plain])
            : $redirect;
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'roles'        => ['nullable', 'array'],
            'roles.*'      => [Rule::in($this->assignableRoles())],
        ]);

        $user->update(collect($data)->only(['name', 'email', 'phone_number'])->all());

        // Roles this admin is not allowed to hand out (e.g. Super Admin) are left as they are.
        $locked = $user->roles->pluck('name')->diff($this->assignableRoles())->all();
        $user->syncRoles(array_merge($locked, $data['roles'] ?? []));

        return back()->with('success', "{$user->name} updated.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $plain = Str::password(10, symbols: false);
        $user->update(['password' => $plain]);

        return back()
            ->with('success', "Password reset for {$user->name}.")
            ->with('generated_password', ['email' => $user->email, 'password' => $plain]);
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', 'You cannot disable your own account.');
        }

        $user->update(['is_disabled' => ! $user->is_disabled]);

        return back()->with('success', $user->name . ($user->is_disabled ? ' has been disabled.' : ' has been enabled.'));
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
            return back()->with('error', 'The last Super Admin cannot be deleted.');
        }

        try {
            $user->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'This user is linked to other records (for example a student profile) and cannot be deleted. Disable the account instead.');
        }

        return back()->with('success', 'User deleted.');
    }

    /** Role names the signed-in user may assign (only Super Admins can hand out Super Admin). */
    private function assignableRoles(): array
    {
        $roles = Role::orderBy('name')->pluck('name');

        if (! auth()->user()?->hasRole('Super Admin')) {
            $roles = $roles->reject(fn ($r) => $r === 'Super Admin');
        }

        return $roles->values()->all();
    }
}
