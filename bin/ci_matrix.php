<?php

/**
 * Print the GitHub Actions test matrix for the PHP and Laravel versions that composer.json supports.
 *
 * The matrix is built from composer.json so that .github/workflows/ci.yml is the same file in every package. Run
 * `php bin/ci_matrix.php` to see the matrix locally. The output is in the `key=value` format of $GITHUB_OUTPUT.
 *
 * Each Laravel major lists the PHP versions that it supports and the Orchestra Testbench major that boots it. When a
 * new Laravel major is released, add it here in every package.
 */
const LARAVEL_VERSIONS = [
    12 => ['php' => ['8.2', '8.3', '8.4', '8.5'], 'testbench' => '10'],
    13 => ['php' => ['8.3', '8.4', '8.5'], 'testbench' => '11'],
];

$composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

// Ex. `^8.2` is PHP 8.2 and newer
if (! preg_match('/^\^(\d+\.\d+)$/', $composer['require']['php'] ?? '', $php_match)) {
    fwrite(STDERR, 'The composer.json `require.php` constraint must have the form `^8.2`.' . PHP_EOL);
    exit(1);
}

// Ex. `^11.0 || ^12.0 || ^13.0` is Laravel 11, 12, and 13
preg_match_all('/\^(\d+)\./', $composer['require']['illuminate/support'] ?? '', $laravel_matches);
$laravel_majors = array_map('intval', $laravel_matches[1]);
sort($laravel_majors);

if ($laravel_majors === []) {
    fwrite(STDERR, 'The composer.json `require.illuminate/support` constraint must have the form `^12.0 || ^13.0`.' . PHP_EOL);
    exit(1);
}

$include = [];

foreach ($laravel_majors as $laravel) {
    if (! isset(LARAVEL_VERSIONS[$laravel])) {
        fwrite(STDERR, 'Laravel ' . $laravel . ' is not in LARAVEL_VERSIONS in bin/ci_matrix.php.' . PHP_EOL);
        exit(1);
    }

    foreach (LARAVEL_VERSIONS[$laravel]['php'] as $php) {
        if (version_compare($php, $php_match[1], '>=')) {
            $include[] = [
                'php' => $php,
                'laravel' => (string) $laravel,
                'testbench' => LARAVEL_VERSIONS[$laravel]['testbench'],
            ];
        }
    }
}

// The newest PHP and Laravel versions run the checks that only need to run once (Pint, PHPStan, composer audit).
echo 'include=' . json_encode($include, JSON_THROW_ON_ERROR) . PHP_EOL;
echo 'latest=' . json_encode(end($include), JSON_THROW_ON_ERROR) . PHP_EOL;
