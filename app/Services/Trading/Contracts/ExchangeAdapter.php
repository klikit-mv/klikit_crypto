<?php

namespace App\Services\Trading\Contracts;

/** Crypto exchange (spot / futures), typically via CCXT. */
interface ExchangeAdapter extends TradingVenueAdapter {}
