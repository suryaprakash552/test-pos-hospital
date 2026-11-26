<?php

return [
    /*
    |--------------------------------------------------------------------------
    | POS Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration settings for the Point of Sale system.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'products' => env('POS_PAGINATION_PRODUCTS', 50),
        'sales' => env('POS_PAGINATION_SALES', 100),
        'purchases' => env('POS_PAGINATION_PURCHASES', 50),
        'users' => env('POS_PAGINATION_USERS', 20),
        'customers' => env('POS_PAGINATION_CUSTOMERS', 20),
        'default' => env('POS_PAGINATION_DEFAULT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    */
    'invoice' => [
        'prefix' => env('POS_INVOICE_PREFIX', 'INV'),
        'number_length' => env('POS_INVOICE_NUMBER_LENGTH', 4),
        'date_format' => env('POS_INVOICE_DATE_FORMAT', 'Ymd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stock Settings
    |--------------------------------------------------------------------------
    */
    'stock' => [
        'alert_threshold' => env('POS_STOCK_ALERT_THRESHOLD', 10),
        'enable_low_stock_alert' => env('POS_ENABLE_LOW_STOCK_ALERT', true),
        'auto_decrement_on_sale' => env('POS_AUTO_DECREMENT_ON_SALE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding Options
    |--------------------------------------------------------------------------
    */
    'rounding' => [
        'options' => [
            'none' => 'none',
            'round_up' => 'round_up',
            'round_down' => 'round_down',
            'nearest_whole_number' => 'nearest_whole_number',
            'nearest_0.05' => 'nearest_0.05',
            'nearest_0.1' => 'nearest_0.1',
            'nearest_0.5' => 'nearest_0.5',
        ],
        'default' => env('POS_ROUNDING_DEFAULT', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    */
    'currency' => [
        'default_code' => env('POS_CURRENCY_DEFAULT_CODE', 'USD'),
        'default_symbol' => env('POS_CURRENCY_DEFAULT_SYMBOL', '$'),
        'default_position' => env('POS_CURRENCY_DEFAULT_POSITION', 'left'),
        'decimal_places' => env('POS_CURRENCY_DECIMAL_PLACES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax/VAT Settings
    |--------------------------------------------------------------------------
    */
    'tax' => [
        'default_type' => env('POS_TAX_DEFAULT_TYPE', 'exclusive'),
        'enable_vat' => env('POS_ENABLE_VAT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Settings
    |--------------------------------------------------------------------------
    */
    'product' => [
        'enable_variants' => env('POS_ENABLE_PRODUCT_VARIANTS', true),
        'enable_serial_numbers' => env('POS_ENABLE_SERIAL_NUMBERS', false),
        'enable_expiry_tracking' => env('POS_ENABLE_EXPIRY_TRACKING', true),
        'enable_batch_tracking' => env('POS_ENABLE_BATCH_TRACKING', true),
        'image_max_size' => env('POS_PRODUCT_IMAGE_MAX_SIZE', 2048), // in KB
        'image_allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sale Settings
    |--------------------------------------------------------------------------
    */
    'sale' => [
        'enable_returns' => env('POS_ENABLE_SALE_RETURNS', true),
        'enable_discounts' => env('POS_ENABLE_DISCOUNTS', true),
        'enable_multiple_payments' => env('POS_ENABLE_MULTIPLE_PAYMENTS', true),
        'require_customer' => env('POS_REQUIRE_CUSTOMER_FOR_SALE', false),
        'auto_calculate_tax' => env('POS_AUTO_CALCULATE_TAX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Roles
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'superadmin' => 'superadmin',
        'admin' => 'admin',
        'shop_owner' => 'shop-owner',
        'staff' => 'staff',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'multi_branch' => env('POS_FEATURE_MULTI_BRANCH', false),
        'multi_warehouse' => env('POS_FEATURE_MULTI_WAREHOUSE', false),
        'hrm' => env('POS_FEATURE_HRM', false),
        'accounting' => env('POS_FEATURE_ACCOUNTING', false),
        'reports' => env('POS_FEATURE_REPORTS', true),
        'analytics' => env('POS_FEATURE_ANALYTICS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute, counted per authenticated user.
    |
    | This was 60, which a single till exceeds during ordinary trade: opening the
    | sale screen alone costs several requests, and a cashier working steadily —
    | or two tills signed into the same account — runs out mid-sale and is met
    | with a 429. The limit exists to stop abuse, not to cap normal use, so it is
    | set well above what one busy till needs while still bounding a runaway
    | client.
    |
    */
    'rate_limit' => [
        'per_minute' => (int) env('POS_RATE_LIMIT_PER_MINUTE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency keys
    |--------------------------------------------------------------------------
    |
    | `idempotency_keys` stores a full response body per money-moving write and
    | nothing ever removed a row, so the table grew for the life of the install.
    |
    | `retention_hours` is how long a completed key can still be replayed. It has
    | to comfortably outlast any client retry: delete a key too early and a late
    | retry stops being a replay and becomes a second sale.
    |
    | `stale_processing_minutes` releases a claim whose request died before it
    | could finish. Until then that key answers 409 forever, so a till that
    | crashed mid-checkout could never retry the same basket.
    */
    'idempotency' => [
        'retention_hours' => (int) env('POS_IDEMPOTENCY_RETENTION_HOURS', 168),
        'stale_processing_minutes' => (int) env('POS_IDEMPOTENCY_STALE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loyalty
    |--------------------------------------------------------------------------
    |
    | §5.3: when to remind a customer that their package is running out. Days
    | before expiry; 0 is the expiry date itself and a negative value is after it.
    */
    'loyalty' => [
        'reminder_days' => [30, 15, 7, 0, -7],

        // §5.3 names sms, in_app, email and whatsapp. Only the ones listed here
        // are attempted, and `LoyaltyReminderChannels` drops any this build cannot
        // actually carry out — so enabling whatsapp without a provider records
        // nothing rather than recording sends that never happened.
        // Defaults to sms alone, which is what reminders did before the other
        // channels existed — turning one on is a decision a shop makes, not one
        // an upgrade makes for it.
        'reminder_channels' => explode(',', env('POS_LOYALTY_REMINDER_CHANNELS', 'sms')),

        // §4.2: how an upgrade is priced. 'prorata' credits the unused part of the
        // current term against the new package — the worked example in the spec —
        // while 'full' charges the complete cost of the new one.
        'upgrade_cost_method' => env('POS_LOYALTY_UPGRADE_COST', 'prorata'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | These default to `default` on purpose: a plain `php artisan queue:work`
    | reads only that queue, so naming them anything else meant bulk uploads and
    | notifications were accepted, queued, and then never processed — with the
    | API already having answered "queued successfully".
    |
    | Set these to dedicated names only alongside a worker that consumes them,
    | e.g. `queue:work --queue=imports,notifications,default`.
    */
    'queues' => [
        'imports'       => env('POS_QUEUE_IMPORTS', 'default'),
        'notifications' => env('POS_QUEUE_NOTIFICATIONS', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('POS_CACHE_ENABLED', true),
        'ttl' => env('POS_CACHE_TTL', 3600), // 1 hour
        'tags' => [
            'products' => 'pos_products',
            'categories' => 'pos_categories',
            'settings' => 'pos_settings',
            'users' => 'pos_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'security' => [
        'allow_maintenance_routes' => env('POS_ALLOW_MAINTENANCE_ROUTES', false),
        'demo_mode' => env('POS_DEMO_MODE', false),
        'max_login_attempts' => env('POS_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_time' => env('POS_LOCKOUT_TIME', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | API Settings
    |--------------------------------------------------------------------------
    */
    'api' => [
        'rate_limit' => env('POS_API_RATE_LIMIT', 60),
        'rate_limit_period' => env('POS_API_RATE_LIMIT_PERIOD', 1), // minutes
        'enable_pagination' => env('POS_API_ENABLE_PAGINATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'low_stock' => env('POS_NOTIFY_LOW_STOCK', true),
        'expiry_alert' => env('POS_NOTIFY_EXPIRY_ALERT', true),
        'due_payment' => env('POS_NOTIFY_DUE_PAYMENT', true),
        'channels' => ['database', 'mail'],
        'queue_mail' => env('QUEUE_MAIL', false),
    ],
];
