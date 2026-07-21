<x-mail::message>
# Your {{ $month_label }} in learning

**{{ $nugget_count }}** {{ Str::plural('nugget', $nugget_count) }}{{ $action_count > 0 ? " and {$action_count} ".Str::plural('thing', $action_count).' to chase' : '' }} from last month. A slow scroll through these is revision — that's the point of this email.

@if ($nugget_count > 0)
## What you learned

@foreach ($groups as $group)
@if (count($group['nuggets']) > 0)
**{{ $group['title'] }}**

@foreach ($group['nuggets'] as $nugget)
- {{ $nugget['text'] }}
@endforeach

@endif
@endforeach
@endif
@if ($action_count > 0)
## Things to investigate or do

@foreach ($groups as $group)
@foreach ($group['actions'] as $action)
- {{ $action['text'] }} <small>({{ $group['title'] }})</small>
@endforeach
@endforeach
@endif

<x-mail::button :url="$takeaways_url">
Browse your Takeaways
</x-mail::button>

[Got these — mark them all done]({{ $mark_done_url }}) and they won't be resurfaced again.

{{ config('app.name') }}

<x-slot:subcopy>
[Unsubscribe from the monthly digest]({{ $unsubscribe_url }}) — your takeaways stay on your Takeaways page either way.
</x-slot:subcopy>
</x-mail::message>
