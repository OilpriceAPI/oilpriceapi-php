<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function oilpriceapiPublicTextFiles(string $root): array
{
    $root = realpath($root) ?: $root;
    if (!is_dir($root)) {
        throw new InvalidArgumentException(sprintf('Package root does not exist: %s', $root));
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (!is_string($content) || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
    sort($files);

    return $files;
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function oilpriceapiClaimFailures(string $root, array $files): array
{
    $patterns = [
        'fixed catalog total' => '~\b\d+\+\s+(commodit|endpoint|tool|api)~i',
        'fixed update cadence' => '~\b(every|updated|refresh(ed)?)\s+(in\s+)?\d+\s+minutes\b~i',
        'unreviewed plan name' => '~\bprofessional(\+|\s+plan)|\bstarter plan\b|\bscale tier\b~i',
        'unreviewed plan price' => '~\$\d+(\.\d+)?\s*(/|per\s+)(mo(nth)?|year)\b~i',
        'uptime or SLA' => '~\b\d+(\.\d+)?%\s+uptime|\bSLA\b~i',
        'price comparison' => '~\bbloomberg\b|\b\d+(\.\d+)?%\s+less\s+cost\b~i',
        'fixed allowance' => '~\b\d[\d,]*\s+(free\s+)?(api\s+requests?|station\s+queries?)\s*(/|per\s+)month\b~i',
        'quota promise' => '~\bdoes\s+not\s+consume.{0,40}\bquota\b|\bunlimited\s+(history|webhooks?|requests?|commodit)~i',
        'universal catalog' => '~\ball\s+(latest\s+)?prices\b|\ball\s+commodit~i',
        'real-time claim' => '~\breal[- ]time\b~i',
        'free-tier claim' => '~\bfree\s+tier\b|\bfree\s+api\s+key\b~i',
        'fixed demo rate' => '~\b\d+\s+(requests?|reqs?\.?)\s*((per|an?)\s+|/\s*)(minutes?|mins?|hours?|hrs?|days?)\b~i',
    ];

    $failures = [];
    foreach ($files as $file) {
        $content = file_get_contents($root . '/' . $file);
        if (!is_string($content)) {
            $failures[] = sprintf('%s: unable to read packaged text', $file);
            continue;
        }
        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[0] as $match) {
                if ($label === 'fixed demo rate' && strtolower($match) === '50 requests/day') {
                    continue;
                }
                $failures[] = sprintf('%s: %s matched %s', $file, $label, $match);
            }
        }
    }

    return $failures;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = $argv[1] ?? '';
    $files = oilpriceapiPublicTextFiles($root);
    $failures = oilpriceapiClaimFailures($root, $files);
    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, sprintf("validated %d installed Composer package text surfaces\n", count($files)));
}
