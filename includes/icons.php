<?php
/**
 * Hand-authored inline-SVG icon set (24x24, stroke-based, currentColor) — no
 * icon font or CDN dependency, so the app renders identically offline. Every
 * icon inherits its color from whatever CSS `color` is set on its wrapper,
 * which is what lets the same icon look right on both the dark sidebar and
 * the white topbar.
 */

const ICONS = [
    'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    'trending-up' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
    'bar-chart-2' => '<path d="M7 20V10M12 20V4M17 20v-7"/>',
    'file-text' => '<path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5M8 13h8M8 17h8M8 9h3"/>',
    'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/>',
    'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
    'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
    'log-out' => '<path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 19H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/>',
    'log-in' => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11"/><path d="M12 5h6a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-6"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    'filter' => '<path d="M4 5h16l-6 8v5l-4 2v-7L4 5z"/>',
    'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
    'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
    'download' => '<path d="M12 4v11m0 0l-4-4m4 4l4-4"/><path d="M5 19h14"/>',
    'edit-2' => '<path d="M17 3a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
    'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.2A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a13.8 13.8 0 0 1-3.1 4.1M6.5 6.6C4 8.3 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 4.1-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    'alert-triangle' => '<path d="M12 4L2 20h20L12 4z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.5v.01"/>',
    'coffee' => '<path d="M4 9h13v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V9z"/><path d="M17 10h1.5a2.5 2.5 0 0 1 0 5H17"/><path d="M7 5.5c0-1 1-1 1-2M11 5.5c0-1 1-1 1-2"/>',
    'play' => '<path d="M7 4.5v15l13-7.5z"/>',
    'award' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5L7 21l5-2.5L17 21l-1.5-8.5"/>',
];

function icon(string $name, string $class = 'icon'): string
{
    $body = ICONS[$name] ?? ICONS['info'];
    $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
    return '<svg' . $classAttr . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
