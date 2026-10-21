<?php

namespace App\Enums;

/**
 * User realm (Spec 03 DEC-1): internal staff sign in at /login, portal users
 * (customer and partner employees) only through the portal.
 */
enum UserType: string
{
    case Internal = 'internal';
    case Portal = 'portal';

    public function label(): string
    {
        return __('users.types.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
