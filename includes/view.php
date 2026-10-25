<?php
declare(strict_types=1);

// A small subset of Laravel Blade for the HTML views. View names are dotted paths under
// includes/ ('layouts.app' is includes/layouts/app.html). Supported directives:
//   @extends('layouts.app')          first line of a page: render it inside that layout
//   @section('title', 'Text')        short section; the value is HTML, written as is
//   @section('content') ... @endsection
//   @yield('title'), @yield('og_type', 'website')   in a layout: a section, or the default
//   @include('partials.header')
//   @hasSection('og_url')...@else...@endif   (not nested)
//   {!! $name !!}                    data passed by PHP: view('templates.home', ['slides' => $html])
// Unlike Blade, values are not escaped, so write &amp; and &quot; in attribute values, and
// escape() text before passing it as data. Data for a partial on every page (the footer) comes
// from a view composer: viewComposer('partials.footer', fn () => ['sponsors' => ...]).

const VIEW_STRING = "'((?:[^'\\\\]|\\\\.)*)'";

function viewSource(string $name): string {
    $file = __DIR__ . '/' . str_replace('.', '/', $name) . '.html';
    if (!preg_match('~^[\w-]+(?:\.[\w-]+)*$~', $name) || !is_file($file)) throw new RuntimeException("View $name not found");
    return file_get_contents($file);
}

/** Registers the data function of a view, or returns the one registered for it. */
function viewComposer(string $name, ?callable $composer = null): ?callable {
    static $composers = [];
    if ($composer) $composers[$name] = $composer;
    return $composers[$name] ?? null;
}

/**
 * Renders a view's directives. $sections are those of the child views it is the layout of;
 * the data of included views' composers is added to $data.
 */
function renderView(string $html, array $sections = [], array &$data = []): string {
    $unquote = fn (string $value) => stripcslashes($value);
    if (preg_match("~^\s*@extends\('([\w.-]+)'\)~", $html, $extends)) {
        // A section defined by a child view wins over the same section in its layout.
        preg_match_all("~@section\('(\w+)'\)\R?(.*?)\R?[ \t]*@endsection~s", $html, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $name, $value]) $sections[$name] ??= $value;
        preg_match_all("~@section\('(\w+)',\s*" . VIEW_STRING . "\)~", $html, $inline, PREG_SET_ORDER);
        foreach ($inline as [, $name, $value]) $sections[$name] ??= $unquote($value);
        return renderView(viewSource($extends[1]), $sections, $data);
    }
    $html = preg_replace_callback("~@hasSection\('(\w+)'\)(.*?)(?:@else(.*?))?@endif~s",
        fn ($m) => isset($sections[$m[1]]) ? $m[2] : ($m[3] ?? ''), $html);
    $html = preg_replace_callback("~@yield\('(\w+)'(?:,\s*" . VIEW_STRING . ")?\)~",
        fn ($m) => $sections[$m[1]] ?? (isset($m[2]) ? $unquote($m[2]) : ''), $html);
    return preg_replace_callback("~@include\('([\w.-]+)'\)~", function ($m) use ($sections, &$data) {
        if ($composer = viewComposer($m[1])) $data += $composer();
        return renderView(viewSource($m[1]), $sections, $data);
    }, $html);
}

/** Renders a view's HTML with its data. Values are inserted once, so data is never parsed as a directive. */
function renderTemplate(string $html, array $data = []): string {
    $html = renderView($html, [], $data);
    return preg_replace_callback('~\{!!\s*\$(\w+)\s*!!\}~', function ($m) use ($data) {
        if (!array_key_exists($m[1], $data)) throw new RuntimeException("View data \$$m[1] is missing");
        return (string) $data[$m[1]];
    }, $html);
}

/** Renders the view with this name, e.g. view('templates.team', ['title' => ...]). */
function view(string $name, array $data = []): string {
    return renderTemplate(viewSource($name), $data);
}
