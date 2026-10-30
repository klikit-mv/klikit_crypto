<?php

return [

    /*
    | Live order routing is off until reconciliation and failure-recovery tests have passed.
    | While false, every order is executed by the paper-trading adapter regardless of the
    | requested mode, and requests for live mode are rejected by the risk engine.
    */
    'live_trading_enabled' => env('TRADING_LIVE_ENABLED', false),

    // Queue used for order execution jobs (processed by dedicated workers / Horizon).
    'queue' => env('TRADING_QUEUE', 'trading'),

    // Platform-wide limits applied on top of each user's own risk profile (USD equivalent).
    'limits' => [
        'max_order_notional' => env('TRADING_MAX_ORDER_NOTIONAL', 100000),
        'max_leverage' => env('TRADING_MAX_LEVERAGE', 20),
        'max_open_positions' => env('TRADING_MAX_OPEN_POSITIONS', 50),
    ],

    // Default risk profile created for new users.
    'default_risk_profile' => [
        'max_amount_per_trade' => 1000,
        'max_daily_loss' => 500,
        'max_open_positions' => 5,
        'max_leverage' => 1,
        'require_stop_loss' => false,
        'max_total_exposure' => 10000,
    ],

    // Extra funds reserved for market orders to absorb price movement before the fill.
    'market_order_buffer' => env('TRADING_MARKET_ORDER_BUFFER', '0.02'),

    // Execute against prices older than MarketDataService::MAX_PRICE_AGE_MINUTES (local dev / tests only).
    'allow_stale_prices' => env('TRADING_ALLOW_STALE_PRICES', false),

    'paper' => [
        'fee_rate' => env('PAPER_FEE_RATE', '0.001'),
        // Balances granted when a user (re)sets their paper account.
        'starting_balances' => [
            'USDT' => '10000',
            'USD' => '10000',
        ],
    ],

    // Quote currencies treated as 1:1 with USD when converting limits.
    'usd_equivalents' => ['USD', 'USDT', 'USDC', 'BUSD', 'FDUSD'],

    // HTTP bridges to the separate execution service (CCXT for crypto, MT5 for Forex).
    'bridges' => [
        'ccxt' => [
            'url' => env('CCXT_BRIDGE_URL', 'http://127.0.0.1:8081'),
            'token' => env('CCXT_BRIDGE_TOKEN'),
            'timeout' => 10,
        ],
        'mt5' => [
            'url' => env('MT5_BRIDGE_URL', 'http://127.0.0.1:8082'),
            'token' => env('MT5_BRIDGE_TOKEN'),
            'timeout' => 10,
        ],
    ],

    'market_data' => [
        'binance_public_url' => env('BINANCE_PUBLIC_URL', 'https://api.binance.com'),
    ],
];
