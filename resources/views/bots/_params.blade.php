{{-- Parameter inputs for every strategy; JS enables the block of the selected strategy. --}}
@foreach($strategies as $strategy)
    <div class="row g-2 strategy-params" data-strategy="{{ $strategy->id }}" style="display:none">
        @foreach($defaults[$strategy->strategy_type] ?? [] as $key => $value)
            <div class="col-6 col-md-4"><label class="form-label small mb-0">{{ str_replace('_', ' ', $key) }}</label>
                <input name="parameters[{{ $key }}]" value="{{ $values[$key] ?? $value }}" class="form-control form-control-sm" disabled></div>
        @endforeach
    </div>
@endforeach
<script>
(() => {
    const select = document.getElementById('{{ $selectId }}');
    const sync = () => document.querySelectorAll('.strategy-params').forEach(el => {
        const on = el.dataset.strategy === select.value;
        el.style.display = on ? '' : 'none';
        el.querySelectorAll('input').forEach(i => i.disabled = !on);
    });
    select.addEventListener('change', sync); sync();
})();
</script>
