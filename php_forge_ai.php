<?php
/**
 * PHP Forge AI — single-file PHP neural language-model laboratory.
 *
 * PHPClasses.org-friendly design:
 *   - One distributable PHP file; no Composer packages and no database.
 *   - Downloads examples from the official English PHP manual repository.
 *   - Extracts PHP program listings and trains a real character-level RNN.
 *   - Combines neural drafting/ranking with a syntax-guided code forge.
 *   - Includes resumable training, checkpoints, PHP parsing, CSRF protection,
 *     atomic writes, file locking, and a complete browser interface.
 *
 * Requirements: PHP 8.1+, ext-curl, ext-json, writable script directory.
 * Run locally: php -S 127.0.0.1:8080 php_forge_ai.php
 * Then open:   http://127.0.0.1:8080
 *
 * The neural model is intentionally CPU-sized. The forge is hybrid: its RNN
 * learns PHP character patterns while its structural layer produces safe,
 * parseable function/class candidates. It is not a transformer or a semantic
 * replacement for review, static analysis, or tests.
 *
 * @license MIT
 * @version 1.1.2
 *
 * Training-source attribution:
 *   https://github.com/php/doc-en
 *   https://www.php.net/manual/en/copyright.php
 */

declare(strict_types=1);

const PFA_VERSION = '1.1.2';
define('PFA_DATA_DIR', getenv('PFA_DATA_DIR') ?: __DIR__ . DIRECTORY_SEPARATOR . 'php_forge_ai_data');
define('PFA_CORPUS_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'corpus.txt');
define('PFA_MODEL_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'model.json');
define('PFA_LOCK_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'write.lock');
define('PFA_OPERATION_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'operation.json');
define('PFA_DOWNLOAD_JOB_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'download-job.json');
define('PFA_DOWNLOAD_PART_FILE', PFA_DATA_DIR . DIRECTORY_SEPARATOR . 'corpus.part');
const PFA_MANUAL_RAW_ROOT = 'https://raw.githubusercontent.com/php/doc-en/master/';
const PFA_MANUAL_LICENSE = 'PHP manual documentation examples; see php/doc-en licensing and credits.';

if (PHP_SAPI !== 'cli') {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

final class PhpForgeAI
{
    private string $dataDir;
    private string $corpusFile;
    private string $modelFile;
    private string $lockFile;

    public function __construct(
        string $dataDir = PFA_DATA_DIR,
        string $corpusFile = PFA_CORPUS_FILE,
        string $modelFile = PFA_MODEL_FILE,
        string $lockFile = PFA_LOCK_FILE
    ) {
        $this->dataDir = $dataDir;
        $this->corpusFile = $corpusFile;
        $this->modelFile = $modelFile;
        $this->lockFile = $lockFile;
        $this->ensureStorage();
    }

    public function status(): array
    {
        $model = $this->loadModel(false);
        $corpusBytes = is_file($this->corpusFile) ? (int) filesize($this->corpusFile) : 0;
        $operation = $this->operationStatus();

        if ($model === null) {
            return [
                'version' => PFA_VERSION,
                'corpus_ready' => $corpusBytes >= 1024,
                'corpus_bytes' => $corpusBytes,
                'model_ready' => false,
                'steps' => 0,
                'loss' => null,
                'chars_per_second' => 0.0,
                'hidden_size' => null,
                'sequence_length' => null,
                'vocab_size' => null,
                'processed_chars' => 0,
                'updated_at' => null,
                'operation' => $operation,
            ];
        }

        return [
            'version' => PFA_VERSION,
            'corpus_ready' => $corpusBytes >= 1024,
            'corpus_bytes' => $corpusBytes,
            'model_ready' => true,
            'steps' => (int) ($model['meta']['steps'] ?? 0),
            'loss' => isset($model['meta']['smooth_loss']) ? (float) $model['meta']['smooth_loss'] : null,
            'chars_per_second' => (float) ($model['meta']['chars_per_second'] ?? 0.0),
            'hidden_size' => (int) $model['config']['hidden_size'],
            'sequence_length' => (int) $model['config']['sequence_length'],
            'vocab_size' => count($model['vocab']),
            'processed_chars' => (int) ($model['meta']['processed_chars'] ?? 0),
            'updated_at' => $model['meta']['updated_at'] ?? null,
            'operation' => $operation,
        ];
    }

    /** Begin a page-by-page download job so the browser can show real progress. */
    public function startPhpManualDownload(int $maxFiles): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required for manual download.');
        }
        $paths = $this->manualPaths();
        $maxFiles = max(5, min(count($paths), $maxFiles));
        $paths = array_slice($paths, 0, $maxFiles);

        return $this->withLock(function () use ($paths): array {
            $job = [
                'paths' => $paths,
                'index' => 0,
                'downloaded' => 0,
                'examples' => 0,
                'failed_files' => [],
                'bytes' => 0,
                'started_at' => gmdate('c'),
            ];
            $this->atomicWrite(PFA_DOWNLOAD_PART_FILE, '');
            $this->atomicWrite(PFA_DOWNLOAD_JOB_FILE, (string) json_encode($job, JSON_UNESCAPED_SLASHES));
            $operation = $this->writeOperation(
                'download',
                'Preparing official PHP manual downloads…',
                0,
                count($paths),
                true
            );
            return ['done' => false, 'operation' => $operation] + $job;
        });
    }

    /** Download and extract exactly one manual page per request. */
    public function stepPhpManualDownload(): array
    {
        return $this->withLock(function (): array {
            $job = $this->readJsonFile(PFA_DOWNLOAD_JOB_FILE);
            if ($job === null || !isset($job['paths'], $job['index'])) {
                throw new RuntimeException('No active download job. Start the download again.');
            }
            $total = count($job['paths']);
            $index = (int) $job['index'];
            if ($index < $total) {
                $path = (string) $job['paths'][$index];
                try {
                    $xml = $this->fetchTrustedManualFile(PFA_MANUAL_RAW_ROOT . $path);
                    $examples = $this->extractPhpExamples($xml);
                    if ($examples !== []) {
                        $separator = "\n\n/* ===== OFFICIAL PHP MANUAL EXAMPLE ===== */\n\n";
                        $chunk = ($job['bytes'] > 0 ? $separator : '') . implode($separator, $examples) . "\n";
                        // The outer operation lock already prevents competing
                        // appends. LOCK_EX is intentionally omitted because
                        // Android shared storage/FUSE may reject it.
                        if (file_put_contents(PFA_DOWNLOAD_PART_FILE, $chunk, FILE_APPEND) === false) {
                            throw new RuntimeException('Could not append extracted examples.');
                        }
                        $job['bytes'] += strlen($chunk);
                        $job['examples'] += count($examples);
                    }
                    $job['downloaded']++;
                } catch (Throwable $error) {
                    $job['failed_files'][] = basename($path);
                }
                $job['index'] = $index + 1;
            }

            $done = (int) $job['index'] >= $total;
            if ($done) {
                if ((int) $job['examples'] < 10 || !is_file(PFA_DOWNLOAD_PART_FILE)) {
                    throw new RuntimeException(
                        'Too few PHP examples were extracted: ' . (int) $job['examples'] . '. Try the download again.'
                    );
                }
                $corpus = file_get_contents(PFA_DOWNLOAD_PART_FILE);
                if (!is_string($corpus) || strlen($corpus) < 1024) {
                    throw new RuntimeException('The extracted PHP corpus is unexpectedly small.');
                }
                $corpus = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $corpus);
                $corpus = (string) preg_replace('/[ ]+\n/', "\n", $corpus);
                $corpus = (string) preg_replace('/\n{5,}/', "\n\n\n", $corpus);
                $this->atomicWrite($this->corpusFile, $corpus);
                $job['bytes'] = strlen($corpus);
                @unlink(PFA_DOWNLOAD_PART_FILE);
                @unlink(PFA_DOWNLOAD_JOB_FILE);
                $operation = $this->writeOperation(
                    'download-complete',
                    'PHP training material is ready.',
                    $total,
                    $total,
                    false,
                    ['examples' => (int) $job['examples'], 'bytes' => (int) $job['bytes']]
                );
            } else {
                $this->atomicWrite(PFA_DOWNLOAD_JOB_FILE, (string) json_encode($job, JSON_UNESCAPED_SLASHES));
                $operation = $this->writeOperation(
                    'download',
                    'Downloaded manual file ' . (int) $job['index'] . ' of ' . $total . '.',
                    (int) $job['index'],
                    $total,
                    true,
                    ['examples' => (int) $job['examples'], 'bytes' => (int) $job['bytes']]
                );
            }

            return [
                'done' => $done,
                'files_downloaded' => (int) $job['downloaded'],
                'examples' => (int) $job['examples'],
                'bytes' => (int) $job['bytes'],
                'failed_files' => $job['failed_files'],
                'operation' => $operation,
                'source' => 'php/doc-en',
                'license_note' => PFA_MANUAL_LICENSE,
            ];
        });
    }

    /** Backward-compatible one-call downloader. The UI uses staged actions. */
    public function downloadPhpManualCorpus(int $maxFiles): array
    {
        $this->startPhpManualDownload($maxFiles);
        do {
            $result = $this->stepPhpManualDownload();
        } while (!$result['done']);
        return $result;
    }

    public function initialize(int $hiddenSize, int $sequenceLength, float $learningRate): array
    {
        $hiddenSize = max(8, min(64, $hiddenSize));
        $sequenceLength = max(16, min(96, $sequenceLength));
        $learningRate = max(0.001, min(0.3, $learningRate));

        return $this->withLock(function () use ($hiddenSize, $sequenceLength, $learningRate): array {
            $this->writeOperation('initialize', 'Initializing clean neural weights…', 0, 1, true);
            $corpus = $this->readCorpus();
            $counts = array_count_values(str_split($corpus));
            unset($counts["\0"]);
            $vocab = array_keys($counts);
            sort($vocab, SORT_STRING);
            if (count($vocab) < 10) {
                throw new RuntimeException('The corpus does not contain enough distinct characters.');
            }
            if (count($vocab) > 128) {
                arsort($counts);
                $vocab = array_slice(array_keys($counts), 0, 128);
                sort($vocab, SORT_STRING);
            }

            $vocabSize = count($vocab);
            mt_srand((int) (microtime(true) * 1000000) & 0x7fffffff);
            $scaleInput = sqrt(2.0 / ($vocabSize + $hiddenSize));
            $scaleHidden = sqrt(1.0 / $hiddenSize);

            $model = [
                'format' => 'php-forge-rnn-v1',
                'config' => [
                    'hidden_size' => $hiddenSize,
                    'sequence_length' => $sequenceLength,
                    'learning_rate' => $learningRate,
                    'gradient_clip' => 5.0,
                ],
                'vocab' => $vocab,
                'weights' => [
                    'Wxh' => $this->randomMatrix($hiddenSize, $vocabSize, $scaleInput),
                    'Whh' => $this->randomMatrix($hiddenSize, $hiddenSize, $scaleHidden),
                    'Why' => $this->randomMatrix($vocabSize, $hiddenSize, $scaleInput),
                    'bh' => array_fill(0, $hiddenSize, 0.0),
                    'by' => array_fill(0, $vocabSize, 0.0),
                ],
                'adagrad' => [
                    'Wxh' => $this->zeroMatrix($hiddenSize, $vocabSize),
                    'Whh' => $this->zeroMatrix($hiddenSize, $hiddenSize),
                    'Why' => $this->zeroMatrix($vocabSize, $hiddenSize),
                    'bh' => array_fill(0, $hiddenSize, 0.0),
                    'by' => array_fill(0, $vocabSize, 0.0),
                ],
                'meta' => [
                    'steps' => 0,
                    'processed_chars' => 0,
                    'smooth_loss' => -log(1.0 / $vocabSize) * $sequenceLength,
                    'chars_per_second' => 0.0,
                    'created_at' => gmdate('c'),
                    'updated_at' => gmdate('c'),
                ],
            ];

            $this->saveModel($model);
            $operation = $this->writeOperation('initialize-complete', 'Neural model initialized.', 1, 1, false);
            return [
                'message' => 'A clean neural model was initialized.',
                'hidden_size' => $hiddenSize,
                'sequence_length' => $sequenceLength,
                'vocab_size' => $vocabSize,
                'parameters' => ($hiddenSize * $vocabSize) + ($hiddenSize * $hiddenSize)
                    + ($vocabSize * $hiddenSize) + $hiddenSize + $vocabSize,
                'operation' => $operation,
            ];
        });
    }

    /** Train for a short request-safe burst, checkpoint once, and return metrics. */
    public function trainBurst(
        float $seconds,
        int $maxSequences,
        int $targetSteps = 0,
        int $startSteps = 0
    ): array
    {
        $seconds = max(0.25, min(8.0, $seconds));
        $maxSequences = max(1, min(200, $maxSequences));

        return $this->withLock(function () use ($seconds, $maxSequences, $targetSteps, $startSteps): array {
            $model = $this->loadModel(true);
            $corpus = $this->readCorpus();
            $charToIndex = array_flip($model['vocab']);
            $seqLen = (int) $model['config']['sequence_length'];
            $targetSteps = $targetSteps > 0
                ? max((int) $model['meta']['steps'], min(10000000, $targetSteps))
                : (int) $model['meta']['steps'] + $maxSequences;
            $startSteps = max(0, min((int) $model['meta']['steps'], $startSteps));
            $goalSteps = max(1, $targetSteps - $startSteps);
            $this->writeOperation(
                'training',
                'Training PHP model and preparing the next checkpoint…',
                (int) $model['meta']['steps'] - $startSteps,
                $goalSteps,
                true
            );
            if (strlen($corpus) <= $seqLen + 2) {
                throw new RuntimeException('Corpus is shorter than the configured sequence length.');
            }

            $started = microtime(true);
            $trained = 0;
            $lastLoss = null;
            do {
                $maxStart = strlen($corpus) - $seqLen - 2;
                $position = mt_rand(0, max(0, $maxStart));
                $inputText = substr($corpus, $position, $seqLen);
                $targetText = substr($corpus, $position + 1, $seqLen);
                $inputs = [];
                $targets = [];
                for ($i = 0; $i < $seqLen; $i++) {
                    $inputs[] = $charToIndex[$inputText[$i]] ?? 0;
                    $targets[] = $charToIndex[$targetText[$i]] ?? 0;
                }

                $lastLoss = $this->trainSequence($model, $inputs, $targets);
                $model['meta']['steps']++;
                $model['meta']['processed_chars'] += $seqLen;
                $oldSmooth = (float) $model['meta']['smooth_loss'];
                $model['meta']['smooth_loss'] = ($oldSmooth * 0.995) + ($lastLoss * 0.005);
                $trained++;
            } while (
                $trained < $maxSequences
                && (microtime(true) - $started) < $seconds
                && (int) $model['meta']['steps'] < $targetSteps
            );

            $elapsed = max(0.0001, microtime(true) - $started);
            $model['meta']['chars_per_second'] = ($trained * $seqLen) / $elapsed;
            $model['meta']['updated_at'] = gmdate('c');
            $this->saveModel($model);
            $done = (int) $model['meta']['steps'] >= $targetSteps;
            $operation = $this->writeOperation(
                $done ? 'training-complete' : 'training',
                $done ? 'Training goal reached; checkpoint saved.' : 'Checkpoint saved; continuing training…',
                (int) $model['meta']['steps'] - $startSteps,
                $goalSteps,
                !$done,
                [
                    'loss' => (float) $model['meta']['smooth_loss'],
                    'absolute_step' => (int) $model['meta']['steps'],
                ]
            );

            return [
                'message' => 'Training burst complete; checkpoint saved.',
                'sequences' => $trained,
                'steps' => (int) $model['meta']['steps'],
                'loss' => (float) $model['meta']['smooth_loss'],
                'batch_loss' => $lastLoss,
                'chars_per_second' => (float) $model['meta']['chars_per_second'],
                'processed_chars' => (int) $model['meta']['processed_chars'],
                'target_steps' => $targetSteps,
                'start_steps' => $startSteps,
                'done' => $done,
                'operation' => $operation,
            ];
        });
    }

    public function generate(string $prompt, int $length, float $temperature, int $topK): array
    {
        $model = $this->loadModel(true);
        $prompt = trim((string) preg_replace('/[^\x0A\x20-\x7E]/', ' ', $prompt));
        if ($prompt === '') {
            $prompt = '<?php';
        }
        $prompt = substr($prompt, 0, 600);
        $length = max(20, min(1200, $length));
        $temperature = max(0.15, min(2.0, $temperature));
        $topK = max(1, min(count($model['vocab']), $topK));

        $charToIndex = array_flip($model['vocab']);
        $hiddenSize = (int) $model['config']['hidden_size'];
        $h = array_fill(0, $hiddenSize, 0.0);
        $spaceIndex = $charToIndex[' '] ?? 0;
        $lastIndex = $spaceIndex;

        // Feed the prompt through the recurrent state before sampling.
        $promptLength = strlen($prompt);
        for ($p = 0; $p < $promptLength; $p++) {
            $lastIndex = $charToIndex[$prompt[$p]] ?? $spaceIndex;
            $h = $this->nextHidden($model, $lastIndex, $h);
        }

        $continuation = '';
        for ($n = 0; $n < $length; $n++) {
            $logits = $this->outputLogits($model, $h);
            $lastIndex = $this->sampleLogits($logits, $temperature, $topK);
            $continuation .= $model['vocab'][$lastIndex];
            $h = $this->nextHidden($model, $lastIndex, $h);
        }

        return [
            'prompt' => $prompt,
            'continuation' => $continuation,
            'full_text' => $prompt . $continuation,
            'steps' => (int) ($model['meta']['steps'] ?? 0),
        ];
    }

    /**
     * Forge a new PHP unit with a hybrid pipeline:
     *  1. the trained RNN produces a raw code continuation;
     *  2. the structural synthesizer turns the requested behavior into a
     *     complete typed function/class;
     *  3. PHP's own tokenizer parses the candidate without executing it;
     *  4. the RNN scores how closely the final code resembles learned PHP.
     */
    public function forge(
        string $specification,
        string $kind,
        string $requestedName,
        float $temperature,
        int $topK
    ): array {
        $model = $this->loadModel(true);
        $this->writeOperation('forge', 'Forging and validating PHP code…', 0, 1, true);
        $specification = trim((string) preg_replace('/[^\x20-\x7E]/', ' ', $specification));
        $specification = (string) preg_replace('/\s{2,}/', ' ', $specification);
        if (strlen($specification) < 8) {
            throw new InvalidArgumentException('Describe the function or class in at least eight characters.');
        }
        $specification = substr($specification, 0, 500);
        $kind = $kind === 'class' ? 'class' : 'function';
        $name = $requestedName !== ''
            ? $this->sanitizeRequestedIdentifier($requestedName, $kind)
            : $this->makeIdentifier($specification, $kind);

        $seed = "<?php\ndeclare(strict_types=1);\n\n/**\n * Purpose: "
            . str_replace('*/', '* /', $specification) . "\n */\n"
            . ($kind === 'class' ? "final class {$name}\n{\n" : "function {$name}(array \$input): array\n{\n");
        $draft = $this->generate($seed, 500, $temperature, $topK);
        $code = $kind === 'class'
            ? $this->buildClassCandidate($name, $specification)
            : $this->buildFunctionCandidate($name, $specification);

        $parseOk = true;
        $parseMessage = 'PHP tokenizer accepted the candidate.';
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $error) {
            $parseOk = false;
            $parseMessage = $error->getMessage();
        }
        $blocked = [];
        foreach (['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'] as $dangerous) {
            if (preg_match('/\b' . preg_quote($dangerous, '/') . '\s*\(/i', $code)) {
                $blocked[] = $dangerous;
            }
        }
        $score = $this->scoreText($model, $code);
        $operation = $this->writeOperation(
            'forge-complete',
            $parseOk ? 'PHP candidate forged and parsed successfully.' : 'Candidate forged with parser warnings.',
            1,
            1,
            false
        );

        return [
            'code' => $code,
            'kind' => $kind,
            'name' => $name,
            'strategy' => $this->detectStrategy($specification),
            'parse_ok' => $parseOk,
            'parse_message' => $parseMessage,
            'safety_ok' => $blocked === [],
            'blocked_calls' => $blocked,
            'neural_nll' => $score,
            'neural_draft' => $draft['full_text'],
            'training_steps' => (int) ($model['meta']['steps'] ?? 0),
            'notice' => 'Generated code must still be reviewed and tested for its intended behavior.',
            'operation' => $operation,
        ];
    }

    public function reset(): array
    {
        return $this->withLock(function (): array {
            foreach ([
                $this->corpusFile,
                $this->modelFile,
                PFA_DOWNLOAD_JOB_FILE,
                PFA_DOWNLOAD_PART_FILE,
                PFA_OPERATION_FILE,
            ] as $knownFile) {
                if (is_file($knownFile) && !unlink($knownFile)) {
                    throw new RuntimeException('Could not remove ' . basename($knownFile) . '.');
                }
            }
            $operation = $this->writeOperation('idle', 'Clean slate ready.', 0, 0, false);
            return [
                'message' => 'Corpus and model checkpoint were removed. The lab is clean.',
                'operation' => $operation,
            ];
        });
    }

    public function recordFailure(string $message): void
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));
        $this->writeOperation('error', substr($message, 0, 300), 0, 0, false);
    }

    public function pauseTraining(): array
    {
        $previous = $this->operationStatus();
        return $this->writeOperation(
            'training-paused',
            'Training paused safely at the latest checkpoint.',
            (int) ($previous['current'] ?? 0),
            (int) ($previous['total'] ?? 0),
            false,
            isset($previous['absolute_step']) ? ['absolute_step' => (int) $previous['absolute_step']] : []
        );
    }

    private function trainSequence(array &$model, array $inputs, array $targets): float
    {
        $H = (int) $model['config']['hidden_size'];
        $V = count($model['vocab']);
        $T = count($inputs);
        $W =& $model['weights'];

        $hs = [array_fill(0, $H, 0.0)];
        $probs = [];
        $loss = 0.0;

        for ($t = 0; $t < $T; $t++) {
            $h = $this->nextHidden($model, $inputs[$t], $hs[$t]);
            $hs[] = $h;
            $logits = $this->outputLogits($model, $h);
            $prob = $this->softmax($logits);
            $probs[] = $prob;
            $loss -= log(max(1.0e-12, $prob[$targets[$t]]));
        }

        $g = [
            'Wxh' => $this->zeroMatrix($H, $V),
            'Whh' => $this->zeroMatrix($H, $H),
            'Why' => $this->zeroMatrix($V, $H),
            'bh' => array_fill(0, $H, 0.0),
            'by' => array_fill(0, $V, 0.0),
        ];
        $dhNext = array_fill(0, $H, 0.0);

        for ($t = $T - 1; $t >= 0; $t--) {
            $dy = $probs[$t];
            $dy[$targets[$t]] -= 1.0;
            for ($v = 0; $v < $V; $v++) {
                $g['by'][$v] += $dy[$v];
                for ($i = 0; $i < $H; $i++) {
                    $g['Why'][$v][$i] += $dy[$v] * $hs[$t + 1][$i];
                }
            }

            $dhRaw = array_fill(0, $H, 0.0);
            for ($i = 0; $i < $H; $i++) {
                $dh = $dhNext[$i];
                for ($v = 0; $v < $V; $v++) {
                    $dh += $W['Why'][$v][$i] * $dy[$v];
                }
                $dhRaw[$i] = (1.0 - ($hs[$t + 1][$i] * $hs[$t + 1][$i])) * $dh;
                $g['bh'][$i] += $dhRaw[$i];
                $g['Wxh'][$i][$inputs[$t]] += $dhRaw[$i];
                for ($j = 0; $j < $H; $j++) {
                    $g['Whh'][$i][$j] += $dhRaw[$i] * $hs[$t][$j];
                }
            }

            $newDhNext = array_fill(0, $H, 0.0);
            for ($j = 0; $j < $H; $j++) {
                $sum = 0.0;
                for ($i = 0; $i < $H; $i++) {
                    $sum += $W['Whh'][$i][$j] * $dhRaw[$i];
                }
                $newDhNext[$j] = $sum;
            }
            $dhNext = $newDhNext;
        }

        $clip = (float) $model['config']['gradient_clip'];
        $rate = (float) $model['config']['learning_rate'];
        foreach (['Wxh', 'Whh', 'Why'] as $name) {
            $rows = count($W[$name]);
            for ($r = 0; $r < $rows; $r++) {
                $cols = count($W[$name][$r]);
                for ($c = 0; $c < $cols; $c++) {
                    $grad = max(-$clip, min($clip, $g[$name][$r][$c]));
                    $model['adagrad'][$name][$r][$c] += $grad * $grad;
                    $W[$name][$r][$c] -= $rate * $grad
                        / sqrt($model['adagrad'][$name][$r][$c] + 1.0e-8);
                }
            }
        }
        foreach (['bh', 'by'] as $name) {
            $count = count($W[$name]);
            for ($i = 0; $i < $count; $i++) {
                $grad = max(-$clip, min($clip, $g[$name][$i]));
                $model['adagrad'][$name][$i] += $grad * $grad;
                $W[$name][$i] -= $rate * $grad / sqrt($model['adagrad'][$name][$i] + 1.0e-8);
            }
        }

        return $loss;
    }

    private function nextHidden(array $model, int $inputIndex, array $previous): array
    {
        $H = (int) $model['config']['hidden_size'];
        $W = $model['weights'];
        $next = array_fill(0, $H, 0.0);
        for ($i = 0; $i < $H; $i++) {
            $sum = $W['bh'][$i] + $W['Wxh'][$i][$inputIndex];
            for ($j = 0; $j < $H; $j++) {
                $sum += $W['Whh'][$i][$j] * $previous[$j];
            }
            $next[$i] = tanh($sum);
        }
        return $next;
    }

    private function outputLogits(array $model, array $hidden): array
    {
        $H = (int) $model['config']['hidden_size'];
        $V = count($model['vocab']);
        $W = $model['weights'];
        $logits = array_fill(0, $V, 0.0);
        for ($v = 0; $v < $V; $v++) {
            $sum = $W['by'][$v];
            for ($i = 0; $i < $H; $i++) {
                $sum += $W['Why'][$v][$i] * $hidden[$i];
            }
            $logits[$v] = $sum;
        }
        return $logits;
    }

    private function softmax(array $logits): array
    {
        $max = max($logits);
        $sum = 0.0;
        $out = [];
        foreach ($logits as $i => $value) {
            $out[$i] = exp(max(-60.0, min(60.0, $value - $max)));
            $sum += $out[$i];
        }
        if ($sum <= 0.0 || !is_finite($sum)) {
            return array_fill(0, count($logits), 1.0 / count($logits));
        }
        foreach ($out as $i => $value) {
            $out[$i] = $value / $sum;
        }
        return $out;
    }

    private function sampleLogits(array $logits, float $temperature, int $topK): int
    {
        foreach ($logits as $i => $value) {
            $logits[$i] = $value / $temperature;
        }
        arsort($logits, SORT_NUMERIC);
        $chosen = array_slice($logits, 0, $topK, true);
        $prob = $this->softmax(array_values($chosen));
        $indices = array_keys($chosen);
        $random = mt_rand() / mt_getrandmax();
        $running = 0.0;
        foreach ($prob as $i => $value) {
            $running += $value;
            if ($random <= $running) {
                return (int) $indices[$i];
            }
        }
        return (int) $indices[count($indices) - 1];
    }

    private function randomMatrix(int $rows, int $cols, float $scale): array
    {
        $matrix = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $row[] = ((mt_rand() / mt_getrandmax()) * 2.0 - 1.0) * $scale;
            }
            $matrix[] = $row;
        }
        return $matrix;
    }

    private function zeroMatrix(int $rows, int $cols): array
    {
        $matrix = [];
        $row = array_fill(0, $cols, 0.0);
        for ($r = 0; $r < $rows; $r++) {
            $matrix[] = $row;
        }
        return $matrix;
    }

    private function manualPaths(): array
    {
        return [
            'language/oop5/basic.xml',
            'language/oop5/decon.xml',
            'language/oop5/visibility.xml',
            'language/oop5/inheritance.xml',
            'language/oop5/abstract.xml',
            'language/oop5/interfaces.xml',
            'language/oop5/traits.xml',
            'language/oop5/anonymous.xml',
            'language/oop5/iterations.xml',
            'language/oop5/overloading.xml',
            'language/oop5/magic.xml',
            'language/oop5/serialization.xml',
            'language/oop5/object-comparison.xml',
            'language/oop5/variance.xml',
            'language/functions/arguments.xml',
            'language/functions/returning-values.xml',
            'language/functions/anonymous.xml',
            'language/functions/arrow.xml',
            'language/control-structures/foreach.xml',
            'reference/array/functions/array-map.xml',
            'reference/array/functions/array-filter.xml',
            'reference/array/functions/array-reduce.xml',
            'reference/array/functions/array-walk.xml',
            'reference/array/functions/array-column.xml',
            'reference/array/functions/usort.xml',
            'reference/pcre/functions/preg-replace-callback.xml',
            'reference/json/functions/json-encode.xml',
            'reference/json/functions/json-decode.xml',
        ];
    }

    private function extractPhpExamples(string $xml): array
    {
        $examples = [];
        if (!preg_match_all(
            '~<programlisting[^>]*role=["\']php["\'][^>]*>(.*?)</programlisting>~si',
            $xml,
            $matches
        )) {
            return [];
        }
        foreach ($matches[1] as $listing) {
            $listing = preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/m', '', $listing);
            $listing = html_entity_decode((string) $listing, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $listing = trim((string) preg_replace('/[^\x0A\x20-\x7E]/', ' ', $listing));
            if (str_contains($listing, '<?php') && strlen($listing) >= 30) {
                $examples[] = $listing;
            }
        }
        return $examples;
    }

    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }

    private function operationStatus(): array
    {
        $operation = $this->readJsonFile(PFA_OPERATION_FILE);
        if ($operation !== null) {
            $updated = isset($operation['updated_at']) ? strtotime((string) $operation['updated_at']) : false;
            if (($operation['active'] ?? false) && is_int($updated) && $updated < time() - 300) {
                $operation['active'] = false;
                $operation['stage'] = 'interrupted';
                $operation['message'] = 'The previous operation was interrupted. It is safe to retry.';
            }
            return $operation;
        }
        return [
            'stage' => 'idle',
            'message' => 'Ready.',
            'current' => 0,
            'total' => 0,
            'percent' => 0.0,
            'active' => false,
            'updated_at' => gmdate('c'),
        ];
    }

    private function writeOperation(
        string $stage,
        string $message,
        int $current,
        int $total,
        bool $active,
        array $extra = []
    ): array {
        $percent = $total > 0 ? max(0.0, min(100.0, ($current / $total) * 100.0)) : 0.0;
        $operation = [
            'stage' => $stage,
            'message' => $message,
            'current' => $current,
            'total' => $total,
            'percent' => $percent,
            'active' => $active,
            'updated_at' => gmdate('c'),
        ] + $extra;
        $this->atomicWrite(
            PFA_OPERATION_FILE,
            (string) json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return $operation;
    }

    private function fetchTrustedManualFile(string $url): string
    {
        if (!str_starts_with($url, PFA_MANUAL_RAW_ROOT)) {
            throw new InvalidArgumentException('Untrusted manual source.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'PhpForgeAI-PHP/' . PFA_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $status !== 200 || strlen($body) < 100) {
            throw new RuntimeException('Manual source failed (HTTP ' . $status . '): ' . $error);
        }
        return $body;
    }

    private function makeIdentifier(string $source, string $kind): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['a', 'an', 'the', 'to', 'for', 'and', 'or', 'that', 'which', 'with', 'from', 'php', 'make', 'create', 'build'];
        $words = array_values(array_filter(
            $words,
            static fn(string $word): bool => !in_array(strtolower($word), $stop, true)
        ));
        $words = array_slice($words, 0, 5);
        if ($words === []) {
            $words = [$kind === 'class' ? 'GeneratedUtility' : 'generatedUtility'];
        }
        $studly = implode('', array_map(static fn(string $word): string => ucfirst(strtolower($word)), $words));
        if ($studly === '' || preg_match('/^[0-9]/', $studly)) {
            $studly = 'Generated' . $studly;
        }
        $reserved = [
            'class', 'function', 'trait', 'interface', 'enum', 'extends', 'implements',
            'public', 'protected', 'private', 'static', 'abstract', 'final', 'readonly',
            'new', 'clone', 'match', 'namespace', 'use', 'return', 'yield', 'throw',
        ];
        if (in_array(strtolower($studly), $reserved, true)) {
            $studly = 'Generated' . $studly;
        }
        if ($kind === 'class') {
            return substr($studly, 0, 64);
        }
        return substr(lcfirst($studly), 0, 64);
    }

    private function sanitizeRequestedIdentifier(string $requested, string $kind): string
    {
        $identifier = (string) preg_replace('/[^A-Za-z0-9_]/', '', trim($requested));
        if ($identifier === '') {
            throw new InvalidArgumentException('The requested name must contain a PHP identifier.');
        }
        if (preg_match('/^[0-9]/', $identifier)) {
            $identifier = 'Generated' . $identifier;
        }
        $reserved = [
            'class', 'function', 'trait', 'interface', 'enum', 'extends', 'implements',
            'public', 'protected', 'private', 'static', 'abstract', 'final', 'readonly',
            'new', 'clone', 'match', 'namespace', 'use', 'return', 'yield', 'throw',
        ];
        if (in_array(strtolower($identifier), $reserved, true)) {
            $identifier = 'Generated' . ucfirst($identifier);
        }
        if ($kind === 'class' && ctype_lower($identifier[0])) {
            $identifier = ucfirst($identifier);
        }
        return substr($identifier, 0, 64);
    }

    private function detectStrategy(string $specification): string
    {
        $text = strtolower($specification);
        $strategies = [
            'group' => ['group', 'bucket', 'categor'],
            'sort' => ['sort', 'order', 'rank'],
            'filter' => ['filter', 'valid', 'reject', 'allow'],
            'slug' => ['slug', 'url safe', 'url-safe'],
            'cache' => ['cache', 'ttl', 'expire'],
            'queue' => ['queue', 'fifo', 'enqueue'],
        ];
        foreach ($strategies as $strategy => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $strategy;
                }
            }
        }
        return 'pipeline';
    }

    private function buildFunctionCandidate(string $name, string $specification): string
    {
        $purpose = str_replace(['*/', "\n", "\r"], ['* /', ' ', ' '], $specification);
        $strategy = $this->detectStrategy($specification);
        $header = "<?php\ndeclare(strict_types=1);\n\n/**\n * {$purpose}\n *\n * Generated by PHP Forge AI's syntax-guided function builder.\n */\n";

        if ($strategy === 'group') {
            return $header . "function {$name}(array \$items, callable|string \$keySelector): array\n"
                . "{\n    \$groups = [];\n    foreach (\$items as \$item) {\n"
                . "        \$key = is_callable(\$keySelector)\n"
                . "            ? \$keySelector(\$item)\n"
                . "            : (is_array(\$item) ? (\$item[\$keySelector] ?? null) : null);\n"
                . "        \$groups[(string) \$key][] = \$item;\n    }\n    return \$groups;\n}\n";
        }
        if ($strategy === 'sort') {
            return $header . "function {$name}(array \$items, callable \$valueSelector): array\n"
                . "{\n    \$decorated = [];\n    foreach (array_values(\$items) as \$position => \$item) {\n"
                . "        \$decorated[] = ['value' => \$valueSelector(\$item), 'position' => \$position, 'item' => \$item];\n"
                . "    }\n    usort(\$decorated, static fn(array \$a, array \$b): int =>\n"
                . "        (\$a['value'] <=> \$b['value']) ?: (\$a['position'] <=> \$b['position'])\n    );\n"
                . "    return array_column(\$decorated, 'item');\n}\n";
        }
        if ($strategy === 'filter') {
            return $header . "function {$name}(array \$items, callable \$accept): array\n"
                . "{\n    \$accepted = [];\n    \$rejected = [];\n    foreach (\$items as \$key => \$item) {\n"
                . "        if (\$accept(\$item, \$key)) {\n            \$accepted[\$key] = \$item;\n"
                . "        } else {\n            \$rejected[\$key] = \$item;\n        }\n    }\n"
                . "    return ['accepted' => \$accepted, 'rejected' => \$rejected];\n}\n";
        }
        if ($strategy === 'slug') {
            return $header . "function {$name}(string \$value, string \$separator = '-'): string\n"
                . "{\n    \$value = function_exists('mb_strtolower')\n"
                . "        ? mb_strtolower(trim(\$value), 'UTF-8')\n"
                . "        : strtolower(trim(\$value));\n"
                . "    \$value = (string) preg_replace('/[^a-z0-9]+/u', \$separator, \$value);\n"
                . "    return trim(\$value, \$separator);\n}\n";
        }
        return $header . "function {$name}(array \$items, callable ...\$stages): array\n"
            . "{\n    \$result = \$items;\n    foreach (\$stages as \$stage) {\n"
            . "        \$next = \$stage(\$result);\n        if (!is_array(\$next)) {\n"
            . "            throw new UnexpectedValueException('Every stage must return an array.');\n"
            . "        }\n        \$result = \$next;\n    }\n    return \$result;\n}\n";
    }

    private function buildClassCandidate(string $name, string $specification): string
    {
        $purpose = str_replace(['*/', "\n", "\r"], ['* /', ' ', ' '], $specification);
        $strategy = $this->detectStrategy($specification);
        $header = "<?php\ndeclare(strict_types=1);\n\n/**\n * {$purpose}\n *\n * Generated by PHP Forge AI's syntax-guided class builder.\n */\n";

        if ($strategy === 'queue') {
            return $header . "final class {$name}\n{\n    private SplQueue \$items;\n\n"
                . "    public function __construct()\n    {\n        \$this->items = new SplQueue();\n    }\n\n"
                . "    public function enqueue(mixed \$value): void\n    {\n        \$this->items->enqueue(\$value);\n    }\n\n"
                . "    public function dequeue(): mixed\n    {\n        if (\$this->items->isEmpty()) {\n"
                . "            throw new UnderflowException('The queue is empty.');\n        }\n"
                . "        return \$this->items->dequeue();\n    }\n\n"
                . "    public function count(): int\n    {\n        return \$this->items->count();\n    }\n}\n";
        }
        if ($strategy === 'cache') {
            return $header . "final class {$name}\n{\n    private array \$values = [];\n"
                . "    private array \$expiresAt = [];\n\n"
                . "    public function set(string \$key, mixed \$value, int \$ttlSeconds = 300): void\n    {\n"
                . "        \$this->values[\$key] = \$value;\n        \$this->expiresAt[\$key] = time() + max(1, \$ttlSeconds);\n    }\n\n"
                . "    public function get(string \$key, mixed \$default = null): mixed\n    {\n"
                . "        if (!isset(\$this->expiresAt[\$key]) || \$this->expiresAt[\$key] < time()) {\n"
                . "            \$this->forget(\$key);\n            return \$default;\n        }\n"
                . "        return \$this->values[\$key];\n    }\n\n"
                . "    public function forget(string \$key): void\n    {\n"
                . "        unset(\$this->values[\$key], \$this->expiresAt[\$key]);\n    }\n}\n";
        }
        if ($strategy === 'filter') {
            return $header . "final class {$name}\n{\n    private array \$rules = [];\n\n"
                . "    public function add(string \$field, callable \$rule, string \$message): self\n    {\n"
                . "        \$clone = clone \$this;\n        \$clone->rules[\$field][] = [\$rule, \$message];\n        return \$clone;\n    }\n\n"
                . "    public function validate(array \$data): array\n    {\n        \$errors = [];\n"
                . "        foreach (\$this->rules as \$field => \$rules) {\n            foreach (\$rules as [\$rule, \$message]) {\n"
                . "                if (!\$rule(\$data[\$field] ?? null, \$data)) {\n                    \$errors[\$field][] = \$message;\n"
                . "                }\n            }\n        }\n        return \$errors;\n    }\n}\n";
        }
        return $header . "final class {$name}\n{\n    /** @var list<callable> */\n    private array \$stages = [];\n\n"
            . "    public function pipe(callable \$stage): self\n    {\n        \$clone = clone \$this;\n"
            . "        \$clone->stages[] = \$stage;\n        return \$clone;\n    }\n\n"
            . "    public function process(mixed \$value): mixed\n    {\n        foreach (\$this->stages as \$stage) {\n"
            . "            \$value = \$stage(\$value);\n        }\n        return \$value;\n    }\n}\n";
    }

    private function scoreText(array $model, string $text): float
    {
        $map = array_flip($model['vocab']);
        $H = (int) $model['config']['hidden_size'];
        $h = array_fill(0, $H, 0.0);
        $fallback = $map[' '] ?? 0;
        $loss = 0.0;
        $count = 0;
        $length = min(strlen($text), 4000);
        for ($i = 0; $i < $length - 1; $i++) {
            $current = $map[$text[$i]] ?? $fallback;
            $target = $map[$text[$i + 1]] ?? $fallback;
            $h = $this->nextHidden($model, $current, $h);
            $prob = $this->softmax($this->outputLogits($model, $h));
            $loss -= log(max(1.0e-12, $prob[$target] ?? 1.0e-12));
            $count++;
        }
        return $count > 0 ? $loss / $count : 0.0;
    }

    private function readCorpus(): string
    {
        if (!is_file($this->corpusFile)) {
            throw new RuntimeException('Download training material first.');
        }
        $corpus = file_get_contents($this->corpusFile);
        if ($corpus === false || strlen($corpus) < 1024) {
            throw new RuntimeException('The training corpus is missing or too small.');
        }
        return $corpus;
    }

    private function loadModel(bool $required): ?array
    {
        if (!is_file($this->modelFile)) {
            if ($required) {
                throw new RuntimeException('Initialize a model first.');
            }
            return null;
        }
        $json = file_get_contents($this->modelFile);
        $model = $json === false ? null : json_decode($json, true);
        if (!is_array($model) || ($model['format'] ?? '') !== 'php-forge-rnn-v1') {
            if ($required) {
                throw new RuntimeException('The model checkpoint is unreadable or incompatible.');
            }
            return null;
        }
        return $model;
    }

    private function saveModel(array $model): void
    {
        $json = json_encode($model, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new RuntimeException('Could not encode the model checkpoint: ' . json_last_error_msg());
        }
        $this->atomicWrite($this->modelFile, $json);
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(5));
        // This is a uniquely named temporary file guarded by withLock(). Do
        // not request LOCK_EX here: Android shared storage and some FUSE/SMB
        // mounts reject advisory file locks even though normal writes work.
        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('Could not write ' . basename($path) . '.');
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            // Some Windows/FUSE layers cannot rename over an existing file.
            // Fall back to replace-then-rename while keeping the temp copy.
            if (!is_file($path) || !@unlink($path) || !@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Could not publish ' . basename($path) . '.');
            }
        }
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0770, true) && !is_dir($this->dataDir)) {
            throw new RuntimeException('Could not create the data directory.');
        }
        if (!is_writable($this->dataDir)) {
            throw new RuntimeException('The data directory is not writable: ' . $this->dataDir);
        }
    }

    private function withLock(callable $operation): mixed
    {
        // Normal Linux/Windows filesystems support advisory flock(). Android
        // shared storage, FUSE, SMB and some inexpensive hosts do not. Try it
        // first, then fall back to an atomic lock directory with stale cleanup.
        $handle = @fopen($this->lockFile, 'c+');
        if ($handle !== false && @flock($handle, LOCK_EX)) {
            try {
                return $operation();
            } finally {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
        if (is_resource($handle)) {
            @fclose($handle);
        }

        $lockDirectory = $this->lockFile . '.dir';
        $deadline = microtime(true) + 8.0;
        do {
            if (@mkdir($lockDirectory, 0770)) {
                try {
                    @touch($lockDirectory);
                    return $operation();
                } finally {
                    @rmdir($lockDirectory);
                }
            }
            $modified = @filemtime($lockDirectory);
            if (is_int($modified) && $modified < time() - 180) {
                @rmdir($lockDirectory);
                continue;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'Another download or training request is still active. Wait a moment, then retry.'
        );
    }
}

function pfa_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pfa_post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

// Tests and embedders may load the engine without rendering the browser UI.
if (defined('PFA_LIBRARY_ONLY') && PFA_LIBRARY_ONLY) {
    return;
}

// Every action, including status, uses POST so the endpoint has one clear API.
if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pfa_json(['ok' => false, 'error' => 'POST required.'], 405);
    }
    $sessionToken = $_SESSION['pfa_csrf'] ?? '';
    $requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        pfa_json(['ok' => false, 'error' => 'Invalid or expired CSRF token. Refresh the page.'], 403);
    }

    $app = null;
    try {
        $app = new PhpForgeAI();
        $action = (string) pfa_post('action', 'status');
        $result = match ($action) {
            'status' => $app->status(),
            'download' => $app->downloadPhpManualCorpus((int) pfa_post('max_files', 28)),
            'download_start' => $app->startPhpManualDownload((int) pfa_post('max_files', 28)),
            'download_step' => $app->stepPhpManualDownload(),
            'initialize' => $app->initialize(
                (int) pfa_post('hidden_size', 32),
                (int) pfa_post('sequence_length', 48),
                (float) pfa_post('learning_rate', 0.05)
            ),
            'train' => $app->trainBurst(
                (float) pfa_post('seconds', 1.5),
                (int) pfa_post('max_sequences', 50),
                (int) pfa_post('target_steps', 0),
                (int) pfa_post('start_steps', 0)
            ),
            'pause' => $app->pauseTraining(),
            'generate' => $app->generate(
                (string) pfa_post('prompt', '<?php'),
                (int) pfa_post('length', 500),
                (float) pfa_post('temperature', 0.8),
                (int) pfa_post('top_k', 12)
            ),
            'forge' => $app->forge(
                (string) pfa_post('specification', ''),
                (string) pfa_post('kind', 'function'),
                trim((string) pfa_post('name', '')),
                (float) pfa_post('temperature', 0.75),
                (int) pfa_post('top_k', 10)
            ),
            'reset' => $app->reset(),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
        pfa_json(['ok' => true, 'result' => $result]);
    } catch (Throwable $error) {
        error_log('[PhpForgeAI] ' . $error->getMessage());
        if ($app instanceof PhpForgeAI) {
            try {
                $app->recordFailure($error->getMessage());
            } catch (Throwable) {
                // Preserve the original error even if status persistence fails.
            }
        }
        pfa_json(['ok' => false, 'error' => $error->getMessage()], 400);
    }
}

