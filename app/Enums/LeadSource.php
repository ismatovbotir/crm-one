<?php

namespace App\Enums;

/**
 * Where a lead came from (Spec 04 section 6, list rev. 2 in section 15).
 */
enum LeadSource: string
{
    case Website = 'website';
    case Phone = 'phone';
    case Email = 'email';
    case Referral = 'referral';
    case Exhibition = 'exhibition';
    /** Campaigns, social media and ads (rev. 2 merged `campaign` and `social`; the campaign name stays a field). */
    case Marketing = 'marketing';
    /** Repeat order or upsell from an existing customer. */
    case ExistingCustomer = 'existing_customer';
    /** Message from a linked customer-portal user (Telegram or portal). */
    case PortalRequest = 'portal_request';
    /** Message to the Telegram bot from a sender without a CRM account. */
    case Telegram = 'telegram';
    case Tender = 'tender';
    case Partner = 'partner';
    case ColdOutreach = 'cold_outreach';
    case Marketplace = 'marketplace';
    case Other = 'other';

    public function label(): string
    {
        return __('leads.sources.'.$this->value);
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
