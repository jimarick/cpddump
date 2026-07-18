<x-mail::message>
# Your CPD week

@if ($captured_this_week > 0)
**{{ $captured_this_week }}** new item{{ $captured_this_week === 1 ? '' : 's' }} captured this week, worth **{{ $points_this_week }}** CPD point{{ $points_this_week == 1 ? '' : 's' }} once approved.
@else
Nothing landed in your inbox this week. Forward an email, snap a certificate, or just type a few words about something you did — the AI does the rest.
@endif

@if ($awaiting > 0)
**{{ $awaiting }}** item{{ $awaiting === 1 ? '' : 's' }} waiting for your approval — two minutes of approve-or-bin.

<x-mail::button :url="$inbox_url">
Review your inbox
</x-mail::button>
@endif

@if (($regulars_waiting ?? 0) > 0)
**{{ $regulars_waiting }}** of your regular activities {{ $regulars_waiting === 1 ? 'has' : 'have' }} a draft waiting for a quick reflection.
@endif

## Where you stand this appraisal year

- **{{ $total_activities }}** activities · **{{ $total_points }}** CPD points
@if (count($thin_areas) > 0)
- Looking thin: {{ implode(' · ', $thin_areas) }}
@else
- Every domain has evidence — genuinely rare. Well done.
@endif
@if (count($behind_expectations ?? []) > 0)
- Behind schedule: {{ implode(' · ', $behind_expectations) }}
@endif

@if (filled($dump_address))
Your dump address, for anything CPD-shaped that lands in your email:
**{{ $dump_address }}**
@endif

Small pile now, easy appraisal later.<br>
{{ config('app.name') }}
</x-mail::message>
