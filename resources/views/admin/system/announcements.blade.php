@extends('layouts.app')
@section('title', 'Announcements')
@section('content')
<div class="row g-3">
    <div class="col-lg-5"><div class="card"><div class="card-header">Publish announcement</div><div class="card-body">
        <form method="post" action="{{ route('admin.announcements.store') }}">@csrf
            <div class="mb-2"><input name="title" class="form-control" placeholder="Title" required></div>
            <div class="mb-2"><textarea name="body" class="form-control" rows="4" placeholder="Message" required></textarea></div>
            <div class="row g-2 mb-3"><div class="col"><select name="level" class="form-select"><option>info</option><option>warning</option><option>critical</option></select></div>
                <div class="col"><input type="datetime-local" name="expires_at" class="form-control" title="Expires at (optional)"></div></div>
            <button class="btn btn-primary">Publish</button>
        </form>
    </div></div></div>
    <div class="col-lg-7"><div class="card"><ul class="list-group list-group-flush">
        @forelse($announcements as $a)
            <li class="list-group-item d-flex justify-content-between"><div><span class="badge bg-secondary">{{ $a->level }}</span> <strong>{{ $a->title }}</strong><div class="small text-secondary">{{ $a->body }}</div></div>
                <form method="post" action="{{ route('admin.announcements.destroy', $a) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Remove</button></form></li>
        @empty<li class="list-group-item text-secondary">No announcements.</li>@endforelse
    </ul></div></div>
</div>
@endsection
