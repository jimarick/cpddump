<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Signed, no-login unsubscribe — reachable from the List-Unsubscribe
 * header (RFC 8058 one-click POST) and the email footer link.
 */
class EmailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): View
    {
        $type = $request->query('type') === 'reminders' ? 'reminders' : 'weekly';

        if ($type === 'reminders') {
            $user->recurrences()->update(['reminder' => 'none']);
        } else {
            $user->update(['weekly_email_enabled' => false]);
        }

        return view('unsubscribed', ['type' => $type]);
    }
}
