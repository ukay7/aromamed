<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#183f35">
<title>Welcome back · Aroma Med Spa</title>
<link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">
</head>
<body class="auth-page">
<header class="auth-header"><a class="brand" href="/login" aria-label="Aroma Med Spa"><i>a</i><span>aroma<small>MED SPA</small></span></a><a class="booking-link" href="/book">Book a visit <span>↗</span></a></header>
<main class="auth-card">
<section class="auth-story" aria-labelledby="story-title">
<span class="overline">YOUR CLINIC, CONNECTED</span>
<div class="story-copy"><p class="kicker">A little space for a better day.</p><h1 id="story-title">More care.<br><em>Less admin.</em></h1><p class="description">Bring your people, appointments and everyday details beautifully together.</p></div>
<div class="story-art" aria-hidden="true"><div class="arch"><div class="disc"></div></div><svg viewBox="0 0 260 370" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M131 365C126 267 166 186 141 47M142 96C96 91 78 64 78 28C122 40 144 59 142 96ZM145 137C182 117 197 86 187 54C155 76 143 102 145 137ZM145 176C99 165 79 143 72 106C111 112 141 140 145 176ZM140 215C180 206 208 181 211 143C171 155 144 181 140 215ZM131 258C88 246 65 214 65 180C104 196 125 222 131 258ZM129 296C169 287 193 260 196 227C160 237 133 266 129 296ZM130 337C93 326 70 303 63 269C99 279 124 304 130 337Z"/></svg></div>
<p class="story-footer">● &nbsp; Thoughtful care. One connected workspace.</p>
</section>
<section class="auth-panel" aria-labelledby="login-title"><div class="panel-content">
<div class="access-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg> TEAM WORKSPACE</div>
<h2 id="login-title">Welcome back.</h2><p class="intro">A good day starts with everything in place.<br>Sign in to your clinic workspace.</p>
@if($errors->any())<div class="auth-error" id="login-error" role="alert">{{ $errors->first() }}</div>@endif
<form action="/login" method="post">@csrf
<label for="login-email">Email address</label><div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg><input id="login-email" type="email" name="email" value="{{ old('email') }}" placeholder="you@yourclinic.com" required autocomplete="username" autocapitalize="none" spellcheck="false" @if($errors->any()) aria-describedby="login-error" @endif></div>
<label for="login-password">Password</label><div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg><input type="password" name="password" id="login-password" placeholder="Enter your password" required autocomplete="current-password"><button type="button" id="password-toggle" aria-label="Show password" aria-controls="login-password" aria-pressed="false">Show</button></div>
<p id="caps-lock-note" hidden role="status">Caps Lock is on.</p><button class="sign-in-button" type="submit">Sign in to workspace <span aria-hidden="true">→</span></button>
</form>
<p class="access-help">Need access? Contact your branch administrator.</p>
<div class="client-link"><span>Here for a little self-care?</span><a href="/book">Book an appointment ↗</a></div>
</div></section>
</main>
<footer class="auth-footer"><span>© {{ date('Y') }} Aroma Med Spa</span><span>@if(app()->environment('local'))Local preview · Sample data @else Your clinic. Beautifully connected. @endif</span></footer>
<script src="{{ asset('js/login.js') }}?v={{ filemtime(public_path('js/login.js')) }}" defer></script>
</body></html>
