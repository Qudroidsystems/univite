<?php

/*
 * Code conventions: services, reversals and controllers get their collaborators by injection, never by reaching
 * into the container. Registries that pick a class from configuration (payment gateways, reversal handlers) are the
 * only places allowed to resolve classes themselves.
 */

it('injects services instead of resolving them from the container', function () {
    $allowed = ['app/Services/Payments/Gateways.php', 'app/Support/Reversals.php'];
    $offenders = [];
    foreach (['app/Services', 'app/Reversals', 'app/Http/Controllers', 'app/Support', 'app/Listeners'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $rel = str_replace(base_path() . '/', '', $file->getPathname());
            if ($file->getExtension() !== 'php' || in_array($rel, $allowed, true)) {
                continue;
            }
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file->getPathname()));
            if (preg_match('/\bapp\(\s*[\\\\A-Z$]|(?<!function )(?<!->)\bresolve\(\s*[\\\\A-Z$]|App::make\(|new \\\\?(App\\\\Services\\\\)?\w+(Service|Mailer|Audit|Approval|Generator|Matcher)\(/', $code)) {
                $offenders[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps data objects immutable apart from the ones that collect results as they go', function () {
    $mutable = ['app/Data/Legacy/ParsedFile.php', 'app/Data/Fees/BulkInvoicePreview.php'];
    foreach (glob(base_path('app/Data/*/*.php')) as $path) {
        $rel = str_replace(base_path() . '/', '', $path);
        if (! in_array($rel, $mutable, true)) {
            expect(file_get_contents($path))->toContain('final readonly class');
        }
    }
});
