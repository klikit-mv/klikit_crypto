<?php

/*
| Permission catalogue and default role grants. Authorization is always checked against
| permissions (via Gate/policies), never against role names. Run `php artisan db:seed
| --class=RolesAndPermissionsSeeder` after changing this file.
|
| Note: no permission grants access to users' exchange/broker secrets, and no admin
| permission allows placing trades on behalf of a user.
*/

return [

    'permissions' => [
        'admin' => [
            'admin.access' => 'Enter the admin dashboard',
        ],
        'users' => [
            'users.view' => 'View users',
            'users.manage' => 'Suspend, reactivate and edit users',
            'users.roles' => 'Assign roles to users',
        ],
        'subscriptions' => [
            'plans.manage' => 'Create and edit subscription plans',
            'subscriptions.view' => 'View user subscriptions and invoices',
            'payments.manage' => 'Confirm or refund payments',
        ],
        'wallet' => [
            'deposits.review' => 'Approve or reject deposits',
            'withdrawals.review' => 'Approve or reject withdrawals',
        ],
        'trading' => [
            'integrations.manage' => 'Configure exchanges and brokers',
            'trading.limits' => 'Configure platform-wide trading limits',
            'trading.halt' => 'Halt platform trading (emergency stop)',
            'trading.monitor' => 'View orders, positions and bots across users',
        ],
        'signals' => [
            'telegram.manage' => 'Manage Telegram integrations',
        ],
        'mining' => [
            'mining.manage' => 'Manage mining pools and devices',
        ],
        'system' => [
            'system.monitor' => 'View failed jobs and execution errors',
            'audit.view' => 'Review audit logs',
            'announcements.manage' => 'Manage platform announcements',
        ],
    ],

    // '*' grants every permission in the catalogue.
    'roles' => [
        'super_admin' => ['label' => 'Super Admin', 'permissions' => ['*']],
        'admin' => ['label' => 'Admin', 'permissions' => [
            'admin.access', 'users.view', 'users.manage', 'plans.manage', 'subscriptions.view', 'payments.manage',
            'deposits.review', 'withdrawals.review', 'integrations.manage', 'trading.limits', 'trading.halt',
            'trading.monitor', 'telegram.manage', 'mining.manage', 'system.monitor', 'audit.view', 'announcements.manage',
        ]],
        'manager' => ['label' => 'Manager', 'permissions' => [
            'admin.access', 'users.view', 'subscriptions.view', 'deposits.review', 'withdrawals.review',
            'trading.monitor', 'mining.manage', 'audit.view', 'announcements.manage',
        ]],
        'support' => ['label' => 'Support', 'permissions' => [
            'admin.access', 'users.view', 'subscriptions.view',
        ]],
        'premium_user' => ['label' => 'Premium User', 'permissions' => []],
        'standard_user' => ['label' => 'Standard User', 'permissions' => []],
    ],
];
