<?php

namespace App\Livewire\Departments;

use App\Actions\Departments\AssignDepartmentMember;
use App\Enums\DepartmentRole;
use App\Enums\Locale;
use App\Enums\UserType;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Department create / show / edit, including members (Spec 03 section 9: "Departments (admin)").
 * Viewers without manage rights get the same page read-only.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    #[Locked]
    public ?int $departmentId = null;

    public string $code = '';

    /** @var array<string, string> locale => name */
    public array $names = ['uz' => '', 'ru' => '', 'en' => ''];

    public ?int $parent_id = null;

    public int $sort_order = 0;

    public bool $is_active = true;

    public ?int $memberUserId = null;

    public string $memberRole = 'specialist';

    public bool $memberPrimary = false;

    public function mount(?Department $department = null): void
    {
        if ($department?->exists) {
            $this->authorize('view', $department);

            $this->departmentId = $department->id;
            $this->code = $department->code;
            $this->parent_id = $department->parent_id;
            $this->sort_order = $department->sort_order;
            $this->is_active = $department->is_active;

            foreach (Locale::cases() as $locale) {
                $this->names[$locale->value] = (string) ($department->name[$locale->value] ?? '');
            }

            return;
        }

        $this->authorize('create', Department::class);
    }

    public function isEditing(): bool
    {
        return $this->departmentId !== null;
    }

    #[Computed]
    public function department(): ?Department
    {
        return $this->departmentId === null ? null : Department::query()->findOrFail($this->departmentId);
    }

    public function canEdit(): bool
    {
        return $this->isEditing()
            ? auth()->user()->can('update', $this->department)
            : auth()->user()->can('create', Department::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->department?->members()->orderBy('name')->get() ?? new Collection;
    }

    /**
     * Active internal users who are not members yet.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->internal()
            ->active()
            ->whereNotIn('id', $this->members->modelKeys())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique(Department::class, 'code')->ignore($this->departmentId),
            ],
            'names.uz' => [$this->hasSeededName() ? 'nullable' : 'required', 'string', 'max:120'],
            'names.ru' => ['nullable', 'string', 'max:120'],
            'names.en' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id'), Rule::notIn(array_filter([$this->departmentId]))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => __('departments.code'),
            'names.uz' => __('departments.name_in', ['language' => Locale::Uz->label()]),
            'names.ru' => __('departments.name_in', ['language' => Locale::Ru->label()]),
            'names.en' => __('departments.name_in', ['language' => Locale::En->label()]),
            'parent_id' => __('departments.parent'),
            'sort_order' => __('departments.sort_order'),
        ];
    }

    protected function hasSeededName(): bool
    {
        return trans()->has('departments.names.'.$this->code, Locale::Uz->value, false);
    }

    public function save(): void
    {
        $this->code = mb_strtolower(trim($this->code));

        if ($this->isEditing()) {
            $this->authorize('update', $this->department);
        } else {
            $this->authorize('create', Department::class);
        }

        $validated = $this->validate();
        $names = array_map(fn (?string $name): ?string => filled($name) ? trim($name) : null, $validated['names']);

        $attributes = [
            'code' => $validated['code'],
            'name' => array_filter($names) === [] ? null : $names,
            'parent_id' => $validated['parent_id'],
            'sort_order' => $validated['sort_order'],
            'is_active' => $validated['is_active'],
        ];

        if ($this->isEditing()) {
            $department = $this->department;
            $department->fill($attributes)->save();
            session()->flash('status', __('departments.updated', ['name' => $department->displayName()]));

            $this->redirectRoute('departments.show', $department, navigate: true);

            return;
        }

        $department = Department::query()->create($attributes);
        session()->flash('status', __('departments.created', ['name' => $department->displayName()]));

        $this->redirectRoute('departments.show', $department, navigate: true);
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->department);

        $validated = $this->validate([
            'memberUserId' => ['required', 'integer', Rule::exists(User::class, 'id')->where('type', UserType::Internal->value)],
            'memberRole' => ['required', Rule::enum(DepartmentRole::class)],
            'memberPrimary' => ['boolean'],
        ], [], [
            'memberUserId' => __('departments.member'),
            'memberRole' => __('departments.member_role'),
        ]);

        $user = User::query()->findOrFail($validated['memberUserId']);
        app(AssignDepartmentMember::class)->handle($this->department, $user, DepartmentRole::from($validated['memberRole']), $validated['memberPrimary'], 'memberUserId');

        $this->reset(['memberUserId', 'memberRole', 'memberPrimary']);
        unset($this->members, $this->candidates);
        session()->flash('status', __('departments.member_added', ['name' => $user->name]));
    }

    public function changeMemberRole(int $userId, string $role): void
    {
        $this->authorize('manageMembers', $this->department);

        $member = $this->findMember($userId);
        $newRole = DepartmentRole::tryFrom($role) ?? abort(422);

        app(AssignDepartmentMember::class)->handle($this->department, $member, $newRole, $member->membership->is_primary, 'members.'.$userId);

        unset($this->members);
        session()->flash('status', __('departments.member_updated', ['name' => $member->name]));
    }

    public function makePrimary(int $userId): void
    {
        $this->authorize('manageMembers', $this->department);

        $member = $this->findMember($userId);
        app(AssignDepartmentMember::class)->handle($this->department, $member, $member->membership->role, true, 'members.'.$userId);

        unset($this->members);
        session()->flash('status', __('departments.member_updated', ['name' => $member->name]));
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manageMembers', $this->department);

        $member = $this->findMember($userId);
        app(AssignDepartmentMember::class)->remove($this->department, $member);

        unset($this->members, $this->candidates);
        session()->flash('status', __('departments.member_removed', ['name' => $member->name]));
    }

    protected function findMember(int $userId): User
    {
        return $this->department->members()->whereKey($userId)->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.departments.form', [
            'roles' => DepartmentRole::options(),
            'parents' => Department::query()->whereKeyNot($this->departmentId)->orderBy('sort_order')->get(),
            'locales' => Locale::cases(),
            'activities' => $this->department?->activities()->with('causer')->limit(50)->get() ?? collect(),
        ])->title($this->isEditing() ? $this->department->displayName() : __('departments.new'));
    }
}
