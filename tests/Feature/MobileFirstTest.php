<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * Structural guards for the mobile-first rules. These are the mistakes that
 * are easy to reintroduce and invisible until someone opens the app on a
 * phone at a counter.
 */
it('sizes every form control for a phone', function () {
    // Below 16px, iOS Safari zooms the whole page when a field is focused.
    foreach (['input', 'select', 'textarea'] as $component) {
        $source = File::get(resource_path("views/components/{$component}.blade.php"));

        expect($source)->toContain('text-base')
            ->and($source)->toContain('sm:text-sm')
            ->and($source)->toContain('py-3');
    }
});

it('keeps every button above the 44px tap target on a phone', function () {
    $source = File::get(resource_path('views/components/button.blade.php'));

    // Each size steps down from `sm` up rather than being small everywhere.
    expect($source)->toContain('sm:py-1.5')
        ->and($source)->toContain('sm:py-2.5')
        ->and($source)->toContain('sm:py-3');
});

it('makes the whole checkbox row tappable', function () {
    $source = File::get(resource_path('views/components/checkbox.blade.php'));

    expect($source)->toContain('min-h-11');
});

it('gives every table cell a data-label so rows stack into readable cards', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = $file->getContents();

        // The receipt is a PDF document, not a responsive page.
        if (str_contains($file->getPathname(), 'receipt.blade.php')) {
            continue;
        }

        if (! str_contains($source, 'x-table') && ! str_contains($source, 'table-cards')) {
            continue;
        }

        foreach (explode("\n", $source) as $i => $line) {
            if (preg_match('/<td\b/', $line) && ! str_contains($line, 'data-label') && ! str_contains($line, 'colspan')) {
                $offenders[] = $file->getRelativePathname().':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'table cells without data-label: '.implode(', ', $offenders));
});

it('never lays out a form action row that cannot stack', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // A bare justify-end row puts two small buttons in the corner on a
        // phone; they should stack full-width with the primary action on top.
        if (str_contains($file->getContents(), 'class="flex justify-end gap-3"')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'non-stacking action rows: '.implode(', ', $offenders));
});

it('starts every multi-column grid at one column', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = $file->getContents();

        foreach (explode("\n", $source) as $i => $line) {
            // grid-cols-3 and up must not be the *base* class: three columns
            // will not fit at 360px. A breakpoint-prefixed `sm:grid-cols-3` is
            // fine, so the lookbehind skips anything after a variant colon.
            // grid-cols-2 is allowed for short paired values.
            if (preg_match('/(?<![:\w-])grid-cols-([3-9]|1[0-2])\b/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'grids starting above 2 columns: '.implode(', ', $offenders));
});

it('lets an icon size override the component default', function () {
    // merge() used to emit class="w-5 h-5 w-4 h-4", and since Tailwind orders
    // .w-4 before .w-5 the default won on source order — every small icon in
    // the app rendered at 20px.
    $html = Blade::render('<x-icon name="wallet" class="w-4 h-4 text-red-600" />');

    expect($html)->toContain('w-4 h-4')
        ->and($html)->not->toContain('w-5')
        ->and($html)->not->toContain('h-5')
        ->and($html)->toContain('text-red-600');

    // With no size given, the default still applies.
    expect(Blade::render('<x-icon name="wallet" />'))->toContain('w-5 h-5');
});

it('does not hard-code a width wider than a 360px screen', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (str_contains($file->getPathname(), 'receipt.blade.php')) {
            continue;
        }

        $source = $file->getContents();

        foreach (explode("\n", $source) as $i => $line) {
            // A min-width beyond the viewport forces the page to scroll
            // sideways, which no amount of responsive grid can fix.
            if (preg_match('/\bmin-w-\[(\d{3,})px\]/', $line, $m) && (int) $m[1] > 360) {
                $offenders[] = $file->getRelativePathname().':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'fixed widths wider than 360px: '.implode(', ', $offenders));
});
