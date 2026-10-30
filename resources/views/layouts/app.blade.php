<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f1419; }
        .sidebar { min-height: 100vh; background: #151b23; }
        .sidebar .nav-link { color: #9aa4b2; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { color: #fff; background: #1f2731; border-radius: .375rem; }
        .stat-card .value { font-size: 1.5rem; font-weight: 600; }
        .text-buy { color: #26a69a; } .text-sell { color: #ef5350; }
        .table { --bs-table-bg: transparent; }
        .num { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
@php($admin = request()->routeIs('admin.*'))
<div class="container-fluid">
    <div class="row">
        <nav class="col-md-2 sidebar p-3">
            <a href="{{ $admin ? route('admin.dashboard') : route('dashboard') }}" class="d-block mb-4 text-decoration-none text-white fw-bold fs-5">
                {{ config('app.name') }} @if($admin)<span class="badge bg-warning text-dark ms-1">Admin</span>@endif
            </a>
            <ul class="nav flex-column gap-1">
                @if($admin)
                    <li><a class="nav-link @if(request()->routeIs('admin.dashboard')) active @endif" href="{{ route('admin.dashboard') }}">Overview</a></li>
                    @can('users.view')<li><a class="nav-link @if(request()->routeIs('admin.users.*')) active @endif" href="{{ route('admin.users.index') }}">Users</a></li>@endcan
                    @can('plans.manage')<li><a class="nav-link @if(request()->routeIs('admin.plans.*')) active @endif" href="{{ route('admin.plans.index') }}">Subscription plans</a></li>@endcan
                    @can('subscriptions.view')<li><a class="nav-link @if(request()->routeIs('admin.payments.*')) active @endif" href="{{ route('admin.payments.index') }}">Payments</a></li>@endcan
                    @if(auth()->user()->can('deposits.review') || auth()->user()->can('withdrawals.review'))<li><a class="nav-link @if(request()->routeIs('admin.wallet.*')) active @endif" href="{{ route('admin.wallet.index') }}">Deposits &amp; withdrawals</a></li>@endif
                    @can('integrations.manage')<li><a class="nav-link @if(request()->routeIs('admin.integrations')) active @endif" href="{{ route('admin.integrations') }}">Exchanges &amp; brokers</a></li>@endcan
                    @if(auth()->user()->can('trading.limits') || auth()->user()->can('trading.halt'))<li><a class="nav-link @if(request()->routeIs('admin.trading.controls')) active @endif" href="{{ route('admin.trading.controls') }}">Trading controls</a></li>@endif
                    @can('trading.monitor')<li><a class="nav-link @if(request()->routeIs('admin.trading.orders')) active @endif" href="{{ route('admin.trading.orders') }}">Order monitor</a></li>@endcan
                    @can('trading.monitor')<li><a class="nav-link @if(request()->routeIs('admin.bots.*')) active @endif" href="{{ route('admin.bots.index') }}">Bot monitor</a></li>@endcan
                    @can('telegram.manage')<li><a class="nav-link @if(request()->routeIs('admin.telegram.*')) active @endif" href="{{ route('admin.telegram.index') }}">Telegram</a></li>@endcan
                    @can('mining.manage')<li><a class="nav-link @if(request()->routeIs('admin.mining.*')) active @endif" href="{{ route('admin.mining.index') }}">Mining pools</a></li>@endcan
                    @can('system.monitor')<li><a class="nav-link @if(request()->routeIs('admin.jobs')) active @endif" href="{{ route('admin.jobs') }}">Failed jobs</a></li>@endcan
                    @can('audit.view')<li><a class="nav-link @if(request()->routeIs('admin.audit')) active @endif" href="{{ route('admin.audit') }}">Audit logs</a></li>@endcan
                    @can('announcements.manage')<li><a class="nav-link @if(request()->routeIs('admin.announcements.*')) active @endif" href="{{ route('admin.announcements.index') }}">Announcements</a></li>@endcan
                    <li class="mt-3"><a class="nav-link" href="{{ route('dashboard') }}">&larr; User dashboard</a></li>
                @else
                    <li><a class="nav-link @if(request()->routeIs('dashboard')) active @endif" href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li><a class="nav-link @if(request()->routeIs('trading.index')) active @endif" href="{{ route('trading.index') }}">Trading</a></li>
                    <li><a class="nav-link @if(request()->routeIs('charts.*')) active @endif" href="{{ route('charts.index') }}">Charts &amp; alerts</a></li>
                    <li><a class="nav-link @if(request()->routeIs('bots.*')) active @endif" href="{{ route('bots.index') }}">Trading bots</a></li>
                    <li><a class="nav-link @if(request()->routeIs('signals.*')) active @endif" href="{{ route('signals.index') }}">Telegram signals</a></li>
                    <li><a class="nav-link @if(request()->routeIs('mining.*')) active @endif" href="{{ route('mining.index') }}">Mining</a></li>
                    <li><a class="nav-link @if(request()->routeIs('trading.risk')) active @endif" href="{{ route('trading.risk') }}">Risk settings</a></li>
                    <li><a class="nav-link @if(request()->routeIs('wallet.*')) active @endif" href="{{ route('wallet.index') }}">Wallet</a></li>
                    <li><a class="nav-link @if(request()->routeIs('subscription.*')) active @endif" href="{{ route('subscription.index') }}">Subscription</a></li>
                    @can('admin.access')<li class="mt-3"><a class="nav-link text-warning" href="{{ route('admin.dashboard') }}">Admin area &rarr;</a></li>@endcan
                @endif
            </ul>
            <hr>
            <div class="small text-secondary mb-2">{{ auth()->user()->email }}</div>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-outline-secondary w-100">Log out</button></form>
        </nav>
        <main class="col-md-10 p-4">
            <h1 class="h3 mb-4">@yield('title')</h1>
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
