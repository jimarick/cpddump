<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "Got these — mark them all done" link in digest emails: a signed URL
 * carrying exactly the takeaway ids that email showed, so one click retires
 * them from the morning gem and future digests. Ids that have since been
 * deleted are ignored silently; nothing here is destructive — done is
 * reversible on the Takeaways page.
 */
class EmailTakeawaysDoneController extends Controller
{
    public function __invoke(Request $request, User $user): View
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        $marked = 0;

        $user->activities()
            ->where(fn ($q) => $q->whereNotNull('nuggets')->orWhereNotNull('actions'))
            ->get()
            ->each(function ($activity) use ($ids, &$marked) {
                foreach ($ids as $id) {
                    if ($activity->setTakeawayDone($id, true)) {
                        $marked++;
                    }
                }
            });

        return view('takeaways-done', ['marked' => $marked]);
    }
}
