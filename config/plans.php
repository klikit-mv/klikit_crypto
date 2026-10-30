<?php

/*
| Feature keys understood by the entitlement layer. Plans store their values in
| `plan_features`; admins can change prices and entitlements without code changes.
| Boolean features use 'true'/'false'; numeric features use integers ('-1' = unlimited).
*/

return [

    'features' => [
        'trading.paper' => 'Paper trading',
        'trading.manual' => 'Manual live trading',
        'charts.advanced' => 'Advanced charts and indicators',
        'signals.telegram' => 'Telegram signal integration',
        'signals.daily_limit' => 'Signals per day',
        'bots.automated' => 'Automated trading bots',
        'bots.max' => 'Maximum active bots',
        'analytics.advanced' => 'Advanced analytics',
        'mining.management' => 'Mining management',
    ],

    // Entitlements for users without an active subscription.
    'free' => [
        'trading.paper' => 'true',
        'signals.daily_limit' => '0',
        'bots.max' => '0',
    ],

    // Seeded plans (illustrative pricing).
    'defaults' => [
        'starter' => [
            'name' => 'Starter', 'price' => '19', 'sort_order' => 1, 'is_featured' => false,
            'description' => 'Manual trading, basic charts and limited signals.',
            'features' => [
                'trading.paper' => 'true', 'trading.manual' => 'true', 'signals.daily_limit' => '5', 'bots.max' => '0',
            ],
        ],
        'pro' => [
            'name' => 'Pro', 'price' => '49', 'sort_order' => 2, 'is_featured' => true,
            'description' => 'Automated bots, advanced charts and Telegram signals.',
            'features' => [
                'trading.paper' => 'true', 'trading.manual' => 'true', 'charts.advanced' => 'true',
                'signals.telegram' => 'true', 'signals.daily_limit' => '50', 'bots.automated' => 'true', 'bots.max' => '3',
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise', 'price' => '99', 'sort_order' => 3, 'is_featured' => false,
            'description' => 'Multiple bots, advanced analytics and mining management.',
            'features' => [
                'trading.paper' => 'true', 'trading.manual' => 'true', 'charts.advanced' => 'true',
                'signals.telegram' => 'true', 'signals.daily_limit' => '-1', 'bots.automated' => 'true', 'bots.max' => '20',
                'analytics.advanced' => 'true', 'mining.management' => 'true',
            ],
        ],
    ],
];
