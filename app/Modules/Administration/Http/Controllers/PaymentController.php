<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('subscriptions.view');

        return view('admin.payments.index', [
            'payments' => Payment::with('user', 'invoice')
                ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
                ->latest()->paginate(25)->withQueryString(),
        ]);
    }

    public function confirm(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('payments.manage');
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);
        $payments->complete($payment, $data['reference'], $request->user());

        return back()->with('status', 'Payment confirmed and subscription activated.');
    }

    public function fail(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('payments.manage');
        $payments->fail($payment, $request->input('reason', 'Rejected by administrator'), $request->user());

        return back()->with('status', 'Payment marked as failed.');
    }
}
