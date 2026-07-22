<?php
/**
 * SMTP Connection Test Suite
 * Tests SMTP connections to multiple servers defined in .env
 *
 * Usage:
 *   php tests/test-smtp-connection.php [options]
 *
 * Options:
 *   --send-email    Also send a test email (uses TEST_RECIPIENT from .env)
 *   --server=N      Test only server N (default: all configured)
 *   --timeout=N     Override connection timeout in seconds
 *   --verbose       Show SMTP protocol exchange
 *   --results-dir   Override results directory
 *
 * @package MailBridge_SMTP_Tests
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Paths
$baseDir = dirname(__DIR__);
$envFile = $baseDir . DIRECTORY_SEPARATOR . '.env';
$resultsDir = __DIR__ . DIRECTORY_SEPARATOR . 'results';

// ─── Parse CLI arguments ─────────────────────────────────────────

$options = getopt('', ['send-email', 'server:', 'timeout:', 'verbose', 'results-dir:']);

$sendEmail = isset($options['send-email']);
$serverFilter = $options['server'] ?? null;
$overrideTimeout = $options['timeout'] ?? null;
$verbose = isset($options['verbose']);
if (isset($options['results-dir'])) {
    $resultsDir = $options['results-dir'];
}

// ─── Autoload SmtpTester ─────────────────────────────────────────

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SmtpTester.php';

// ─── Pre-flight checks ───────────────────────────────────────────

if (!file_exists($envFile)) {
    echo "[ERROR] .env file not found: {$envFile}" . PHP_EOL;
    echo "Copy .env.example to .env and fill in your SMTP credentials." . PHP_EOL;
    exit(1);
}

if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0755, true);
}

// Required PHP extensions
$required = ['openssl'];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        echo "[ERROR] Required PHP extension missing: {$ext}" . PHP_EOL;
        exit(1);
    }
}
// Note: stream_socket_client is part of PHP core, not the sockets extension

// ─── Run tests ───────────────────────────────────────────────────

try {
    $envOverrides = [];

    // Override timeout if requested
    if ($overrideTimeout !== null) {
        $envOverrides['TEST_TIMEOUT'] = (string) (int) $overrideTimeout;
    }

    // Override verbose if requested
    if ($verbose) {
        $envOverrides['TEST_VERBOSE'] = 'true';
    }

    // Filter to single server if requested
    if ($serverFilter !== null) {
        $envOverrides['TEST_SERVERS'] = (string) (int) $serverFilter;
    }

    $tester = new SmtpTester($envFile, $resultsDir, $envOverrides);

    // Determine recipient
    $recipient = $sendEmail ? ($tester->getEnv('TEST_RECIPIENT') ?: null) : null;
    if ($sendEmail && !$recipient) {
        echo "[WARNING] --send-email specified but TEST_RECIPIENT not set in .env" . PHP_EOL;
        echo "          Skipping email sending." . PHP_EOL;
    }

    $results = $tester->runAll($recipient);

    // Exit code: 0 if all passed, 1 if any failed
    $failed = count(array_filter($results, fn($r) => !$r['success']));
    exit($failed > 0 ? 1 : 0);

} catch (RuntimeException $e) {
    echo "[ERROR] " . $e->getMessage() . PHP_EOL;
    exit(1);
} catch (Throwable $e) {
    echo "[FATAL] " . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    echo "        " . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    exit(1);
}
