<?php

namespace App\Modules\Trading\Http\Resources;

use App\Models\Order;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $num = fn ($v) => $v === null ? null : Decimal::display($v, 18);

        return [
            'id' => $this->uuid,
            'symbol' => $this->symbol?->symbol,
            'market_type' => $this->symbol?->market_type,
            'mode' => $this->mode,
            'source' => $this->source,
            'side' => $this->side,
            'type' => $this->type,
            'quantity' => $num($this->quantity),
            'price' => $num($this->price),
            'stop_loss' => $num($this->stop_loss),
            'take_profit' => $num($this->take_profit),
            'leverage' => $num($this->leverage),
            'filled_quantity' => $num($this->filled_quantity),
            'average_fill_price' => $num($this->average_fill_price),
            'status' => $this->status,
            'reject_reason' => $this->reject_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
