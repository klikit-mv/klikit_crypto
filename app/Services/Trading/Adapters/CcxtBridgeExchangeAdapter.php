<?php

namespace App\Services\Trading\Adapters;

use App\Services\Trading\Contracts\ExchangeAdapter;

/** Crypto exchanges through the CCXT-based execution service. */
class CcxtBridgeExchangeAdapter extends HttpBridgeAdapter implements ExchangeAdapter {}
