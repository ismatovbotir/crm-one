<?php

/*
| 1C integration labels (Spec 02 section 12). The integration itself is a later increment.
*/

return [

    'badge' => [
        'synced_from_1c' => 'Synced from 1C',
    ],

    'last_received' => 'Last received from 1C',
    'read_only' => 'Read-only',
    'deleted_in_1c' => 'Deleted in 1C',
    'deleted_in_1c_banner' => 'This customer was deleted in 1C and is read-only.',
    'duplicate_tax_id' => 'Duplicate INN in 1C',
    'onec_code' => '1C code',
    'sync_status_label' => '1C sync',
    'any_sync_status' => 'Any sync status',

    'sync_status' => [
        'not_synced' => 'Not synced',
        'pending' => 'Pending',
        'synced' => 'Synced',
        'failed' => 'Failed',
        'conflict' => 'Conflict',
    ],

    'stale' => 'Data may be outdated',
    'stale_feed' => ':feed: last received :ago (threshold :minutes min).',
    'stale_feed_never' => ':feed: nothing received from 1C yet.',

    'feeds' => [
        'items' => 'Items',
        'stock' => 'Stock',
    ],

    'batch_status' => [
        'received' => 'Received',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'processed' => 'Processed',
        'partial' => 'Partially processed',
        'failed' => 'Failed',
        'rejected' => 'Rejected',
    ],

    'sync_mode' => [
        'delta' => 'Changes',
        'full' => 'Full snapshot',
    ],

    'abilities' => [
        'items_write' => 'Send items',
        'stock_write' => 'Send stock',
        'batches_read' => 'Read batch status',
        'customers_read' => 'Collect CRM customers (poll)',
        'customers_write' => 'Send customers and acknowledgements',
        'orders_read' => 'Read orders, agreements and invoices (outbox)',
        'payments_read' => 'Read payments (outbox)',
        'payments_write' => 'Send payments from 1C',
        'warehouse_read' => 'Read goods receipts (outbox)',
        'warehouse_write' => 'Send goods receipts from 1C',
    ],

    'settings' => [
        'title' => 'Integrations (1C)',
        'intro' => '1C sends items, prices, stock and customers to the CRM API and collects CRM customers from it; the CRM never calls 1C. Tokens are shown once; give them to the 1C team over a secure channel.',
        'saved' => 'Integration settings saved.',
        'inbound_title' => 'Inbound API (1C to CRM)',
        'api_base_url' => 'API base URL for 1C',
        'api_base_url_help' => 'Endpoints: /ping, /items, /item-stock, /batch, /batches/{batch_id}, /customers/pending, /customers/ack, /customers, /outbox/pending, /outbox/ack, /payments, /goods-receipts.',
        'inbound_enabled' => 'Accept data from 1C',
        'inbound_enabled_help' => 'When off, 1C receives 503 and retries later; nothing is stored.',
        'stale_title' => 'Alert when a feed is older than (minutes)',
        'stale_help' => 'Items, Item and Dashboard show a red warning after this time without new data.',
        'feeds_title' => 'Feed status',
    ],

    'tokens' => [
        'title' => 'API tokens',
        'help' => 'Give each token only the abilities it needs. Rotate: create a new token, switch 1C to it, then revoke the old one.',
        'name' => 'Name',
        'days' => 'Valid for (days)',
        'days_help' => 'Every token expires; at most :max days.',
        'abilities' => 'Abilities',
        'allowed_ips' => 'Allowed IP addresses',
        'allowed_ips_help' => 'IPs or CIDR ranges, one per line. Empty = any address (then request signing is required).',
        'with_secret' => 'Require request signing (HMAC)',
        'with_secret_help' => 'Mandatory unless the token is limited to private network addresses.',
        'create' => 'Create token',
        'ips_invalid' => 'Enter valid IP addresses or CIDR ranges.',
        'secret_required' => 'Turn on request signing, or limit the token to private network addresses.',
        'revoked' => 'Token revoked. 1C calls with it now fail.',
        'issued_title' => 'New token created',
        'issued_warning' => 'Copy it now. It is shown only once and cannot be recovered.',
        'token' => 'Token',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'signing_secret' => 'Signing secret',
        'issued_done' => 'I have saved it',
        'last_used' => 'Last used',
        'expires' => 'Expires',
        'any_ip' => 'Any address',
        'hmac_on' => 'Signing required',
        'status_revoked' => 'Revoked',
        'status_expired' => 'Expired',
        'rotate' => 'Rotate',
        'rotate_confirm' => 'Create a replacement token with the same settings? The current one stays active until you revoke it.',
        'revoke' => 'Revoke',
        'revoke_confirm' => 'Revoke this token? 1C will be rejected immediately.',
        'empty' => 'No tokens yet.',
    ],

    'batches' => [
        'title' => 'Recent batches from 1C',
        'received_at' => 'Received',
        'endpoint' => 'Endpoint',
        'mode' => 'Mode',
        'counts' => 'Records',
        'counts_line' => ':accepted accepted, :rejected rejected, :failed failed',
        'empty' => 'Nothing received yet.',
    ],

    'empty' => [
        'catalog' => 'Items arrive from 1C. Ask an admin to create an API token and share it with the 1C team.',
    ],

    'customer_sync' => [
        'title' => '1C exchange',
        'pending_since' => 'Waiting for 1C since :time',
        'poll_stale' => '1C has not collected changes since :time (expected at least every :minutes min).',
        'poll_never' => '1C has not collected any changes yet.',
        'last_synced' => 'Last synced',
        'last_contact' => 'Last contact from 1C',
        'resync' => 'Resync',
        'resync_queued' => 'Queued for 1C. It will be collected on the next 1C exchange.',
        'not_pushable' => [
            'sync_off' => 'Sync with 1C is off for this partner.',
            'integration_disabled' => 'The customer exchange with 1C is switched off.',
            'archived' => 'Archived or deleted customers are not sent to 1C.',
            'tax_id_missing' => 'Add the tax ID (INN) to send this customer to 1C.',
            'tax_id_invalid' => 'The tax ID (INN) must have 9 digits to send this customer to 1C.',
            'legal_type_not_supported' => 'This legal form is not sent to 1C.',
            'not_pushable' => 'This customer cannot be sent to 1C.',
        ],
    ],

    'customer_exchange' => [
        'allow_create' => '1C may create new partners',
        'allow_create_help' => 'When 1C sends a tax ID that no partner has, create a new customer. Off: such records are skipped (create_disabled).',
        'title' => 'Customer exchange (1C polls the CRM)',
        'help' => '1C is on a private network and is never called by the CRM. Its scheduled job sends its customers, collects new and changed CRM customers and confirms each one.',
        'enabled' => 'Exchange customers with 1C',
        'enabled_help' => 'When on, customers with a valid tax ID wait for 1C as "Pending". When off, 1C receives 503.',
        'poll_stale' => 'Alert when 1C has not polled for (minutes)',
        'poll_stale_help' => 'Set it a little above the schedule of the 1C job (e.g. job every 5 min, alert after 30).',
        'last_contact' => 'Last contact from 1C',
        'channels' => [
            'poll' => 'Collected customers',
            'ack' => 'Confirmed customers',
            'push' => 'Sent its customers',
        ],
        'poll_stale_badge' => '1C has stopped collecting customers',
        'outbox_line' => 'Waiting for 1C: :pending · failed: :failed',
        'oldest' => 'oldest :time',
        'saved' => 'Customer exchange settings saved. Customers queued: :count.',
    ],

    'document_outbox' => [
        'title' => 'Documents for 1C (outbox)',
        'intro' => '1C fetches confirmed orders, agreements, invoices and payments from GET /outbox/pending and acknowledges them; payments posted in 1C arrive through POST /payments.',
        'open' => 'Waiting for 1C',
        'blocked' => 'Waiting for customer',
        'rejected' => 'Rejected (30 days)',
        'oldest' => 'Oldest waiting',
        'last_poll' => 'Last fetch by 1C',
        'last_payments' => 'Last payments from 1C',
        'last_receipts' => 'Last goods receipts from 1C',
        'never' => 'never',
    ],

    'docs' => [
        'title' => '1C integration API',
        'back' => 'Settings > Integrations',
        'read_only' => 'Read-only documentation with example values. Requests cannot be sent from this page.',
        'link' => 'API documentation for the 1C developer (Swagger)',
        'hint' => 'OpenAPI 3.1 description of every endpoint, with examples. The written guide is docs/integration-1c.md (Russian: docs/integration-1c.ru.md).',
        'token_hint' => 'Give the 1C developer a token from the form below: choose only the abilities 1C needs, add the 1C server IP addresses, and tick "signing secret" when 1C connects over the internet. The token and secret are shown once; pass them over a secure channel.',
    ],

];
