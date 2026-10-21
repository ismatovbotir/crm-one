<?php

namespace App\Enums;

/**
 * How an agreement version was sent (Spec 06 section 6.5 dispatches). Portal and e-mail link
 * become live with the customer portal (Spec 03); print and courier work now.
 */
enum AgreementChannel: string
{
    case Print = 'print';
    case Courier = 'courier';
    case EmailLink = 'email_link';
    case Portal = 'portal';

    /**
     * Channels usable now: print, courier and the customer portal (Spec 03 P-4; the customer must
     * be portal-enabled). The e-mail link channel stays off until portal invitations by e-mail.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return [self::Print, self::Courier, self::Portal];
    }

    public function label(): string
    {
        return __('agreements.channels.'.$this->value);
    }
}
