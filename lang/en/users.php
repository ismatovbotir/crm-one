<?php

return [

    'title' => 'Users',
    'intro' => 'Create accounts, assign roles and deactivate leavers. Users are never deleted, so their records stay attributable.',
    'new' => 'New user',
    'edit' => 'Edit user',
    'back' => 'Back to users',

    'search_placeholder' => 'Search name or email',
    'role' => 'Role',
    'all_roles' => 'All roles',
    'you' => 'you',
    'empty' => 'No users match your filters.',

    'activate' => 'Activate',
    'deactivate' => 'Deactivate',
    'deactivate_confirm' => 'Deactivate :name? They will be signed out and unable to log in.',

    'password_optional' => 'New password (optional)',
    'cannot_change_own_role' => 'You cannot change your own role.',
    'create' => 'Create user',
    'save_changes' => 'Save changes',

    'created' => 'User :name created.',
    'updated' => 'User :name updated.',
    'activated' => ':name has been activated.',
    'deactivated' => ':name has been deactivated.',

    'type' => 'Type',
    'all_types' => 'All types',
    'department' => 'Department',
    'all_departments' => 'All departments',
    'more_departments' => '+:count more',
    'phone' => 'Phone',
    'position' => 'Position',
    'primary_department' => 'Primary department',
    'department_role' => 'Role in department',
    'no_department' => 'No department',
    'other_departments' => 'Also member of',
    'staff_hint' => 'Staff have no global rights: everything comes from their department memberships.',
    'staff_needs_department' => 'A staff user needs a primary department, otherwise they have no rights at all.',
    'portal_hint' => 'Portal users sign in through the customer portal with one-time codes; they have no role or password here.',

    'roles' => [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'sales' => 'Sales',
        'finance' => 'Finance',
        'accountant' => 'Accountant',
        'stock_manager' => 'Stock manager',
        'service_engineer' => 'Service engineer',
        'staff' => 'Staff',
    ],

    'types' => [
        'internal' => 'Internal (staff)',
        'portal' => 'Portal (customer / supplier user)',
    ],

    'telegram_linked' => 'Telegram linked',
    'telegram_unlink' => 'Unlink Telegram',
    'telegram_unlink_confirm' => 'Unlink Telegram from :name? They will no longer get codes there.',
    'telegram_unlinked' => 'Telegram unlinked from :name.',

    'two_factor_on' => '2FA on',

    'two_factor_reset' => 'Reset 2FA',
    'two_factor_reset_confirm' => 'Reset two-step verification for :name? Use this only after checking the person (lost or replaced phone). They set it up again at the next sign-in.',
    'two_factor_reset_done' => 'Two-step verification of :name was reset.',

    'locale' => 'Default language for the CRM',
    'locale_hint' => 'Used at sign-in (unless you chose another language in this session) and for notifications, e-mails and Telegram messages.',
    'locale_saved' => 'Default language saved.',
    'telegram_pending' => 'Telegram pending verification',

];
