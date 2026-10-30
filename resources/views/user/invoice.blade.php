@extends('layouts.app')
@section('title', 'Invoice '.$invoice->number)
@section('content')
<div class="card" style="max-width:640px"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-4">Description</dt><dd class="col-8">{{ $invoice->description }}</dd>
        <dt class="col-4">Subtotal</dt><dd class="col-8">{{ \App\Support\Decimal::display($invoice->subtotal, 2) }} {{ $invoice->currency }}</dd>
        <dt class="col-4">Discount</dt><dd class="col-8">{{ \App\Support\Decimal::display($invoice->discount, 2) }} {{ $invoice->currency }}</dd>
        <dt class="col-4">Total</dt><dd class="col-8 fw-bold">{{ \App\Support\Decimal::display($invoice->total, 2) }} {{ $invoice->currency }}</dd>
        <dt class="col-4">Status</dt><dd class="col-8">{{ $invoice->status }}</dd>
    </dl>
    @if($invoice->status === 'open' && $instructions)
        <div class="alert alert-warning mt-3 mb-0">{{ $instructions['message'] }}</div>
    @endif
</div></div>
@endsection
