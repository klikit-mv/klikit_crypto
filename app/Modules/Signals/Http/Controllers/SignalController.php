<?php

namespace App\Modules\Signals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SignalExecution;
use App\Models\SignalSubscription;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Services\Signals\SignalService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SignalController extends Controller
{
    public function page(Request $request, SubscriptionService $subscriptions): View
    {
        $user = $request->user();

        return view('signals.index', [
            'enabled' => $subscriptions->hasFeature($user, 'signals.telegram'),
            'channels' => TelegramChannel::whereNull('user_id')->where('is_active', true)->orderBy('channel_name')->get(),
            'mySubscriptions' => SignalSubscription::where('user_id', $user->id)->get()->keyBy('channel_id'),
            'pending' => $this->executions($user->id)->where('status', 'pending_approval')->get(),
            'history' => $this->executions($user->id)->whereNot('status', 'pending_approval')->limit(30)->get(),
        ]);
    }

    public function channels(Request $request): JsonResponse
    {
        $mine = SignalSubscription::where('user_id', $request->user()->id)->get()->keyBy('channel_id');

        return response()->json(['data' => TelegramChannel::whereNull('user_id')->where('is_active', true)->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->channel_name, 'subscription' => $mine->get($c->id)?->only(['execution_mode', 'amount_per_signal', 'max_leverage', 'is_active']),
        ])]);
    }

    public function list(Request $request): JsonResponse
    {
        $channelIds = SignalSubscription::where('user_id', $request->user()->id)->pluck('channel_id');

        return response()->json(TelegramSignal::whereIn('channel_id', $channelIds)->with('targets:id,signal_id,target_number,target_price', 'channel:id,channel_name')
            ->latest('received_at')->paginate(25));
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json($this->executions($request->user()->id)->paginate(25));
    }

    public function subscribe(Request $request, TelegramChannel $channel, SubscriptionService $subscriptions): JsonResponse|RedirectResponse
    {
        abort_unless($channel->user_id === null && $channel->is_active, 404);
        abort_unless($subscriptions->hasFeature($request->user(), 'signals.telegram'), 402, 'Your plan does not include Telegram signals.');
        $data = $request->validate([
            'execution_mode' => ['required', Rule::in(['manual_approval', 'auto'])],
            'amount_per_signal' => ['required', 'numeric', 'min:1'],
            'max_leverage' => ['nullable', 'numeric', 'min:1', 'max:'.config('trading.limits.max_leverage')],
        ]);
        if ($data['execution_mode'] === 'auto' && ! $subscriptions->hasFeature($request->user(), 'bots.automated')) {
            abort(402, 'Automatic signal execution requires a plan with automated trading.');
        }

        $subscription = SignalSubscription::updateOrCreate(
            ['user_id' => $request->user()->id, 'channel_id' => $channel->id],
            $data + ['max_leverage' => $data['max_leverage'] ?? 1, 'is_active' => true],
        );

        return $request->expectsJson() ? response()->json(['data' => $subscription], 201) : back()->with('status', "Subscribed to {$channel->channel_name}.");
    }

    public function unsubscribe(Request $request, TelegramChannel $channel): JsonResponse|RedirectResponse
    {
        SignalSubscription::where(['user_id' => $request->user()->id, 'channel_id' => $channel->id])->delete();

        return $request->expectsJson() ? response()->json(['message' => 'Unsubscribed.']) : back()->with('status', 'Unsubscribed.');
    }

    public function approve(Request $request, SignalExecution $execution, SignalService $signals): JsonResponse|RedirectResponse
    {
        abort_unless($execution->user_id === $request->user()->id, 404);
        $execution = $signals->approve($execution);

        return $request->expectsJson()
            ? response()->json(['data' => $execution->fresh()])
            : back()->with('status', "Signal {$execution->status}".($execution->reason ? ": {$execution->reason}" : '.'));
    }

    public function reject(Request $request, SignalExecution $execution, SignalService $signals): JsonResponse|RedirectResponse
    {
        abort_unless($execution->user_id === $request->user()->id, 404);
        $signals->reject($execution);

        return $request->expectsJson() ? response()->json(['data' => $execution->fresh()]) : back()->with('status', 'Signal rejected.');
    }

    private function executions(int $userId)
    {
        return SignalExecution::where('user_id', $userId)->with('signal.targets', 'signal.channel:id,channel_name', 'order:id,uuid,status,average_fill_price')->latest();
    }
}
