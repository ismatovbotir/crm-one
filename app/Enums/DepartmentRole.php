<?php

namespace App\Enums;

/**
 * A user's role inside one department (Spec 03 section 4.9). Exactly one head per department.
 */
enum DepartmentRole: string
{
    case Head = 'head';
    case Specialist = 'specialist';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __('departments.roles.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
