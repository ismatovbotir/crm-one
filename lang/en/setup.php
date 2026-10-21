<?php

/*
| Initial setup wizard (roadmap step 7).
*/

return [

    'title' => 'Setup',
    'intro' => 'Go-live checklist: each step shows its current state and where to complete it.',
    'ready' => 'Ready for go-live: all required steps are done.',
    'not_ready' => 'Not ready yet: complete the required steps.',
    'done' => 'done',
    'required' => 'required',
    'optional' => 'optional',
    'open' => 'Open',
    'ceo' => 'CEO',
    'two_factor_enable' => 'Enforce 2FA',
    'two_factor_disable' => 'Stop enforcing',
    'confirm_two_factor_on' => 'Require two-step verification for admin, accountant and finance users? Users without 2FA must set it up at their next page view.',
    'confirm_two_factor_off' => 'Stop requiring two-step verification?',
    'two_factor_on' => 'Two-step verification is now required for admin, accountant and finance users.',
    'two_factor_off' => 'Two-step verification is no longer required.',
    'steps' => [
        'company' => [
            'title' => 'Company profile',
            'hint' => 'Legal name, tax ID, address and contacts printed on documents.',
        ],
        'signatory' => [
            'title' => 'CEO as default signatory',
            'hint' => 'Agreements are signed by the CEO.',
        ],
        'bank' => [
            'title' => 'Default bank account',
            'hint' => 'Printed on proformas in the base currency.',
        ],
        'templates' => [
            'title' => 'Agreement templates',
            'hint' => 'Publish the templates (run AgreementTemplateSeeder once in production).',
        ],
        'onec_token' => [
            'title' => '1C integration token',
            'hint' => 'Issue the token 1C uses to send data and pull the outbox.',
        ],
        'warehouses' => [
            'title' => 'Warehouses from 1C',
            'hint' => 'Warehouses arrive with the first 1C stock feed.',
        ],
        'roles' => [
            'title' => 'Key roles assigned',
            'hint' => 'At least one sales user, accountant, finance controller, stock manager and the CEO (head of management).',
        ],
        'scheduler' => [
            'title' => 'Scheduler running',
            'hint' => 'Cron / Task Scheduler runs "php artisan schedule:run" every minute.',
        ],
        'two_factor' => [
            'title' => 'Two-step verification enforced',
            'hint' => 'Require 2FA for admin, accountant and finance users.',
        ],
        'telegram' => [
            'title' => 'Telegram bot',
            'hint' => 'Optional: login codes, notifications and leads by Telegram.',
        ],
        'demo_data' => [
            'title' => 'Demo data removed',
            'hint' => 'Run the command below before go-live.',
        ],
    ],

];
