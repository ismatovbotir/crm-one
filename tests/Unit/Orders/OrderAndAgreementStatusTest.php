<?php

namespace Tests\Unit\Orders;

use App\Enums\AgreementStatus;
use App\Enums\HoldReason;
use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * Spec 06 6.1 / 6.5 transition tables.
 */
class OrderAndAgreementStatusTest extends TestCase
{
    public function test_order_transitions(): void
    {
        $this->assertTrue(OrderStatus::New->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Packing));
        $this->assertTrue(OrderStatus::Packing->canTransitionTo(OrderStatus::Packed));
        $this->assertFalse(OrderStatus::Packing->canTransitionTo(OrderStatus::Delivered), 'Delivery only after packaging (Spec 06 section 25).');
        $this->assertTrue(OrderStatus::Packed->canTransitionTo(OrderStatus::Delivered));
        $this->assertTrue(OrderStatus::PartiallyDelivered->canTransitionTo(OrderStatus::Packing));
        $this->assertTrue(OrderStatus::Delivered->canTransitionTo(OrderStatus::Closed), 'Retail closes from delivered (CloseOrder enforces retail).');
        $this->assertTrue(OrderStatus::Invoiced->canTransitionTo(OrderStatus::Closed));
        $this->assertFalse(OrderStatus::New->canTransitionTo(OrderStatus::Packing));
        $this->assertFalse(OrderStatus::PartiallyDelivered->canTransitionTo(OrderStatus::Cancelled), 'No cancellation after a delivery.');
        $this->assertFalse(OrderStatus::Closed->canTransitionTo(OrderStatus::New));
        $this->assertSame([], OrderStatus::transitions()[OrderStatus::Cancelled->value]);
        $this->assertFalse(OrderStatus::New->isActive());
        $this->assertTrue(OrderStatus::Delivered->isActive());
    }

    public function test_agreement_transitions(): void
    {
        $this->assertTrue(AgreementStatus::Draft->canTransitionTo(AgreementStatus::SentForSignature));
        $this->assertTrue(AgreementStatus::InReview->canTransitionTo(AgreementStatus::Draft));
        $this->assertTrue(AgreementStatus::SentForSignature->canTransitionTo(AgreementStatus::Draft), 'Re-issue.');
        $this->assertTrue(AgreementStatus::Active->canTransitionTo(AgreementStatus::Renewed));
        $this->assertFalse(AgreementStatus::Signed->canTransitionTo(AgreementStatus::Cancelled), 'Signed agreements are terminated, not cancelled.');
        $this->assertFalse(AgreementStatus::Draft->canTransitionTo(AgreementStatus::Signed));
        $this->assertTrue(AgreementStatus::Active->isBinding());
        $this->assertFalse(AgreementStatus::SentForSignature->isBinding());
    }

    public function test_which_holds_block_confirmation(): void
    {
        $this->assertTrue(HoldReason::CreditLimit->blocksConfirmation());
        $this->assertTrue(HoldReason::Manual->blocksConfirmation());
        $this->assertFalse(HoldReason::AgreementUnsigned->blocksConfirmation());
        $this->assertFalse(HoldReason::PrepaymentMissing->blocksConfirmation());
    }
}
