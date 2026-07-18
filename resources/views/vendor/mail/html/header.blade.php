@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{{-- The rubber-stamp logo, email-safe: double ring, brand orange, no rotation. --}}
<span style="display: inline-block; border: 3px solid #f4590c; border-radius: 10px; padding: 4px; opacity: 0.93;">
<span style="display: inline-block; border: 1px solid #f4590c; border-radius: 6px; padding: 6px 14px; color: #f4590c; font-weight: 800; font-size: 16px; letter-spacing: 1.5px; text-transform: uppercase;">{{ trim($slot) }}</span>
</span>
</a>
</td>
</tr>
