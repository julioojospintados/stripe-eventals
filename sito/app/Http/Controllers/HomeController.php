<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\MembershipPlan;
use App\Services\Catalog;
use Illuminate\Http\Request;

/** Pagina dell'evento. */
class HomeController
{
    public function __invoke(Request $request, Catalog $catalog)
    {
        $event = Event::current() ?? abort(404);

        return view('home', $catalog->for($event, $request->user()) + [
            'event' => $event,
            'plan' => MembershipPlan::where('active', true)->first(),
        ]);
    }
}
