<?php

namespace Tests\Unit\Offers;

use App\Enums\OfferStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 05 section 6.1: the full allowed-transition table, valid and invalid.
 */
class OfferStatusTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'draft' => ['pending_approval', 'sent', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'cancelled'],
        'approved' => ['sent', 'draft', 'cancelled'],
        'sent' => ['viewed', 'partially_selected', 'accepted', 'rejected', 'expired', 'superseded', 'cancelled'],
        'viewed' => ['partially_selected', 'accepted', 'rejected', 'expired', 'superseded', 'cancelled'],
        'partially_selected' => ['accepted', 'rejected', 'expired', 'superseded', 'cancelled'],
        'expired' => ['sent', 'superseded'],
        'accepted' => [],
        'rejected' => [],
        'superseded' => [],
        'cancelled' => [],
    ];

    /**
     * @return array<string, array{OfferStatus, OfferStatus, bool}>
     */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (OfferStatus::cases() as $from) {
            foreach (OfferStatus::cases() as $to) {
                $pairs[$from->value.' -> '.$to->value] = [$from, $to, in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }

        return $pairs;
    }

    #[DataProvider('pairs')]
    public function test_transition_map(OfferStatus $from, OfferStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_status_groups(): void
    {
        $this->assertSame([OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected], OfferStatus::open());
        $this->assertTrue(OfferStatus::Approved->isEditable());
        $this->assertFalse(OfferStatus::Sent->isEditable());
        $this->assertTrue(OfferStatus::Rejected->isRevisable());
        $this->assertFalse(OfferStatus::Accepted->isRevisable());

        foreach ([OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Superseded, OfferStatus::Cancelled] as $final) {
            $this->assertTrue($final->isFinal());
        }
    }
}
