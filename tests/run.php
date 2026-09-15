<?php

declare(strict_types=1);

/**
 * The test runner.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php Money      run only the tests whose class matches "Money"
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$totalPassed = 0;
$totalFailed = 0;
$failures = [];
$started = microtime(true);

echo "SmallERP test suite\n";
echo str_repeat('=', 64), "\n";

foreach ($files as $file) {
    $class = 'Tests\\' . basename($file, '.php');
    if ($filter !== '' && !str_contains(strtolower($class), strtolower($filter))) {
        continue;
    }

    require_once $file;
    /** @var Tests\TestCase $test */
    $test = new $class();
    $test->run();

    $failed = count($test->failures);
    $totalPassed += $test->passed;
    $totalFailed += $failed;

    printf(
        "%-22s %s %d passed%s\n",
        basename($file, '.php'),
        $failed === 0 ? '  ok ' : ' FAIL',
        $test->passed,
        $failed > 0 ? ", {$failed} failed" : ''
    );

    foreach ($test->failures as $failure) {
        $failures[] = basename($file, '.php') . ': ' . $failure;
    }
}

echo str_repeat('=', 64), "\n";

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $i => $failure) {
        printf("%3d. %s\n", $i + 1, $failure);
    }
    echo "\n";
}

printf(
    "%d assertions passed, %d failed, in %.2fs\n",
    $totalPassed,
    $totalFailed,
    microtime(true) - $started
);

// Clean up the throwaway database the tests build.
$testDb = sys_get_temp_dir() . '/smallerp-test-' . getmypid() . '.sqlite';
foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

exit($totalFailed > 0 ? 1 : 0);
