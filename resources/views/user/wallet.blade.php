@extends('layouts.app')
@section('title', 'Wallet')
@section('content')
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">Wallets
            <form method="post" action="{{ route('wallet.paper.reset') }}">@csrf<button class="btn btn-sm btn-outline-info">Top up paper balance</button></form></div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Type</th><th>Currency</th><th class="text-end">Balance</th><th class="text-end">Locked</th><th class="text-end">Available</th></tr></thead><tbody>
            @foreach($wallets as $w)
                <tr><td><span class="badge bg-{{ $w->wallet_type === 'paper' ? 'secondary' : 'primary' }}">{{ $w->wallet_type }}</span></td><td>{{ $w->currency }}</td>
                    <td class="text-end">{{ \App\Support\Decimal::display($w->balance) }}</td><td class="text-end">{{ \App\Support\Decimal::display($w->locked_balance) }}</td><td class="text-end">{{ \App\Support\Decimal::display($w->available()) }}</td></tr>
            @endforeach
            </tbody></table>
        </div>
        <div class="card"><div class="card-header">Ledger history</div>
            <table class="table table-sm mb-0 num"><thead><tr><th>Time</th><th>Type</th><th>Description</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead><tbody>
            @forelse($transactions as $t)
                <tr><td>{{ $t->created_at->format('m-d H:i') }}</td><td>{{ $t->transaction->transaction_type }}</td><td class="small">{{ $t->transaction->description }}</td>
                    <td class="text-end {{ str_starts_with((string) $t->amount, '-') ? 'text-sell' : 'text-buy' }}">{{ \App\Support\Decimal::display($t->amount) }} {{ $t->wallet->currency }}</td><td class="text-end">{{ \App\Support\Decimal::display($t->balance_after) }}</td></tr>
            @empty<tr><td colspan="5" class="text-secondary">No transactions.</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header">Request deposit</div><div class="card-body">
            <form method="post" action="{{ route('wallet.deposit') }}" class="row g-2">@csrf
                <div class="col-4"><input name="currency" class="form-control" placeholder="USDT" required></div>
                <div class="col-8"><input name="amount" class="form-control" placeholder="Amount" required></div>
                <div class="col-12"><input name="reference" class="form-control" placeholder="Transfer reference / tx hash"></div>
                <div class="col-12"><button class="btn btn-outline-primary w-100">Submit for review</button></div>
            </form>
        </div></div>
        <div class="card mb-3"><div class="card-header">Request withdrawal</div><div class="card-body">
            <form method="post" action="{{ route('wallet.withdraw') }}" class="row g-2">@csrf
                <div class="col-4"><input name="currency" class="form-control" placeholder="USDT" required></div>
                <div class="col-8"><input name="amount" class="form-control" placeholder="Amount" required></div>
                <div class="col-12"><input name="destination" class="form-control" placeholder="Destination address / IBAN" required></div>
                <div class="col-12"><button class="btn btn-outline-primary w-100">Submit for review</button></div>
            </form>
        </div></div>
        <div class="card"><div class="card-header">Recent requests</div><ul class="list-group list-group-flush small">
            @foreach($deposits as $d)<li class="list-group-item">Deposit {{ \App\Support\Decimal::display($d->amount) }} {{ $d->currency }} — {{ $d->status }}</li>@endforeach
            @foreach($withdrawals as $w)<li class="list-group-item">Withdrawal {{ \App\Support\Decimal::display($w->amount) }} {{ $w->currency }} — {{ $w->status }}</li>@endforeach
        </ul></div>
    </div>
</div>
@endsection
