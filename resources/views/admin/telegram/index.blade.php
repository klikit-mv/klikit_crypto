@extends('layouts.app')
@section('title', 'Telegram integration')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-lg-5"><div class="card"><div class="card-header">Bots</div><div class="card-body">
        <ul class="small">@forelse($bots as $b)<li>#{{ $b->id }} @{{ $b->username ?? 'unknown' }} — {{ $b->status }}</li>@empty<li class="text-secondary">No bot connected.</li>@endforelse</ul>
        <form method="post" action="{{ route('admin.telegram.bots.store') }}" class="d-flex gap-2">@csrf
            <input name="bot_token" class="form-control form-control-sm" placeholder="Bot token from @BotFather" required><button class="btn btn-sm btn-primary text-nowrap">Connect bot</button></form>
        <p class="small text-secondary mt-2 mb-0">Connecting validates the token and registers the webhook. Add the bot to each channel as an administrator. The token is stored encrypted.</p>
    </div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">Add signal channel</div><div class="card-body">
        <form method="post" action="{{ route('admin.telegram.channels.store') }}" class="row g-2">@csrf
            <div class="col-md-6"><input name="channel_name" class="form-control form-control-sm" placeholder="Display name" required></div>
            <div class="col-md-6"><input name="channel_id" class="form-control form-control-sm" placeholder="Chat ID, e.g. -1001234567890" required></div>
            <div class="col-md-6"><select name="telegram_bot_id" class="form-select form-select-sm"><option value="">No bot (ingest API only)</option>@foreach($bots as $b)<option value="{{ $b->id }}">@{{ $b->username }}</option>@endforeach</select></div>
            <div class="col-md-3"><input name="signal_ttl_minutes" value="60" class="form-control form-control-sm" title="Signal validity (minutes)"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        </form>
    </div></div></div>
</div>
<div class="card mb-3"><div class="card-header">Channels</div><table class="table table-sm mb-0 small"><thead><tr><th>Name</th><th>Chat ID</th><th>Subscribers</th><th>Signals</th><th>Ingest endpoint</th><th>Status</th></tr></thead><tbody>
@foreach($channels as $c)
    <tr><td>{{ $c->channel_name }}</td><td>{{ $c->channel_id }}</td><td>{{ $c->subscriptions_count }}</td><td>{{ $c->signals_count }}</td>
        <td><code>POST {{ url('/api/v1/signals/ingest/'.$c->id) }}</code><br><span class="text-secondary">Header X-Signal-Secret: {{ $c->webhook_secret }}</span></td>
        <td><form method="post" action="{{ route('admin.telegram.channels.toggle', $c) }}">@csrf<button class="btn btn-sm btn-outline-{{ $c->is_active ? 'success' : 'secondary' }}">{{ $c->is_active ? 'active' : 'disabled' }}</button></form></td></tr>
@endforeach
</tbody></table></div>
<div class="card"><div class="card-header">Recent messages</div><table class="table table-sm mb-0 small"><tbody>
@foreach($logs as $l)
    <tr><td class="text-nowrap">{{ $l->created_at->format('m-d H:i') }}</td><td><span class="badge bg-{{ $l->result === 'parsed' ? 'success' : 'secondary' }}">{{ $l->result }}</span></td>
        <td><pre class="mb-0 small" style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit($l->raw_message, 200) }}</pre></td><td class="text-secondary">{{ $l->parsed_data['error'] ?? '' }}</td></tr>
@endforeach
</tbody></table></div>
@endsection