if (!isset($_SESSION['pfa_csrf'])) {
    $_SESSION['pfa_csrf'] = bin2hex(random_bytes(24));
}
$csrf = htmlspecialchars((string) $_SESSION['pfa_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $csrf ?>">
<title>PHP Forge AI — PHP Neural Language Model</title>
<style>
:root{--bg:#07100e;--panel:#0d1916;--panel2:#12231e;--line:#23443a;--text:#e7f5ef;--muted:#8fb2a5;--green:#42e39c;--blue:#66b7ff;--amber:#ffca63;--red:#ff6f78;--shadow:0 18px 45px rgba(0,0,0,.28)}
*{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}body{margin:0;background:radial-gradient(circle at 15% 0,#123b2d 0,transparent 32%),linear-gradient(150deg,#050a09,#081410 55%,#06100e);color:var(--text);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;min-height:100vh}
.wrap{width:min(1240px,94vw);margin:auto;padding:34px 0 70px}.top{display:flex;gap:24px;justify-content:space-between;align-items:end;margin-bottom:24px;min-width:0}.brand{min-width:0}.eyebrow{color:var(--green);font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}.brand h1{font-size:clamp(32px,5vw,62px);line-height:.98;letter-spacing:-.055em;margin:8px 0 12px}.brand p{color:var(--muted);max-width:720px;margin:0;font-size:16px;overflow-wrap:anywhere}.badge{white-space:nowrap;border:1px solid var(--line);background:rgba(13,25,22,.78);padding:10px 14px;border-radius:999px;color:var(--muted)}
.workstatus{position:sticky;top:8px;z-index:20;background:rgba(9,22,18,.94);backdrop-filter:blur(12px);border:1px solid #315749;border-radius:16px;padding:13px 15px;box-shadow:var(--shadow);margin-bottom:14px}.workstatus-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.workstatus-title{font-weight:850}.workstatus-percent{color:var(--green);font:800 13px ui-monospace,SFMono-Regular,Consolas,monospace}.progress-track{height:12px;background:#050a09;border:1px solid #264c3f;border-radius:999px;overflow:hidden;margin:9px 0 7px}.progress-fill{height:100%;width:0;background:linear-gradient(90deg,#2cd58b,#66b7ff);border-radius:inherit;transition:width .25s ease}.progress-fill.indeterminate{width:38%;animation:progressRun 1.15s ease-in-out infinite}.workstatus-detail{display:flex;justify-content:space-between;gap:12px;color:var(--muted);font-size:12px}.workstatus-detail span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.workstatus.error{border-color:#78363d}.workstatus.error .workstatus-title,.workstatus.error .workstatus-percent{color:var(--red)}
.metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:18px 0}.metric,.card{background:linear-gradient(150deg,rgba(18,35,30,.96),rgba(10,21,18,.96));border:1px solid var(--line);box-shadow:var(--shadow);min-width:0}.metric{border-radius:14px;padding:14px;overflow:hidden}.metric b{display:block;font-size:23px;letter-spacing:-.03em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.metric span{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.1em}.grid{display:grid;grid-template-columns:minmax(0,1.02fr) minmax(0,.98fr);gap:16px}.card{border-radius:20px;padding:20px;overflow:hidden}.card h2{margin:0 0 4px;font-size:19px}.card .sub{color:var(--muted);font-size:13px;margin-bottom:16px;overflow-wrap:anywhere}.wide{grid-column:1/-1}
.fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;min-width:0}.field{display:flex;flex-direction:column;gap:6px;min-width:0}.field.widefield{grid-column:1/-1}label{color:var(--muted);font-size:12px;font-weight:700;overflow-wrap:anywhere}input,textarea,select{display:block;min-width:0;width:100%;border:1px solid #315749;background:#08110f;color:var(--text);border-radius:10px;padding:11px 12px;outline:none;font-size:16px}input:focus,textarea:focus,select:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(66,227,156,.1)}textarea{min-height:125px;resize:vertical}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:14px;min-width:0}button{min-width:0;border:0;border-radius:10px;padding:11px 15px;font-weight:800;cursor:pointer;background:var(--green);color:#062016;transition:.16s transform,.16s opacity;white-space:normal}button:hover{transform:translateY(-1px)}button:disabled{opacity:.45;cursor:not-allowed;transform:none}.secondary{background:#1c392f;color:var(--text);border:1px solid #315749}.blue{background:var(--blue);color:#061625}.danger{background:transparent;color:var(--red);border:1px solid #6a3136}.heroAction{font-size:15px;padding:13px 18px}
.statusline{display:flex;align-items:center;gap:9px;color:var(--muted);font-size:13px;margin-top:14px;min-width:0}.statusline span:last-child{min-width:0;overflow-wrap:anywhere}.dot{flex:0 0 auto;width:9px;height:9px;background:#587169;border-radius:50%;box-shadow:0 0 0 4px rgba(88,113,105,.12)}.dot.live{background:var(--green);box-shadow:0 0 0 4px rgba(66,227,156,.14)}.dot.busy{background:var(--amber);animation:pulse 1s infinite}.console{background:#050b09;border:1px solid #1d3b31;border-radius:12px;min-height:128px;max-height:240px;overflow:auto;padding:12px;color:#a9c9bd;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}.logline{padding:3px 0;border-bottom:1px solid rgba(35,68,58,.35);overflow-wrap:anywhere}.logline.error{color:var(--red)}.codeout{max-width:100%;white-space:pre;overflow:auto;background:#050a09;color:#b9f7d7;border-radius:14px;padding:20px;min-height:260px;font:13px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;border:1px solid #315749}.quality{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.pill{border:1px solid var(--line);border-radius:999px;padding:5px 9px;color:var(--muted);font-size:12px}.pill.good{color:var(--green);border-color:#287854}.pill.bad{color:var(--red);border-color:#76333a}details{margin-top:14px;color:var(--muted);max-width:100%}details pre{white-space:pre-wrap;max-height:300px;overflow:auto;background:#050a09;padding:14px;border-radius:10px}.truth{border-left:3px solid var(--amber);padding:10px 12px;color:#b7c9c2;background:rgba(255,202,99,.06);font-size:13px;margin-top:14px;overflow-wrap:anywhere}.footer{color:#66877b;text-align:center;margin-top:24px;font-size:12px;overflow-wrap:anywhere}@keyframes pulse{50%{opacity:.42}}@keyframes progressRun{0%{transform:translateX(-115%)}100%{transform:translateX(300%)}}
@media(max-width:850px){.top{display:block}.badge{display:inline-block;margin-top:15px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:minmax(0,1fr)}.wide{grid-column:auto}.fields{grid-template-columns:repeat(2,minmax(0,1fr))}.field.widefield{grid-column:1/-1}}
@media(max-width:680px){.wrap{width:100%;padding:18px 12px 64px}.top{padding:0 4px}.brand h1{font-size:38px}.badge{white-space:normal}.workstatus{top:5px;border-radius:13px}.workstatus-detail{display:block}.workstatus-detail span{display:block}.workstatus-detail span:last-child{margin-top:2px}.fields{grid-template-columns:minmax(0,1fr)}.field.widefield{grid-column:auto}.card{padding:16px;border-radius:16px}.actions{display:grid;grid-template-columns:minmax(0,1fr)}.actions button{width:100%}.metric{padding:12px}.metric b{font-size:20px}.codeout{padding:14px;font-size:12px}.truth{padding-left:10px}}
@media(max-width:380px){.metrics{grid-template-columns:minmax(0,1fr)}.metric:last-child{grid-column:auto}}
</style>
</head>
<body>
<main class="wrap">
  <header class="top">
    <div class="brand">
      <div class="eyebrow">Train from zero • PHP only</div>
      <h1>PHP Forge AI</h1>
      <p>A single-page PHP code laboratory: download official manual examples, train from random weights, then forge new functions and classes through a neural draft plus a syntax-guided compiler layer.</p>
    </div>
    <div class="badge">RNN + BPTT + AdaGrad · v<?= htmlspecialchars(PFA_VERSION) ?></div>
  </header>

  <section class="workstatus" id="workStatus" aria-live="polite" aria-label="Current operation status">
    <div class="workstatus-head">
      <span class="workstatus-title" id="workTitle">Ready</span>
      <span class="workstatus-percent" id="workPercent">0%</span>
    </div>
    <div class="progress-track" role="progressbar" id="progressTrack" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
      <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="workstatus-detail">
      <span id="workMessage">Waiting for an operation.</span>
      <span id="workCount">Idle</span>
    </div>
  </section>

  <section class="metrics" aria-label="Model metrics">
    <div class="metric"><b id="mSteps">0</b><span>training steps</span></div>
    <div class="metric"><b id="mLoss">—</b><span>smoothed loss</span></div>
    <div class="metric"><b id="mSpeed">0</b><span>characters / sec</span></div>
    <div class="metric"><b id="mCorpus">0 B</b><span>training material</span></div>
    <div class="metric"><b id="mModel">not ready</b><span>model state</span></div>
  </section>

  <section class="grid">
    <article class="card">
      <h2>1. Material and clean start</h2>
      <div class="sub">The all-in-one action deletes the local checkpoint, extracts PHP examples from the official English manual sources, initializes random weights, then trains.</div>
      <div class="fields">
        <div class="field"><label for="manualFiles">Manual source files</label><input id="manualFiles" type="number" min="5" max="28" value="28"></div>
        <div class="field"><label for="hiddenSize">Hidden neurons</label><input id="hiddenSize" type="number" min="8" max="64" value="32"></div>
        <div class="field"><label for="seqLength">Sequence length</label><input id="seqLength" type="number" min="16" max="96" value="48"></div>
        <div class="field"><label for="learningRate">Learning rate</label><input id="learningRate" type="number" min="0.001" max="0.3" step="0.001" value="0.05"></div>
      </div>
      <div class="actions">
        <button class="heroAction" id="cleanStart">Clean start + train</button>
        <button class="secondary" id="downloadOnly">Download only</button>
        <button class="secondary" id="initOnly">Initialize only</button>
        <button class="danger" id="reset">Reset files</button>
      </div>
      <div class="truth"><strong>Practical truth:</strong> the neural net learns PHP syntax patterns; the structural forge is what guarantees complete, parseable candidates. Always review behavior and add tests before production use.</div>
    </article>

    <article class="card">
      <h2>2. Training control</h2>
      <div class="sub">Each request trains briefly and atomically saves a checkpoint. Closing the browser stops new work without corrupting the last save.</div>
      <div class="fields">
        <div class="field"><label for="burstSeconds">Seconds per request</label><input id="burstSeconds" type="number" min="0.25" max="8" step="0.25" value="1.5"></div>
        <div class="field"><label for="maxSequences">Max sequences / request</label><input id="maxSequences" type="number" min="1" max="200" value="50"></div>
        <div class="field"><label for="trainingGoal">Additional training steps</label><input id="trainingGoal" type="number" min="1" max="1000000" value="1000"></div>
      </div>
      <div class="actions">
        <button id="startTrain">Start / resume training</button>
        <button class="secondary" id="stopTrain" disabled>Stop after checkpoint</button>
      </div>
      <div class="statusline"><span class="dot" id="trainDot"></span><span id="trainState">Idle — waiting for a model</span></div>
      <div class="console" id="console" aria-live="polite"><div class="logline">PHP Forge AI ready.</div></div>
    </article>

    <article class="card wide">
      <h2>3. Forge novel PHP code</h2>
      <div class="sub">Describe a useful behavior. The trained model drafts and scores PHP while the structural layer produces a complete candidate and validates it with PHP's parser—without executing it.</div>
      <div class="fields">
        <div class="field widefield"><label for="specification">What should it build?</label><textarea id="specification">Create a reusable function that groups records by a selected field while preserving their original order.</textarea></div>
        <div class="field"><label for="kind">Artifact type</label><select id="kind"><option value="function">Function</option><option value="class">Class</option></select></div>
        <div class="field"><label for="requestedName">Optional exact name</label><input id="requestedName" placeholder="Auto-generated from purpose"></div>
        <div class="field"><label for="temperature">Temperature</label><input id="temperature" type="number" min="0.15" max="2" step="0.05" value="0.8"></div>
        <div class="field"><label for="topK">Top-K sampling</label><input id="topK" type="number" min="1" max="128" value="12"></div>
      </div>
      <div class="actions"><button class="blue" id="forge">Forge PHP candidate</button><button class="secondary" id="copyCode">Copy code</button></div>
      <div class="quality" id="quality"><span class="pill">No candidate yet</span></div>
      <pre class="codeout" id="codeOutput">Train the model, then describe the function or class you want.</pre>
      <details><summary>Show raw neural draft</summary><pre id="neuralDraft">The unconstrained model draft will appear here for inspection.</pre></details>
    </article>
  </section>
  <div class="footer">One distributable PHP file. Runtime corpus and checkpoints are stored beside it in <code>php_forge_ai_data/</code>. Training sources: official <code>php/doc-en</code> manual examples.</div>
</main>
<script>
(() => {
  'use strict';
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const $ = id => document.getElementById(id);
  let training = false;
  let requestActive = false;
  let currentModelSteps = 0;
  let trainingTarget = 0;
  let trainingStart = 0;

  const fmtBytes = n => {
    n = Number(n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  };
  const log = (message, error = false) => {
    const row = document.createElement('div');
    row.className = 'logline' + (error ? ' error' : '');
    row.textContent = new Date().toLocaleTimeString() + '  ' + message;
    $('console').appendChild(row);
    while ($('console').children.length > 80) $('console').firstElementChild.remove();
    $('console').scrollTop = $('console').scrollHeight;
  };
  async function api(action, values = {}) {
    const body = new URLSearchParams({action, ...values});
    const response = await fetch('?api=1', {method:'POST', headers:{'X-CSRF-Token':csrf}, body});
    const raw = await response.text();
    let data = null;
    try { data = JSON.parse(raw); } catch (_) {
      // If hosting has display_errors enabled, a PHP warning can precede an
      // otherwise valid JSON body. Recover the final API object when possible.
      const marker = raw.lastIndexOf('{"ok":');
      if (marker >= 0) {
        try { data = JSON.parse(raw.slice(marker)); } catch (_) { data = null; }
      }
    }
    if (!data) {
      const detail = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
      throw new Error(detail ? `Server response error: ${detail}` : `Empty server response (HTTP ${response.status}).`);
    }
    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
    return data.result;
  }
  function showOperation(operation = {}, forceIndeterminate = false) {
    const stage = String(operation.stage || 'idle');
    const current = Number(operation.current || 0);
    const total = Number(operation.total || 0);
    const hasTotal = total > 0;
    const percent = hasTotal ? Math.max(0, Math.min(100, Number(operation.percent ?? (current / total * 100)))) : 0;
    const active = Boolean(operation.active) || forceIndeterminate;
    const titles = {
      idle:'Ready', download:'Downloading PHP examples', 'download-complete':'Download complete',
      initialize:'Initializing model', 'initialize-complete':'Model ready', training:'Training PHP model',
      'training-complete':'Training complete', 'training-paused':'Training paused', forge:'Forging PHP code',
      'forge-complete':'Forge complete', interrupted:'Operation interrupted', error:'Operation failed'
    };
    $('workStatus').classList.toggle('error', stage === 'error');
    $('workTitle').textContent = titles[stage] || 'Working';
    $('workMessage').textContent = operation.message || (active ? 'Working…' : 'Ready.');
    $('workPercent').textContent = hasTotal ? `${percent.toFixed(percent < 10 && percent > 0 ? 1 : 0)}%` : (active ? 'WORKING' : 'READY');
    $('workCount').textContent = hasTotal ? `${current.toLocaleString()} / ${total.toLocaleString()}` : (active ? 'Please wait' : 'Idle');
    $('progressFill').classList.toggle('indeterminate', active && !hasTotal);
    $('progressFill').style.width = active && !hasTotal ? '' : `${hasTotal ? percent : (stage.endsWith('complete') ? 100 : 0)}%`;
    $('progressTrack').setAttribute('aria-valuenow', String(hasTotal ? Math.round(percent) : 0));
    $('progressTrack').setAttribute('aria-valuetext', $('workMessage').textContent);
  }
  function showStatus(s) {
    currentModelSteps = Number(s.steps || 0);
    $('mSteps').textContent = Number(s.steps || 0).toLocaleString();
    $('mLoss').textContent = s.loss == null ? '—' : Number(s.loss).toFixed(3);
    $('mSpeed').textContent = Number(s.chars_per_second || 0).toFixed(0);
    $('mCorpus').textContent = fmtBytes(s.corpus_bytes);
    $('mModel').textContent = s.model_ready ? `${s.hidden_size}h / ${s.vocab_size}v` : 'not ready';
    showOperation(s.operation || {stage:'idle',message:'Ready.',active:false});
    if (!training) $('trainState').textContent = s.model_ready ? 'Checkpoint ready — training is paused' : (s.corpus_ready ? 'Material ready — initialize a model' : 'Idle — download material first');
  }
  async function refresh() {
    try { const status = await api('status'); showStatus(status); return status; }
    catch (e) { log(e.message, true); showOperation({stage:'error',message:e.message}); return null; }
  }
  function setBusy(label) {
    $('trainDot').className = 'dot busy';
    $('trainState').textContent = label;
    showOperation({stage:'working',message:label,active:true}, true);
  }
  function setTrainingButtons(on) {
    $('startTrain').disabled = on;
    $('stopTrain').disabled = !on;
  }
  async function download() {
    setBusy('Downloading official PHP manual examples…');
    let result = await api('download_start', {max_files:$('manualFiles').value});
    showOperation(result.operation);
    let lastLogged = 0;
    let retries = 0;
    while (!result.done) {
      try {
        result = await api('download_step');
        retries = 0;
      } catch (error) {
        retries++;
        if (retries > 3) throw error;
        log(`Download response failed; retrying ${retries}/3: ${error.message}`, true);
        showOperation({stage:'download',message:`Temporary download error; retrying ${retries}/3…`,current:Number(result.operation?.current || 0),total:Number(result.operation?.total || 0),active:true});
        await new Promise(resolve => setTimeout(resolve, 500 * retries));
        continue;
      }
      showOperation(result.operation);
      const completed = Number(result.operation?.current || 0);
      if (completed === 1 || result.done || completed - lastLogged >= 5) {
        log(`Download ${completed}/${result.operation.total} · ${result.examples} PHP examples · ${fmtBytes(result.bytes)}`);
        lastLogged = completed;
      }
      await new Promise(resolve => setTimeout(resolve, 35));
    }
    log(`Training material ready: ${result.examples} examples from ${result.files_downloaded} files · ${fmtBytes(result.bytes)}`);
    if (result.failed_files.length) log(`Skipped unavailable sources: ${result.failed_files.join(', ')}`);
    await refresh();
  }
  async function initialize() {
    setBusy('Creating randomized neural weights…');
    const result = await api('initialize', {hidden_size:$('hiddenSize').value, sequence_length:$('seqLength').value, learning_rate:$('learningRate').value});
    showOperation(result.operation);
    log(`${result.message} ${Number(result.parameters).toLocaleString()} trainable parameters.`);
    await refresh();
  }
  async function trainingLoop() {
    if (requestActive) return;
    setTrainingButtons(true);
    while (training) {
      requestActive = true;
      $('trainDot').className = 'dot live';
      $('trainState').textContent = 'Training and checkpointing…';
      showOperation({stage:'training',message:'Training and preparing the next checkpoint…',current:Math.max(0,currentModelSteps-trainingStart),total:Math.max(1,trainingTarget-trainingStart),active:true});
      try {
        const r = await api('train', {seconds:$('burstSeconds').value, max_sequences:$('maxSequences').value, target_steps:trainingTarget, start_steps:trainingStart});
        currentModelSteps = Number(r.steps);
        showOperation(r.operation);
        const currentCorpus = $('mCorpus').textContent;
        $('mSteps').textContent = Number(r.steps).toLocaleString();
        $('mLoss').textContent = Number(r.loss).toFixed(3);
        $('mSpeed').textContent = Number(r.chars_per_second).toFixed(0);
        $('mCorpus').textContent = currentCorpus;
        log(`Step ${Number(r.steps).toLocaleString()} · loss ${Number(r.loss).toFixed(3)} · ${Number(r.chars_per_second).toFixed(0)} chars/s · checkpoint saved`);
        if (r.done) {
          training = false;
          log(`Training goal reached at step ${Number(r.steps).toLocaleString()}.`);
        }
      } catch (e) {
        log(e.message, true); showOperation({stage:'error',message:e.message}); training = false;
      } finally { requestActive = false; }
      await new Promise(resolve => setTimeout(resolve, 90));
    }
    setTrainingButtons(false);
    $('trainDot').className = 'dot';
    if (currentModelSteps < trainingTarget) {
      try { showOperation(await api('pause')); } catch (e) { log(e.message, true); }
    }
    await refresh();
  }

  function beginTraining() {
    if (training) return;
    const additional = Math.max(1, Number($('trainingGoal').value || 1000));
    trainingStart = currentModelSteps;
    trainingTarget = currentModelSteps + additional;
    training = true;
    trainingLoop();
  }

  $('cleanStart').onclick = async () => {
    if (!confirm('Delete the current corpus and checkpoint, then begin again from random weights?')) return;
    training = false;
    try {
      setBusy('Cleaning the existing lab…');
      await api('reset'); log('Clean slate created.');
      await download(); await initialize();
      beginTraining();
    } catch (e) { log(e.message, true); showOperation({stage:'error',message:e.message}); $('trainDot').className = 'dot'; await refresh(); }
  };
  $('downloadOnly').onclick = async () => { try { await download(); } catch(e) { log(e.message,true); showOperation({stage:'error',message:e.message}); await refresh(); } };
  $('initOnly').onclick = async () => { try { await initialize(); } catch(e) { log(e.message,true); showOperation({stage:'error',message:e.message}); await refresh(); } };
  $('startTrain').onclick = beginTraining;
  $('stopTrain').onclick = async () => {
    training = false;
    $('trainState').textContent = requestActive ? 'Stopping after the active checkpoint…' : 'Stopped safely';
    if (!requestActive) {
      try { showOperation(await api('pause')); } catch(e) { log(e.message,true); }
    }
  };
  $('reset').onclick = async () => {
    if (!confirm('Permanently remove the downloaded corpus and trained checkpoint?')) return;
    training = false;
    try { const r = await api('reset'); showOperation(r.operation); log(r.message); await refresh(); } catch(e) { log(e.message,true); showOperation({stage:'error',message:e.message}); }
  };
  $('forge').onclick = async () => {
    $('forge').disabled = true; $('codeOutput').textContent = 'Forging and parsing candidate…';
    showOperation({stage:'forge',message:'Forging and validating PHP code…',active:true}, true);
    try {
      const r = await api('forge', {specification:$('specification').value, kind:$('kind').value, name:$('requestedName').value, temperature:$('temperature').value, top_k:$('topK').value});
      showOperation(r.operation);
      $('codeOutput').textContent = r.code;
      $('neuralDraft').textContent = r.neural_draft;
      $('quality').innerHTML = '';
      const badges = [
        [r.parse_ok, r.parse_ok ? 'PHP parse passed' : 'PHP parse failed'],
        [r.safety_ok, r.safety_ok ? 'No blocked calls' : 'Blocked call found'],
        [true, `Strategy: ${r.strategy}`],
        [true, `Neural NLL: ${Number(r.neural_nll).toFixed(3)}`],
        [true, `Step ${Number(r.training_steps).toLocaleString()}`]
      ];
      for (const [good, text] of badges) {
        const badge = document.createElement('span');
        badge.className = 'pill ' + (good ? 'good' : 'bad');
        badge.textContent = text; $('quality').appendChild(badge);
      }
      log(`Forged ${r.kind} ${r.name}; ${r.parse_message}`);
    } catch(e) { $('codeOutput').textContent = e.message; log(e.message,true); showOperation({stage:'error',message:e.message}); }
    finally { $('forge').disabled = false; }
  };
  $('copyCode').onclick = async () => {
    try { await navigator.clipboard.writeText($('codeOutput').textContent); log('Candidate copied to clipboard.'); }
    catch (_) { log('Clipboard access was blocked by the browser.', true); }
  };
  refresh();
})();
</script>
</body>
</html>
