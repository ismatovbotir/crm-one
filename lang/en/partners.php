<?php

/*
| Partners: all organizations with roles (Spec 03 section 24).
*/

return [
    'title' => 'Partners',
    'intro' => 'All organizations: customers, suppliers and carriers. One organization can have several roles.',
    'new' => 'New partner',
    'create' => 'Create partner',
    'back' => 'Back to partners',
    'search_placeholder' => 'Name, legal name or tax ID',
    'any_role' => 'Any role',
    'empty' => 'No partners found',
    'roles' => [
        'customer' => 'Customer',
        'supplier' => 'Supplier',
        'carrier' => 'Carrier',
    ],
    'fields' => [
        'short_name' => 'Name',
        'region' => 'Region',
        'supplier_category' => 'Supplier category',
        'permits' => 'Permits',
        'role' => 'Role',
        'roles' => 'Roles',
        'fleet_size' => 'Fleet size (vehicles)',
    ],
    'roles_hint' => 'Tick at least one role. Supplier and carrier can be combined; a customer can also be a supplier or carrier. You see only the roles you may create.',
    'sections' => [
        'terms' => 'Language, currency and owner',
        'identity' => 'Organization',
        'customer' => 'Customer',
        'supplier' => 'Supplier and carrier',
    ],
    'wizard' => [
        'label' => 'New partner steps',
        'steps' => ['roles' => 'Type', 'identity' => 'Organization', 'address' => 'Address and contacts', 'terms' => 'Terms', 'contact' => 'First contact'],
        'next' => 'Next',
        'back' => 'Back',
        'step_of' => 'Step :current of :total',
        'optional' => 'Optional: skip it and add contacts on the card.',
    ],
    'customer_hint' => 'Bank details, credit and finance fields are on the customer card after saving.',
    'supplier_hint' => 'Qualification starts as Prospect. Documents, bank details and ratings are added on the supplier card.',
    'tax_id_exists' => 'An organization with this tax ID already exists: :name (roles: :roles).',
    'add_roles_existing' => 'Add the chosen roles to it',
    'created' => 'Partner :name created.',
    'edit_roles' => 'Edit roles',
    'roles_edit_hint' => 'A role in use (offers, orders, tenders, purchase orders) cannot be removed. Nothing is deleted.',
    'roles_saved' => 'Roles of :name saved.',
    'errors' => [
        'owner_not_allowed' => 'Only users who may assign owners can choose someone else.',
        'tax_id_uz_individual' => 'Enter a 9-digit INN or a 14-digit PINFL.',
        'tax_id_foreign' => 'The tax number must have 5 to 20 letters or digits.',
        'role_required' => 'Choose at least one role.',
        'role_not_allowed' => 'You may not change the role ":role".',
        'role_in_use' => 'The role ":role" cannot be removed while it is used: :details.',
        'tax_id_taken' => 'An organization with this tax ID already exists (:name).',
        'tax_id_archived' => 'An archived organization has this tax ID (:name).',
    ],
    'dependents' => [
        'offers' => ':count offers',
        'orders' => ':count orders',
        'leads' => ':count open leads',
        'tender_invitations' => ':count tender invitations',
        'tender_bids' => ':count bids',
        'tender_awards' => ':count awards',
        'purchase_orders' => ':count purchase orders',
    ],

    'hints' => [
        'short_name' => 'Short name shown everywhere in the CRM.',
        'legal_name' => 'Full legal name with the company form (e.g. "Alfa Trade" MChJ); used on documents.',
        'tax_uz' => '9-digit INN (STIR).',
        'tax_uz_individual' => 'Optional: 9-digit INN or 14-digit PINFL.',
        'tax_foreign' => 'Tax number of the country (5-20 letters or digits).',
        'search_country' => 'Search country',
        'search_owner' => 'Search owner',
        'sale_type' => 'B2B by default; a private person defaults to retail. Each order can override it.',
        'payment_terms' => 'Days to pay after the invoice; 0 = prepayment.',
        'position' => 'Choose from the list or type a new position.',
    ],
    'any_industry' => 'Any industry',

];
