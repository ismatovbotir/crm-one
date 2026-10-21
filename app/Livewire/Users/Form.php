<?php

namespace App\Livewire\Users;

use App\Actions\Departments\AssignDepartmentMember;
use App\Enums\DepartmentRole;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    /** Realm of the edited user. New users created here are always internal (portal users are invited). */
    #[Locked]
    public string $type = 'internal';

    public string $name = '';

    public string $email = '';

    public string $role = 'sales';

    public string $phone = '';

    public string $position = '';

    /** Default language for the CRM (users.locale): sign-in, notifications, e-mails, Telegram. */
    public string $locale = 'uz';

    /** Primary department; memberships in further departments are managed on the Departments page. */
    public ?int $departmentId = null;

    public string $departmentRole = 'specialist';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $this->userId = $user->id;
            $this->type = $user->type->value;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role?->value ?? '';
            $this->phone = (string) $user->phone;
            $this->position = (string) $user->position;
            $this->locale = ($user->locale ?? Locale::default())->value;

            $primary = $user->primaryDepartment();
            $this->departmentId = $primary?->id;
            $this->departmentRole = $primary?->membership->role->value ?? 'specialist';

            return;
        }

        $this->authorize('create', User::class);
    }

    public function isEditing(): bool
    {
        return $this->userId !== null;
    }

    public function isInternal(): bool
    {
        return $this->type === UserType::Internal->value;
    }

    public function canChangeRole(): bool
    {
        return ! $this->isEditing() || auth()->user()->can('changeRole', $this->editedUser());
    }

    protected function editedUser(): User
    {
        return User::findOrFail($this->userId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $internal = $this->isInternal();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class, 'email')->ignore($this->userId),
            ],
            'role' => $internal ? ['required', Rule::enum(UserRole::class)] : ['nullable'],
            'phone' => ['nullable', 'string', 'max:32'],
            'position' => ['nullable', 'string', 'max:120'],
            'locale' => ['required', Rule::enum(Locale::class)],
            'departmentId' => [
                $internal && $this->role === UserRole::Staff->value ? 'required' : 'nullable',
                'integer',
                Rule::exists(Department::class, 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'departmentRole' => ['required', Rule::enum(DepartmentRole::class)],
            'password' => [
                $this->isEditing() || ! $internal ? 'nullable' : 'required',
                'string', 'confirmed', Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['departmentId.required' => __('users.staff_needs_department')];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'role' => __('users.role'),
            'phone' => __('users.phone'),
            'position' => __('users.position'),
            'locale' => __('users.locale'),
            'departmentId' => __('users.primary_department'),
            'departmentRole' => __('users.department_role'),
        ];
    }

    public function save(): void
    {
        $this->email = mb_strtolower(trim($this->email));

        if ($this->isEditing()) {
            $user = $this->editedUser();
            $this->authorize('update', $user);
        } else {
            $this->authorize('create', User::class);
        }

        $validated = $this->validate();

        if ($this->isEditing() && $this->isInternal() && $validated['role'] !== $user->role?->value) {
            $this->authorize('changeRole', $user);
        }

        $user = DB::transaction(function () use ($validated): User {
            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => filled($validated['phone']) ? trim($validated['phone']) : null,
                'position' => filled($validated['position']) ? trim($validated['position']) : null,
                'locale' => $validated['locale'],
            ];

            if ($this->isInternal()) {
                $attributes['role'] = $validated['role'];
            }

            if ($this->isEditing()) {
                $user = $this->editedUser()->fill($attributes);

                if (filled($validated['password'])) {
                    $user->password = $validated['password'];
                }

                $user->save();
            } else {
                $user = User::create([
                    ...$attributes,
                    'type' => UserType::Internal,
                    'password' => $validated['password'],
                    'is_active' => true,
                ]);
            }

            if ($this->isInternal() && $validated['departmentId'] !== null) {
                app(AssignDepartmentMember::class)->handle(
                    Department::query()->findOrFail($validated['departmentId']),
                    $user,
                    DepartmentRole::from($validated['departmentRole']),
                    primary: true,
                    errorKey: 'departmentId',
                );
            }

            return $user;
        });

        session()->flash('status', __($this->isEditing() ? 'users.updated' : 'users.created', ['name' => $user->name]));

        $this->redirectRoute('users.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.users.form', [
            'roles' => UserRole::options(),
            'departments' => Department::query()->active()->orderBy('sort_order')->get(),
            'departmentRoles' => DepartmentRole::options(),
            'otherDepartments' => $this->isEditing()
                ? $this->editedUser()->activeDepartments()->reject(fn (Department $department): bool => $department->membership->is_primary)
                : collect(),
        ])->title($this->isEditing() ? __('users.edit') : __('users.new'));
    }
}
