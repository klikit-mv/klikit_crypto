@extends('layouts.app')
@section('title', 'Failed jobs')
@section('content')
<p class="small text-secondary">Retry with <code>php artisan queue:retry &lt;uuid&gt;</code>. Order executions are intentionally not retried automatically — reconcile with the venue first.</p>
<div class="card"><table class="table table-sm mb-0 small"><thead><tr><th>Failed at</th><th>Queue</th><th>UUID</th><th>Exception</th></tr></thead><tbody>
@forelse($jobs as $job)
    <tr><td>{{ $job->failed_at }}</td><td>{{ $job->queue }}</td><td><code>{{ $job->uuid }}</code></td><td><code>{{ \Illuminate\Support\Str::limit(strtok($job->exception, "\n"), 200) }}</code></td></tr>
@empty<tr><td colspan="4" class="text-secondary">No failed jobs.</td></tr>@endforelse
</tbody></table></div>
@endsection
