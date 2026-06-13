@props(['url'])

<tr>
<td class="header">
<table class="brand" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-mark">
<a href="{{ $url }}" aria-label="{{ config('app.name') }}">S</a>
</td>
<td class="brand-copy">
<a href="{{ $url }}" class="brand-name">{{ $slot }}</a>
<span class="brand-tagline">Digitale Spuren. Klar verbunden.</span>
</td>
</tr>
</table>
</td>
</tr>
