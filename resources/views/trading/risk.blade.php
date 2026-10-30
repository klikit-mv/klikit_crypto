@extends('layouts.app')
@section('title', 'Risk settings')
@section('content')
<div class="card" style="max-width:640px"><div class="card-body">
<p class="text-secondary small">These limits apply to every order — manual, signal-driven and automated bots. Amounts are in USD equivalent.</p>
<form method="post" action="{{ route('trading.risk.update') }}">
    @csrf @method('put')
    @foreach([
        'max_amount_per_trade' => 'Maximum amount per trade',
        'max_daily_loss' => 'Maximum daily loss',
        'max_open_positions' => 'Maximum open positions',
        'max_leverage' => 'Maximum leverage',
        'max_total_exposure' => 'Maximum total exposure',
    ] as $field => $label)
        <div class="mb-3"><label class="form-label">{{ $label }}</label><input name="{{ $field }}" value="{{ old($field, \App\Support\Decimal::display($profile->$field, 2)) }}" class="form-control" required></div>
    @endforeach
    <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="require_stop_loss" value="1" id="sl" @checked($profile->require_stop_loss)><label for="sl" class="form-check-label">Require a stop-loss on every order</label></div>
    <div class="form-check mb-3"><input type="checkbox" class="form-check-input" name="trading_enabled" value="1" id="te" @checked($profile->trading_enabled)><label for="te" class="form-check-label">Trading enabled (untick for emergency shutdown)</label></div>
    <button class="btn btn-primary">Save</button>
</form>
</div></div>
@endsection
