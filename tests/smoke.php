<?php
declare(strict_types=1);

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-forge-ai-test-' . bin2hex(random_bytes(4));
putenv('PFA_DATA_DIR=' . $temporary);
define('PFA_LIBRARY_ONLY', true);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'php_forge_ai.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

try {
    if (!is_dir($temporary) && !mkdir($temporary, 0770, true) && !is_dir($temporary)) {
        throw new RuntimeException('Could not create temporary test directory.');
    }
    $snippet = <<<'PHP'
<?php
declare(strict_types=1);
function groupRecords(array $records): array {
    $groups = [];
    foreach ($records as $record) {
        $groups[(string) ($record['type'] ?? 'unknown')][] = $record;
    }
    return $groups;
}
PHP;
    file_put_contents(PFA_CORPUS_FILE, str_repeat($snippet . "\n", 20));
    $app = new PhpForgeAI();
    $initial = $app->initialize(8, 16, 0.03);
    check($initial['parameters'] > 0, 'model initializes from a local corpus');

    $training = $app->trainBurst(0.25, 1, 1, 0);
    check($training['steps'] === 1, 'one bounded training sequence checkpoints');
    check(is_file(PFA_MODEL_FILE), 'model checkpoint exists');

    $sample = $app->generate('<?php', 20, 0.8, 5);
    check(strlen($sample['continuation']) === 20, 'neural sampler returns requested length');

    $forged = $app->forge(
        'Group records by a selected field while preserving original order.',
        'function',
        'groupRecordsSafely',
        0.8,
        5
    );
    check($forged['parse_ok'] === true, 'forged candidate passes the PHP parser');
    check($forged['safety_ok'] === true, 'forged candidate contains no blocked execution calls');
    check(str_contains($forged['code'], 'function groupRecordsSafely'), 'requested safe identifier is preserved');

    $app->reset();
    check(!is_file(PFA_MODEL_FILE) && !is_file(PFA_CORPUS_FILE), 'reset removes runtime corpus and checkpoint');
} finally {
    if (is_dir($temporary)) {
        foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                rmdir($file);
            }
        }
        rmdir($temporary);
    }
}

fwrite(STDOUT, "PHP Forge AI smoke test: PASS\n");
