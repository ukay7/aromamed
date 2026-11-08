@php($branchTheme = app(\App\Services\BranchTheme::class)->current())
<style id="branch-theme">:root{--sage:{{ $branchTheme['primary'] }};--branch-sidebar:{{ $branchTheme['sidebar'] }};--paper:{{ $branchTheme['background'] }}}</style>
<link rel="stylesheet" href="{{ asset('css/color-schemes.css') }}">
