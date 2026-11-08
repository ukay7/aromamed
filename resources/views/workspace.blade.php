<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Aroma · Clinic workspace</title>
    <link rel="stylesheet" href="{{ asset('vendor/smartadmin/css/vendors.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/smartadmin/css/app.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/smartadmin/css/fa-solid.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/smartadmin/css/miscellaneous/fullcalendar/fullcalendar.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clinic.css') }}">
    <link rel="stylesheet" href="{{ asset('css/branches.css') }}">
    @include('partials.branch-theme')
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<div class="workspace">
    <aside class="clinic-sidebar" id="sidebar" aria-label="Main navigation">
        <a class="brand" href="/admin" data-route="dashboard"><span class="brand-mark">a</span><span>aroma<small>MED SPA · WORKSPACE</small></span></a>
        <div class="location-card"><i class="fas fa-map-marker-alt"></i><div class="branch-control"><label for="branch-selector">ACTIVE WORKSPACE</label><select id="branch-selector" aria-label="Switch branch"></select><small id="clinic-location">Your clinic</small><small id="clinic-currency">Connected workspace</small></div><span class="online-dot"></span></div>
        <nav id="navigation" aria-label="Clinic modules"><div class="nav-loading">Loading your workspace…</div></nav>
        <div class="sidebar-bottom"><a href="/book" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Customer booking <span>↗</span></a><div class="profile"><span class="avatar">CO</span><span><strong id="profile-name">Clinic owner</strong><small id="profile-role">Workspace</small></span><form method="post" action="/logout">@csrf<button class="icon-button" title="Sign out" aria-label="Sign out"><i class="fas fa-sign-out-alt"></i></button></form></div></div>
    </aside>
    <button class="sidebar-scrim" id="sidebar-scrim" aria-label="Close navigation"></button>
    <div class="clinic-main">
        <header class="topbar"><div class="breadcrumb-line"><button class="icon-button mobile-menu" id="menu-toggle" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><span>Workspace</span><span class="slash">/</span><strong id="breadcrumb">Overview</strong></div><div class="topbar-actions"><label class="module-search"><i class="fas fa-search"></i><input id="module-search" type="search" placeholder="Find a module…" aria-label="Find a module"><kbd>/</kbd></label><span class="workspace-status"><span class="online-dot"></span> Workspace live</span><span class="avatar small">CO</span></div></header>
        <div class="preview-strip"><span><span class="preview-dot"></span> LOCAL PREVIEW <span class="strip-note">Sample clinic data · No live payments or messages</span></span><span id="today-label"></span></div>
        <main id="content" tabindex="-1"><div class="loading-state"><span class="spinner-border"></span><p>Preparing your clinic workspace…</p></div></main>
        <footer class="workspace-footer"><span>Aroma Med Spa <span class="muted">/ Thoughtful care, connected.</span></span><span id="footer-timezone">Secure local workspace</span></footer>
    </div>
</div>
<dialog id="editor" class="editor-dialog" aria-labelledby="editor-title"><header class="dialog-header"><div><span class="eyebrow" id="editor-eyebrow">CLINIC WORKSPACE</span><h2 id="editor-title"></h2></div><button class="icon-button" id="close-editor" aria-label="Close dialog"><i class="fas fa-times"></i></button></header><div id="editor-body"></div></dialog>
<div id="toast-region" aria-live="polite"></div>
<script src="{{ asset('vendor/smartadmin/js/vendors.bundle.js') }}"></script>
<script src="{{ asset('vendor/smartadmin/js/miscellaneous/fullcalendar/fullcalendar.bundle.js') }}"></script>
<script src="{{ asset('js/theme-settings.js') }}" defer></script><script src="{{ asset('js/clinic.js') }}" defer></script>
</body></html>
