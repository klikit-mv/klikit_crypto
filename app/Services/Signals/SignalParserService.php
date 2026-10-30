<?php

namespace App\Services\Signals;

use App\Models\TradingSymbol;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Turns free-text channel messages into structured signals. Understands the common layouts:
 *
 *   BUY BTC/USDT  Entry: 67000 - 67500  TP1: 68000  TP2: 69000  SL: 66000  Leverage: 10x
 *   #ETHUSDT LONG Entry 3500 Targets 3600 3700 3800 Stop 3400
 *   EURUSD SELL @ 1.0850 TP 1.0800 SL 1.0900
 *
 * Anything that would require guessing (no/both directions, several or unknown instruments,
 * missing stop-loss or targets, prices on the wrong side of the entry) is rejected with
 * AmbiguousSignal instead.
 */
class SignalParserService
{
    private const NUM = '(\d+(?:[.,]\d+)?)';

    public function parse(string $message): ParsedSignal
    {
        $text = strtoupper(str_replace(["\u{2013}", "\u{2014}", '–', '—'], '-', $message));
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        $side = $this->side($text);
        $leverage = $this->leverage($text);
        $symbol = $this->symbol($text, $side, $leverage);

        [$entry, $entryMax] = $this->entry($text);
        $stop = $this->firstNumberAfter($text, '(?:SL|STOP[ -]?LOSS|STOP)')
            ?? throw new AmbiguousSignal('No stop-loss found.');
        $targets = $this->targets($text);
        if ($targets === []) {
            throw new AmbiguousSignal('No take-profit targets found.');
        }

        $this->assertConsistent($side, $entry, $entryMax, $stop, $targets);

        if ($symbol->isSpot() && $leverage !== '1') {
            throw new AmbiguousSignal('Leverage given for an instrument that is only available as spot.');
        }
        if (Decimal::of($leverage)->isGreaterThan(Decimal::of($symbol->max_leverage))) {
            throw new AmbiguousSignal("Leverage {$leverage}x exceeds the instrument maximum.");
        }

        return new ParsedSignal($symbol, $side, $entry, $entryMax, $stop, $targets, $leverage);
    }

    private function side(string $text): string
    {
        $buy = preg_match('/\b(BUY|LONG)\b/', $text);
        $sell = preg_match('/\b(SELL|SHORT)\b/', $text);

        return match (true) {
            $buy && ! $sell => 'buy',
            $sell && ! $buy => 'sell',
            default => throw new AmbiguousSignal($buy ? 'Message contains both buy and sell instructions.' : 'No trade direction (buy/sell/long/short) found.'),
        };
    }

    private function leverage(string $text): string
    {
        if (preg_match('/\b(?:LEVERAGE|LEV)\b\s*[:=]?\s*(?:CROSS|ISOLATED)?\s*(\d+(?:\.\d+)?)\s*X?/', $text, $m)
            || preg_match('/\b(\d+(?:\.\d+)?)\s*X\b/', $text, $m)) {
            return Decimal::display($m[1], 2);
        }

        return '1';
    }

    private function symbol(string $text, string $side, string $leverage): TradingSymbol
    {
        $symbols = TradingSymbol::active()->get();
        $found = $symbols->filter(function (TradingSymbol $s) use ($text) {
            $base = preg_quote($s->base_currency, '/');
            $quote = preg_quote($s->quote_currency, '/');

            return (bool) preg_match("/(?<![A-Z0-9])#?{$base}\s*[\/\-_]?\s*{$quote}(?:\.P|PERP)?(?![A-Z0-9])/", $text);
        });

        $pairs = $found->map(fn ($s) => $s->base_currency.$s->quote_currency)->unique();
        if ($pairs->isEmpty()) {
            throw new AmbiguousSignal('No supported instrument found in the message.');
        }
        if ($pairs->count() > 1) {
            throw new AmbiguousSignal('Message mentions more than one instrument: '.$pairs->join(', ').'.');
        }

        return $this->pickMarket($found, $text, $side, $leverage);
    }

