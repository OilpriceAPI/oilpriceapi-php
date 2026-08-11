<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function oilpriceapiPublicTextFiles(string $root, array $excludedPaths = []): array
{
    $root = realpath($root) ?: $root;
    if (!is_dir($root)) {
        throw new InvalidArgumentException(sprintf('Package root does not exist: %s', $root));
    }

    $excludedPaths = array_map(
        static function (string $path): string {
            $path = trim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
            if ($path === '' || strpbrk($path, '*?[]!') !== false) {
                throw new InvalidArgumentException(sprintf('Unsupported Composer archive exclusion: %s', $path));
            }

            return $path;
        },
        $excludedPaths,
    );
    $isExcluded = static function (string $relative) use ($excludedPaths): bool {
        foreach ($excludedPaths as $excludedPath) {
            if ($relative === $excludedPath || str_starts_with($relative, $excludedPath . '/')) {
                return true;
            }
        }

        return false;
    };

    $files = [];
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $file) use ($root, $isExcluded): bool {
            if ($file->isLink()) {
                return false;
            }
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($root) + 1),
            );

            return !$isExcluded($relative);
        },
    );
    $iterator = new RecursiveIteratorIterator($filter);
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
        'venue-specific futures path' => '~/(?:ice-(?:brent|wti|gasoil)|eua-carbon)(?:/|[\'"`])~i',
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
                $failures[] = sprintf('%s: %s matched %s', $file, $label, $match);
            }
        }
        foreach (oilpriceapiFixedCadenceClaims($content) as $claim) {
            $failures[] = sprintf('%s: fixed request cadence matched %s', $file, $claim);
        }
    }

    return $failures;
}

/**
 * Find mutable count/action/cadence claims in any word order within a bounded
 * paragraph or sentence. Non-request counts such as tests or records per page
 * are intentionally outside the invariant.
 *
 * @return list<string>
 */
function oilpriceapiFixedCadenceClaims(string $content): array
{
    $segments = preg_split('~(?:\R\s*\R)|(?<=[.!?])\s+~u', $content) ?: [];
    $patterns = [
        '~\b\d[\d,]*\b~u',
        '~\b(?:api\s+)?(?:requests?|calls?|queries|hits?|credits?)\b~iu',
        '~(?:\b(?:daily|hourly|minutely|monthly)\b|(?:/|\bper\b|\bevery\b|\beach\b|\bin\b)\s*(?:(?:a|an|one|1|24)\s+)?(?:minutes?|mins?|hours?|hrs?|days?|months?)\b)~iu',
    ];

    $claims = [];
    foreach ($segments as $segment) {
        $positions = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $segment, $matches, PREG_OFFSET_CAPTURE);
            $positions[] = array_map(static fn (array $match): int => $match[1], $matches[0]);
        }
        if (in_array([], $positions, true)) {
            continue;
        }

        foreach ($positions[0] as $countPosition) {
            foreach ($positions[1] as $actionPosition) {
                foreach ($positions[2] as $cadencePosition) {
                    if (max($countPosition, $actionPosition, $cadencePosition)
                        - min($countPosition, $actionPosition, $cadencePosition) <= 200) {
                        $claims[] = trim((string) preg_replace('~\s+~u', ' ', $segment));
                        continue 4;
                    }
                }
            }
        }
    }

    return $claims;
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
