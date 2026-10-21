<?php

/*
|--------------------------------------------------------------------------
| Tender engine (Spec 03 sections 4.10, 6-8; skills transport-tender / purchase-tender)
|--------------------------------------------------------------------------
|
| Amounts in UZS minor units (tiyin, 2 decimals). A `settings` row with the same key overrides
| the value (BusinessSettings). Deadlines are stored UTC and shown in `timezone` (UTC+5).
|
*/

return [

    'enabled' => true,

    'timezone' => env('CRM_BUSINESS_TIMEZONE', 'Asia/Tashkent'),
    'timezone_label' => 'UTC+5',

    // User decision 2026-10-18: approvals above 60 000 000 UZS go to the CEO (head of `management`).
    'ceo_threshold_minor' => 6_000_000_000,
    // Spec 03 Q-4: T1 (≥ 3 invitees and ≥ 2 committee members become blockers) and T2 (finance co-approves awards).
    't1_minor' => 10_000_000_000,
    't2_minor' => 100_000_000_000,
    // Spec 08 9.3: above budget by more than this → CEO.
    'over_budget_ceo_bp' => 1000,

    // BR-T.2 / BR-T.3.
    'min_bid_working_days' => 3,
    'urgent_min_bid_working_days' => 1,
    'clarification_gap_hours' => 24,
    'addendum_min_hours' => 48,
    'min_invitees' => 3,
    // BR-T.10: winner acceptance window.
    'accept_within_hours' => 24,
    // Spec 08 8.2: bid links stay readable this long after the deadline (submissions stop at the deadline).
    'link_grace_hours' => 72,
    // Spec 08 9.4: award decisions of these tender types need a step-up confirmation (password re-entry;
    // OTP via Telegram/e-mail comes with the Spec 03 OTP channels).
    'decision_step_up' => ['transport'],
    // Comparison CSV export (Spec 08 9.2): ';' for Excel in ru/uz locales.
    'csv_delimiter' => ';',

    // Landed cost (skill section 5, Spec 08 7.2): yearly rates in basis points.
    'cost_of_capital_bp' => 2400,
    'carrying_rate_bp' => 2400,
    // Purchase-tender skill P5: prepayment above this needs a justification (warning).
    'prepayment_policy_bp' => 3000,

    'default_currencies' => ['UZS', 'USD', 'EUR'],
    'currencies' => ['UZS', 'USD', 'EUR', 'RUB', 'CNY'],

    // Default criteria (code => [weight_bp, method]); weights sum to 10000.
    'default_criteria' => [
        'transport' => [
            'cost' => [5000, 'auto_lowest_cost'],
            'transit' => [1500, 'auto_fastest'],
            'reliability' => [1500, 'rating'],
            'compliance' => [1000, 'manual'],
            'payment' => [1000, 'auto_payment'],
        ],
        'purchase' => [
            'cost' => [7000, 'auto_lowest_cost'],
            'lead_time' => [1500, 'auto_fastest'],
            'reliability' => [1500, 'rating'],
        ],
    ],

    // Evidence and notes of employee-entered bids (Spec 08 8.1).
    'evidence_note_min' => 20,
    'evidence_max_kb' => 20480,

    /*
     * Controlled vocabularies of the transport RFQ (labels: tenders.vocab.<group>.<value>).
     */
    'vocab' => [
        'mode' => ['road', 'rail', 'sea', 'air', 'multimodal'],
        'load_type' => ['ftl', 'ltl', 'fcl', 'lcl', 'wagon', 'uld'],
        'border_policy' => ['required', 'preferred', 'carrier_choice'],
        'handling_method' => ['rear', 'side', 'top', 'any'],
        'handled_by' => ['shipper', 'carrier', 'consignee', 'us'],
        'clearance_by' => ['seller', 'buyer', 'carrier', 'our_broker', 'na'],
        'insurance_by' => ['carrier', 'buyer', 'seller', 'none'],
        'container_owner' => ['coc', 'soc'],
        'payer' => ['buyer', 'seller', 'na'],
        'payer_charges' => ['duties', 'vat', 'customs_fees', 'brokerage', 'storage', 'terminal', 'port', 'inspection'],
        'documents' => ['cmr', 'tir', 'invoice', 'packing_list', 'bl_awb_smgs', 'origin_certificate', 'quality_certificates', 'export_declaration', 'dgd_msds', 'insurance_certificate'],
        'driver_documents' => ['adr_certificate', 'atp', 'permits', 'visas', 'cmr_insurance', 'gps_link'],
        'received_via' => ['email', 'phone', 'telegram', 'whatsapp', 'meeting', 'other'],
    ],

];
