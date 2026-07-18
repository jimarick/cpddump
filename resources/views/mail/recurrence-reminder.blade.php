<x-mail::message>
@if ($scheduled)
# {{ $title }} — the draft is waiting

Today's **{{ $title }}** draft is sitting in your inbox. Two minutes now, while it's fresh, beats reconstructing it at appraisal time — open it, dictate a line or two of reflection, approve.
@else
# Did a {{ $title }} happen recently?

Nothing tagged **{{ $title }}** has landed in a while, so we've left a prompt in your inbox. If one happened, add the date and approve it. If not, bin it — no harm done.
@endif

<x-mail::button :url="$inboxUrl">
Open your inbox
</x-mail::button>

{{ config('app.name') }}

<x-slot:subcopy>
[Stop these reminders]({{ $unsubscribe_url }}) — drafts will still appear quietly in your inbox.
</x-slot:subcopy>
</x-mail::message>
