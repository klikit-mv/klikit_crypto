@extends('layouts.app')
@section('title', $plan->exists ? 'Edit '.$plan->name : 'New plan')
@section('content')
<form method="post" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="card" style="max-width:760px"><div class="card-body">
    @csrf @if($plan->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', $plan->name) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Slug</label><input name="slug" value="{{ old('slug', $plan->slug) }}" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Description</label><input name="description" value="{{ old('description', $plan->description) }}" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Price</label><input name="price" value="{{ old('price', $plan->price ? \App\Support\Decimal::display($plan->price, 2) : '') }}" class="form-control" required></div>
        <div class="col-md-2"><label class="form-label">Currency</label><input name="currency" value="{{ old('currency', $plan->currency) }}" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">Billing period</label><select name="billing_period" class="form-select">@foreach(['monthly','quarterly','yearly'] as $p)<option @selected(old('billing_period', $plan->billing_period) === $p)>{{ $p }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Sort order</label><input name="sort_order" value="{{ old('sort_order', $plan->sort_order ?? 0) }}" class="form-control"></div>
        <div class="col-12">
            <div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="ia" @checked(old('is_active', $plan->is_active))><label class="form-check-label" for="ia">Active</label></div>
            <div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="is_featured" value="1" id="if" @checked(old('is_featured', $plan->is_featured))><label class="form-check-label" for="if">Featured</label></div>
        </div>
    </div>
    <h2 class="h6 mt-4">Entitlements</h2>
    <p class="small text-secondary">Use <code>true</code>/<code>false</code> for switches and numbers for limits (<code>-1</code> = unlimited). Leave blank to omit.</p>
    <div class="row g-2">
        @foreach($featureLabels as $key => $label)
            <div class="col-md-6"><label class="form-label small">{{ $label }} <code>{{ $key }}</code></label>
                <input name="features[{{ $key }}]" value="{{ old("features.$key", $plan->exists ? $plan->featureValue($key) : '') }}" class="form-control form-control-sm"></div>
        @endforeach
    </div>
    <button class="btn btn-primary mt-4">Save plan</button>
</div></form>
@endsection
