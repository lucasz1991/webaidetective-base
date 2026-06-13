<tr>
<td>
<table class="footer" align="center" width="620" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<a href="{{ config('app.url') }}" class="footer-brand">{{ config('app.name') }}</a>
<div class="footer-copy">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</div>
</td>
</tr>
</table>
</td>
</tr>
