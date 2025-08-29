<?php
declare(strict_types=1);

/**
 * coverage-uncovered.php
 * usage: php coverage-uncovered.php coverage.xml
 *
 * Refactored: modular functions, clearer names, safer file resolution,
 * preserves ANSI coloring and output format from previous version.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
if (count($argv) < 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} coverage.xml\n");
    exit(1);
}

$xmlFile = $argv[1];
$projectRoot = realpath(__DIR__) ?: __DIR__;
$useColors = !(bool)getenv('NO_COLOR');

$COL = [
    'reset'  => $useColors ? "\033[0m"  : '',
    'bold'   => $useColors ? "\033[1m"  : '',
    'red'    => $useColors ? "\033[31m" : '',
    'green'  => $useColors ? "\033[32m" : '',
    'yellow' => $useColors ? "\033[33m" : '',
    'blue'   => $useColors ? "\033[34m" : '',
];

function color(string $text, string $colorKey, array $palette): string
{
    return ($palette[$colorKey] ?? '') . $text . ($palette['reset'] ?? '');
}

function loadCoverageXml(string $path)
{
    if (!file_exists($path)) {
        throw new RuntimeException("Coverage file not found: {$path}");
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        $errs = array_map(fn($e) => trim((string)$e->message), libxml_get_errors());
        throw new RuntimeException('Failed to parse XML: ' . implode('; ', $errs));
    }
    return $xml;
}

function safePct(int $covered, int $total): float
{
    if ($total <= 0) {
        return 100.0;
    }
    return ($covered / $total) * 100.0;
}

function resolvePathCandidates(string $covPath, string $projectRoot): array
{
    $rel = ltrim($covPath, '/');
    $parts = explode('/', $rel);
    $candidates = [];
    $candidates[] = $projectRoot . '/' . $rel;
    if (count($parts) > 1 && $parts[0] === 'app' && $parts[1] === 'app') {
        $candidates[] = $projectRoot . '/' . implode('/', array_slice($parts, 1));
    }
    if (count($parts) > 0 && $parts[0] === 'app') {
        $candidates[] = $projectRoot . '/' . implode('/', array_slice($parts, 1));
    }
    $candidates[] = $projectRoot . '/app/' . $rel;
    // normalized candidate
    $candidates[] = $projectRoot . '/' . preg_replace('#^/+#', '', $rel);
    return array_unique($candidates);
}

function findExistingFile(string $covPath, string $projectRoot)
{
    foreach (resolvePathCandidates($covPath, $projectRoot) as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }
    // fallback: search by basename (first match)
    $basename = basename($covPath);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getFilename() === $basename) {
            return $f->getPathname();
        }
    }
    return null;
}

function shortDisplayPath(string $covPath): string
{
    $display = ltrim($covPath, '/');
    $display = preg_replace('#^app/app/#', 'app/', $display);
    $display = preg_replace('#^/+#', '', $display);
    return $display;
}

try {
    $xml = loadCoverageXml($xmlFile);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

// overall metrics from project if present
$overallTotal = 0;
$overallCovered = 0;
if (isset($xml->project->metrics)) {
    $m = $xml->project->metrics;
    $overallTotal = (int)($m['elements'] ?? 0);
    $overallCovered = (int)($m['coveredelements'] ?? 0);
}

// collect files and uncovered lines
$fileNodes = $xml->xpath('//file') ?: [];
$files = [];
$accTotal = 0;
$accCovered = 0;

foreach ($fileNodes as $fileNode) {
    $covPath = (string)$fileNode['name'];
    $uncoveredLines = [];
    $totalCount = 0;
    $coveredCount = 0;

    // prefer file metrics if available
    if (isset($fileNode->metrics)) {
        $fm = $fileNode->metrics;
        $statements = (int)($fm['statements'] ?? 0);
        $coveredStatements = (int)($fm['coveredstatements'] ?? 0);
        $elements = (int)($fm['elements'] ?? 0);
        $coveredElements = (int)($fm['coveredelements'] ?? 0);

        if ($statements > 0 || $elements > 0) {
            $totalCount = $statements > 0 ? $statements : $elements;
            $coveredCount = $coveredStatements > 0 ? $coveredStatements : $coveredElements;
        }
    }

    foreach ($fileNode->line as $line) {
        $type = (string)$line['type'];
        if ($type !== 'stmt' && $type !== 'method') {
            continue;
        }
        $totalCount++;
        $cnt = (int)$line['count'];
        if ($cnt > 0) {
            $coveredCount++;
        } else {
            $uncoveredLines[] = (int)$line['num'];
        }
    }

    if (!empty($uncoveredLines)) {
        $fullPath = findExistingFile($covPath, $projectRoot);

        // Prefer showing a project-relative path when possible, fall back to
        // a normalized coverage path otherwise.
        if ($fullPath !== null) {
            $display = ltrim(str_replace($projectRoot, '', $fullPath), "/\\");
        } else {
            $display = shortDisplayPath($covPath);
        }

        $files[] = [
            'covPath' => $covPath,
            'display' => $display,
            'full' => $fullPath,
            'uncovered' => array_values(array_unique($uncoveredLines)),
            'covered' => $coveredCount,
            'total' => $totalCount,
        ];
    }

    // accumulate if project metrics missing
    if ($overallTotal === 0) {
        if ($totalCount > 0) {
            $accTotal += $totalCount;
            $accCovered += $coveredCount;
        }
    }
}

if ($overallTotal === 0) {
    $overallTotal = $accTotal;
    $overallCovered = $accCovered;
}

// print summary
echo PHP_EOL; // one empty line before summary

$overallPct = $overallTotal > 0 ? safePct($overallCovered, $overallTotal) : null;
if ($overallPct !== null) {
    $pctColor = $overallPct >= 90 ? 'green' : ($overallPct >= 75 ? 'yellow' : 'red');
    $label = color('Test coverage', 'blue', $COL);
    $colon = color(':', 'yellow', $COL);
    printf("%s%s %s%5.1f%%%s  (covered %d of %d elements)\n\n",
        $label, $colon, ($COL[$pctColor] ?? ''), $overallPct, $COL['reset'], $overallCovered, $overallTotal
    );
} else {
    echo color('Overall coverage: unknown (no metrics found)', 'yellow', $COL) . PHP_EOL . PHP_EOL;
}

if (empty($files)) {
    echo color('No uncovered statements/methods found in coverage file.', 'green', $COL) . PHP_EOL;
    exit(0);
}

// print per-file info and uncovered lines
foreach ($files as $f) {
    $display = $f['display'];
    $full = $f['full'] ?? '(not found)';
    $uncovered = $f['uncovered'];
    $covered = $f['covered'];
    $total = $f['total'];

    $filePct = $total > 0 ? safePct($covered, $total) : null;
    $pctText = $filePct !== null ? sprintf("%5.1f%%", $filePct) : "  n/a";
    $pctColor = ($filePct === null) ? 'yellow' : ($filePct >= 90 ? 'green' : ($filePct >= 75 ? 'yellow' : 'red'));

    // line with display and percent: "app/Models/Card.php - 87.5%"
    echo color($display, 'blue', $COL) . ' - ' . ($COL[$pctColor] ?? '') . $pctText . $COL['reset'] . PHP_EOL;

    $src = ($full && file_exists($full)) ? @file($full, FILE_IGNORE_NEW_LINES) : false;
    foreach ($uncovered as $ln) {
        $code = ($src !== false && isset($src[$ln - 1])) ? rtrim($src[$ln - 1]) : '';
        $marker = color('>>', 'red', $COL);
        $lnColored = color(str_pad((string)$ln, 4, ' ', STR_PAD_LEFT), 'yellow', $COL);
        $codeColored = color($code, 'red', $COL);
        printf("%s %s | %s\n", $marker, $lnColored, $codeColored);
    }
    echo PHP_EOL;
}

exit(0);