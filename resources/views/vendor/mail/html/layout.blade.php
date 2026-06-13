<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<style>
@media only screen and (max-width: 640px) {
.content,
.inner-body,
.footer {
width: 100% !important;
}

.body {
padding-left: 16px !important;
padding-right: 16px !important;
}

.content-cell {
padding: 32px 24px !important;
}

.header {
padding: 28px 20px 20px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
box-sizing: border-box !important;
width: 100% !important;
}
}
</style>
</head>
<body>
<div class="preheader">Eine sichere Benachrichtigung von {{ config('app.name') }}.</div>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{{ $header ?? '' }}

<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="620" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-accent"></td>
</tr>
<tr>
<td class="content-cell">
{{ Illuminate\Mail\Markdown::parse($slot) }}

{{ $subcopy ?? '' }}
</td>
</tr>
</table>
</td>
</tr>

{{ $footer ?? '' }}
</table>
</td>
</tr>
</table>
</body>
</html>
