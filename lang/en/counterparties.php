<?php

/*
| Counterparties: suppliers and carriers (Spec 03 T-1).
*/

return [

    'title' => 'Counterparties',
    'intro' => 'Suppliers and carriers invited to tenders: qualification documents, contacts and ratings.',
    'new' => 'New counterparty',
    'create' => 'Create',
    'back' => 'Counterparties',
    'search_placeholder' => 'Name, legal name, TIN or e-mail',
    'all_roles' => 'Suppliers and carriers',
    'empty' => 'No counterparties yet.',
    'no_tenders' => 'Not invited to any tender yet.',
    'change_status' => 'Change status',
    'reason' => 'Reason',
    'tabs_label' => 'Counterparty sections',
    'tabs' => ['details' => 'Details', 'contacts' => 'Contacts', 'documents' => 'Documents', 'ratings' => 'Ratings', 'tenders' => 'Tenders', 'activity' => 'Change log'],

    'status' => ['prospect' => 'Prospect', 'pending_qualification' => 'Pending qualification', 'qualified' => 'Qualified', 'suspended' => 'Suspended', 'blacklisted' => 'Blacklisted'],
    'status_reasons' => ['document_expired' => 'A qualification document expired.'],
    'roles' => ['supplier' => 'Supplier', 'carrier' => 'Carrier'],

    'fields' => [
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'tax_id' => 'TIN',
        'country' => 'Country (ISO)',
        'city' => 'City',
        'address' => 'Address',
        'phone' => 'Phone',
        'email' => 'E-mail',
        'website' => 'Website',
        'roles' => 'Role',
        'location' => 'Location',
        'rating' => 'Rating',
        'fleet_size' => 'Fleet size',
        'has_tir' => 'TIR carnet holder',
        'has_adr' => 'ADR (dangerous goods)',
        'preferred_locale' => 'Document language',
        'notes' => 'Notes',
    ],

    'contacts' => [
        'name' => 'Full name', 'position' => 'Position', 'telegram' => 'Telegram', 'primary' => 'Primary', 'add' => 'Add contact',
        'remove' => 'Remove', 'confirm_remove' => 'Remove this contact?', 'empty' => 'No contacts yet.',
    ],

    'document_types' => [
        'registration' => 'State registration', 'license' => 'License', 'transport_license' => 'Transport license', 'cmr_insurance' => 'CMR liability insurance',
        'cargo_insurance' => 'Cargo insurance', 'adr' => 'ADR certificate', 'tir' => 'TIR carnet', 'iso' => 'ISO certificate',
        'dealer_authorization' => 'Dealer / OEM authorization', 'bank_reference' => 'Bank reference', 'other' => 'Other',
    ],

    'documents' => [
        'type' => 'Type', 'number' => 'Number', 'valid_until' => 'Valid until', 'verified' => 'Verified by', 'file' => 'File (PDF, JPG, PNG)',
        'add' => 'Add document', 'verify' => 'Verify', 'open' => 'Open', 'expired' => 'Expired', 'expiring' => 'Expires soon', 'empty' => 'No documents yet.',
    ],

    'ratings' => [
        'quality' => 'Quality', 'communication' => 'Communication', 'documents' => 'Documents', 'on_time' => 'On time', 'late' => 'Late',
        'comment' => 'Comment', 'add' => 'Add rating', 'empty' => 'No ratings yet.',
        'line' => 'Quality :quality · communication :communication · documents :documents',
    ],

    'flash' => [
        'saved' => 'Counterparty saved.',
        'contact_saved' => 'Contact saved.',
        'document_added' => 'Document added.',
        'status_changed' => 'Status changed.',
        'rated' => 'Rating saved.',
    ],

];
