<?php

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use App\Services\MembershipService;
use App\Services\StripeGateway;
use Illuminate\Http\Request;

/** Membership: avvio del Checkout in abbonamento, ritorno da Stripe (attivazione subito), Customer Portal. */
class MembershipController
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            $request->session()->put('url.intended', route('membership.show'));
        }

        return view('membership', [
            'plan' => MembershipPlan::where('active', true)->first(),
            'membership' => $user?->memberships()->latest('current_period_end')->first(),
            'isMember' => (bool) $user?->isMemberUntil(now()),
        ]);
    }

    public function start(Request $request, MembershipService $memberships)
    {
        return redirect()->away($memberships->startCheckout($request->user()));
    }

    public function success(Request $request, StripeGateway $stripe, MembershipService $memberships)
    {
        $session = $stripe->retrieveCheckoutSession((string) $request->query('session_id'));
        abort_unless(($session['client_reference_id'] ?? null) === (string) $request->user()->id, 404);

        $memberships->activateFromCheckout($session);

        return redirect()->route('account.index');
    }

    public function portal(Request $request, MembershipService $memberships)
    {
        $url = $memberships->portalUrl($request->user());
        abort_if(! $url, 404);

        return redirect()->away($url);
    }
}
