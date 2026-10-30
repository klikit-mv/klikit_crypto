@extends('layouts.guest')
@section('title', 'Create account')
@section('content')
<form method="post" action="{{ route('register') }}">
    @csrf
    <div class="mb-3"><label class="form-label">Name</label><input name="name" value="{{ old('name') }}" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" value="{{ old('email') }}" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required><div class="form-text">At least 10 characters with letters and numbers.</div></div>
    <div class="mb-3"><label class="form-label">Confirm password</label><input type="password" name="password_confirmation" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Country (ISO code)</label><input name="country" value="{{ old('country') }}" maxlength="2" class="form-control"></div>
    <button class="btn btn-primary w-100">Create account</button>
</form>
<p class="text-center mt-3 mb-0 small">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