    /** The same pair can exist as SPOT and FUTURES; choose only when the message makes it clear. */
    private function pickMarket(Collection $candidates, string $text, string $side, string $leverage): TradingSymbol
    {
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $byType = $candidates->keyBy('market_type');
        $derivativeHint = $leverage !== '1' || $side === 'sell' || preg_match('/\b(FUTURES?|PERP|PERPETUAL|SHORT|LONG)\b|\.P\b/', $text);
        $spotHint = (bool) preg_match('/\bSPOT\b/', $text);

        if ($spotHint && ! $derivativeHint && $byType->has(TradingSymbol::SPOT)) {
            return $byType[TradingSymbol::SPOT];
        }
        if ($derivativeHint && ! $spotHint && $byType->has(TradingSymbol::FUTURES)) {
            return $byType[TradingSymbol::FUTURES];
        }
        if (! $derivativeHint && ! $spotHint && $byType->has(TradingSymbol::SPOT)) {
            return $byType[TradingSymbol::SPOT]; // plain "BUY BTC/USDT" without leverage is a spot buy
        }

        throw new AmbiguousSignal('Cannot tell whether the signal is for spot or futures.');
    }

    /** @return array{0: ?string, 1: ?string} */
    private function entry(string $text): array
    {
        $n = self::NUM;
        if (preg_match("/(?:ENTRY(?: ZONE| PRICE)?|BUY ZONE|SELL ZONE|ENTER|@)\s*[:=]?\s*{$n}(?:\s*(?:-|TO|~)\s*{$n})?/", $text, $m)) {
            $a = $this->num($m[1]);
            $b = isset($m[2]) ? $this->num($m[2]) : null;
            if ($b === null) {
                return [$a, null];
            }

            return Decimal::of($a)->isLessThanOrEqualTo(Decimal::of($b)) ? [$a, $b] : [$b, $a];
        }
        if (preg_match('/\b(CMP|MARKET|NOW)\b/', $text)) {
            return [null, null];
        }

        throw new AmbiguousSignal('No entry price found (use "Entry: price" or "market").');
    }

    /** @return list<string> */
    private function targets(string $text): array
    {
        $n = self::NUM;
        $targets = [];
        // "TP1: 68000", "TP2 1.08", "TP 3700": an optional target index must be followed by a
        // separator or whitespace so "TP 3700" is never read as target #3 at 700.
        if (preg_match_all("/\b(?:TP|TARGET|TAKE[ -]?PROFIT)\s*(?:\d{1,2}\s*[:=)\-]\s*|\d(?=\s))?\s*[:=]?\s*{$n}/", $text, $m)) {
            $targets = array_map(fn ($v) => $this->num($v), $m[1]);
        }
        // List form ("Targets 3600 3700 3800") only when no labelled targets were found.
        if ($targets === [] && preg_match("/\bTARGETS?\s*[:=]?\s*((?:{$n}[\s,\/-]*)+)/", $text, $m)) {
            preg_match_all('/\d+(?:[.,]\d+)?/', $m[1], $all);
            $targets = array_map(fn ($v) => $this->num($v), $all[0]);
        }

        return array_values(array_unique($targets));
    }

    private function assertConsistent(string $side, ?string $entry, ?string $entryMax, string $stop, array $targets): void
    {
        $stop = Decimal::of($stop);
        $low = $entry !== null ? Decimal::of($entry) : null;
        $high = $entryMax !== null ? Decimal::of($entryMax) : $low;

        foreach ($targets as $target) {
            $t = Decimal::of($target);
            $ok = $side === 'buy' ? $t->isGreaterThan($stop) && ($high === null || $t->isGreaterThan($high))
                : $t->isLessThan($stop) && ($low === null || $t->isLessThan($low));
            if (! $ok) {
                throw new AmbiguousSignal("Target $target is on the wrong side for a $side signal.");
            }
        }
        if ($low !== null) {
            $ok = $side === 'buy' ? $stop->isLessThan($low) : $stop->isGreaterThan($high);
            if (! $ok) {
                throw new AmbiguousSignal('Stop-loss is on the wrong side of the entry.');
            }
        }
    }

    private function firstNumberAfter(string $text, string $label): ?string
    {
        return preg_match('/\b'.$label.'\b\s*[:=]?\s*'.self::NUM.'/', $text, $m) ? $this->num($m[1]) : null;
    }

    private function num(string $value): string
    {
        return Decimal::display(str_replace(',', '.', $value), 18);
    }
}
