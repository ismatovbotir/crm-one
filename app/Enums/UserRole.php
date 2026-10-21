<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';
    case Finance = 'finance';

    /** Operator (maker): prepares/sends agreements, issues invoices, records payments (Spec 06 DEC-2). */
    case Accountant = 'accountant';

    /** Moves goods and registers serial numbers; never touches money (Spec 06 21.2, DEC-18). */
    case StockManager = 'stock_manager';

    /** Service team (Spec 09): equipment register and repair tickets; no money, no sales. */
    case ServiceEngineer = 'service_engineer';

    /** No global rights; everything comes from department memberships (Spec 03 section 4.9). */
    case Staff = 'staff';

    public function label(): string
    {
        return __('users.roles.'.$this->value);
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
