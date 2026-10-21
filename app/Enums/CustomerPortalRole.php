<?php

namespace App\Enums;

/**
 * Customer-side portal roles (Spec 03 4.8); a membership holds one or more.
 */
enum CustomerPortalRole: string
{
    case PortalAdmin = 'portal_admin';
    case Buyer = 'buyer';
    case Approver = 'approver';
    case Viewer = 'viewer';
    case Finance = 'finance';

    /** Spec 10: client team member who creates requests (requisitions). */
    case Requester = 'requester';

    /** Spec 10: the client's buyer who reviews, consolidates and sends requests to us. */
    case PurchaseManager = 'purchase_manager';

    public function label(): string
    {
        return __('portal.roles.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
