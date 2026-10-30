<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#0f1419">
<div class="container" style="max-width:440px; margin-top:8vh">
    <h1 class="h3 text-center mb-4">{{ config('app.name') }}</h1>
    <div class="card"><div class="card-body p-4">
        <h2 class="h5 mb-3">@yield('title')</h2>
        @include('partials.flash')
        @yield('content')
    </div></div>
</div>
</body>
</html>
