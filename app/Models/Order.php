<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const PENDING = 'pending';

    public const OPEN = 'open';

    public const PARTIALLY_FILLED = 'partially_filled';

    public const FILLED = 'filled';

    public const CANCELLED = 'cancelled';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::PENDING, self::OPEN, self::PARTIALLY_FILLED];

    protected $fillable = [
        'uuid',
        'user_id',
        'symbol_id',
        'exchange_account_id',
        'broker_account_id',
        'idempotency_key',
        'mode',
        'source',
        'side',
        'type',
        'quantity',
        'price',
        'stop_price',
        'stop_loss',
        'take_profit',
        'leverage',
        'filled_quantity',
        'average_fill_price',
        'reserved_amount',
        'reserved_currency',
        'status',
        'external_order_id',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:18',
            'price' => 'decimal:18',
            'stop_price' => 'decimal:18',
            'stop_loss' => 'decimal:18',
            'take_profit' => 'decimal:18',
            'leverage' => 'decimal:2',
            'filled_quantity' => 'decimal:18',
            'average_fill_price' => 'decimal:18',
            'reserved_amount' => 'decimal:18',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(TradingSymbol::class, 'symbol_id');
    }

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(OrderExecution::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
