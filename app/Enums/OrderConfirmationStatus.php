<?php

namespace App\Enums;

enum OrderConfirmationStatus: string
{
    case NEW = 'new';
    case ASSIGNED = 'assigned';
    case CONFIRMED = 'confirmed';
    case CONFIRMED_FOLLOWUP = 'confirmed_followup';
    case CALLBACK = 'callback';
    case FAKE = 'fake';
    case VOICEMAIL = 'voicemail';
    case NO_ANSWER = 'no_answer';
    case BUSY = 'busy';
    case WHATSAPP_SENT = 'whatsapp_sent';
    case CANCELLED = 'cancelled';
    case SUBMITTED_TO_COURIER = 'submitted_to_courier';
    case TEST_COMPLETED = 'test_completed';

    /**
     * Statuses a user may set by hand.
     *
     * ASSIGNED is set when an order is auto-assigned or an admin
     * (un)assigns an agent. SUBMITTED_TO_COURIER is set only when the
     * courier's API confirms the parcel was created — picking it by hand
     * would claim a parcel exists at a courier that never received one,
     * leaving an order that can never be tracked or settled.
     * TEST_COMPLETED is the terminal state a confirmed test order lands
     * on automatically (UC-25).
     *
     * OrderService::updateStatus() is deliberately not bound by this —
     * the shipment flow reaches SUBMITTED_TO_COURIER through it.
     *
     * @return array<int, self>
     */
    public static function manuallySelectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => ! in_array($status, [
                self::ASSIGNED,
                self::SUBMITTED_TO_COURIER,
                self::TEST_COMPLETED,
            ], true),
        ));
    }

    /**
     * Get the human-readable description for the status.
     */
    public function description(): string
    {
        return match ($this) {
            self::NEW => 'Order just arrived, unassigned',
            self::ASSIGNED => 'Given to a confirmation agent',
            self::CONFIRMED => 'Client confirmed the order',
            self::CONFIRMED_FOLLOWUP => 'Confirmed, but needs a follow-up (missing detail) before shipment',
            self::CALLBACK => 'Agent needs to call again later (client asked to be called back)',
            self::FAKE => 'Agent suspects a fake/prank order',
            self::VOICEMAIL => 'Reached voicemail, not a live no-answer',
            self::NO_ANSWER => 'No answer on the phone',
            self::BUSY => 'Line was busy',
            self::WHATSAPP_SENT => 'Agent messaged on WhatsApp instead of calling, awaiting reply',
            self::CANCELLED => 'Closed before shipment',
            self::SUBMITTED_TO_COURIER => 'Parcel successfully created at the delivery courier (API success) — terminal state for the confirmation side',
            self::TEST_COMPLETED => 'Terminal state for confirmed test orders (UC-25)',
        };
    }
}
