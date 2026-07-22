<?php
/**
 * SMTP Tester Class
 * Handles .env parsing, SMTP connection testing, and result formatting.
 *
 * @package MailBridge_SMTP_Tests
 */

class SmtpTester
{
    private array $env = [];
    private array $results = [];
    private string $resultsDir;
    private int $timeout = 10;
    private bool $verbose = false;

    public function __construct(string $envFile, string $resultsDir, array $overrides = [])
    {
        $this->resultsDir = $resultsDir;
        $this->loadEnv($envFile);
        $this->applyOverrides($overrides);
        $this->timeout = (int) ($this->env['TEST_TIMEOUT'] ?? 10);
        $this->verbose = ($this->env['TEST_VERBOSE'] ?? 'true') === 'true';
    }

    // ─── .env parsing ────────────────────────────────────────────────

    private function loadEnv(string $file): void
    {
        if (!file_exists($file)) {
            throw new RuntimeException("Missing .env file: {$file}");
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Strip surrounding quotes
            $value = trim($value, "\"'");
            $this->env[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    private function applyOverrides(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            $this->env[$key] = (string) $value;
            $_ENV[$key] = (string) $value;
        }
    }

    public function getEnv(string $key, string $default = ''): string
    {
        return $this->env[$key] ?? $default;
    }

    // ─── Server discovery ────────────────────────────────────────────

    public function getServers(): array
    {
        $requested = $this->env['TEST_SERVERS'] ?? 'all';
        $servers = [];

        $indices = ($requested === 'all')
            ? range(1, 6)
            : array_map('trim', explode(',', $requested));

        foreach ($indices as $i) {
            $host = $this->env["SMTP_{$i}_HOST"] ?? '';
            if ($host === '') {
                continue;
            }
            $servers[(int) $i] = [
                'host'         => $host,
                'port'         => (int) ($this->env["SMTP_{$i}_PORT"] ?? 587),
                'encryption'   => $this->env["SMTP_{$i}_ENCRYPTION"] ?? 'tls',
                'username'     => $this->env["SMTP_{$i}_USERNAME"] ?? '',
                'password'     => $this->env["SMTP_{$i}_PASSWORD"] ?? '',
                'from_email'   => $this->env["SMTP_{$i}_FROM_EMAIL"] ?? '',
                'from_name'    => $this->env["SMTP_{$i}_FROM_NAME"] ?? '',
            ];
        }

        return $servers;
    }

    // ─── Connection testing ──────────────────────────────────────────

    /**
     * Run all server tests.
     * Optionally send a test email to $recipient.
     */
    public function runAll(?string $recipient = null): array
    {
        $servers = $this->getServers();
        $this->results = [];

        $this->header('MailBridge SMTP Connection Test Suite');
        $this->line('PHP ' . phpversion() . ' | ' . php_sapi_name());
        $this->line('Timeout: ' . $this->timeout . 's | Servers: ' . count($servers));
        $this->line('');

        $passed = 0;
        $failed = 0;

        foreach ($servers as $id => $config) {
            $result = $this->testServer($id, $config, $recipient);
            $this->results[$id] = $result;
            $result['success'] ? $passed++ : $failed++;
        }

        $this->line('');
        $this->header('Summary');
        $this->line("Passed: {$passed}  |  Failed: {$failed}  |  Total: " . ($passed + $failed));
        $this->line('');

        $this->saveResults();

        return $this->results;
    }

    /**
     * Test a single SMTP server.
     */
    public function testServer(int $id, array $config, ?string $recipient = null): array
    {
        $label = "Server {$id}: {$config['host']}:{$config['port']} ({$config['encryption']})";
        $this->header($label);

        $result = [
            'server_id'  => $id,
            'host'       => $config['host'],
            'port'       => $config['port'],
            'encryption' => $config['encryption'],
            'success'    => false,
            'steps'      => [],
            'error'      => null,
            'timestamp'  => date('Y-m-d H:i:s'),
        ];

        // Step 1: DNS resolution
        $ip = gethostbyname($config['host']);
        $dnsOk = ($ip !== $config['host']);
        $result['steps']['dns'] = [
            'success' => $dnsOk,
            'detail'  => $dnsOk ? "Resolved to {$ip}" : 'DNS resolution failed',
        ];
        $this->stepResult('DNS Resolve', $dnsOk, $dnsOk ? $ip : 'Failed');

        if (!$dnsOk) {
            $result['error'] = 'DNS resolution failed';
            return $result;
        }

        // Step 2: TCP connection
        $errno = 0;
        $errstr = '';
        $scheme = ($config['encryption'] === 'ssl') ? 'ssl' : 'tcp';
        $target = "{$scheme}://{$config['host']}:{$config['port']}";
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ])
        );
        $connOk = ($socket !== false);
        $result['steps']['connect'] = [
            'success' => $connOk,
            'detail'  => $connOk ? 'Connected' : "Error {$errno}: {$errstr}",
        ];
        $this->stepResult('TCP Connect', $connOk, $connOk ? 'Connected' : "{$errno}: {$errstr}");

        if (!$connOk) {
            $result['error'] = "Connection failed: {$errstr}";
            return $result;
        }

        stream_set_timeout($socket, $this->timeout);

        // Step 3: Read SMTP banner
        $banner = $this->readSmtpResponse($socket);
        $bannerOk = ($banner !== false && (int) $banner['code'] === 220);
        $result['steps']['banner'] = [
            'success' => $bannerOk,
            'detail'  => $banner ? $banner['lines'][0] : 'No banner received',
        ];
        $this->stepResult('SMTP Banner', $bannerOk, $banner ? $banner['lines'][0] : 'None');

        // Step 4: EHLO
        $hostname = php_uname('n') ?: 'localhost';
        $this->writeSmtpCommand($socket, "EHLO {$hostname}");
        $ehlo = $this->readSmtpResponse($socket);
        $ehloOk = ($ehlo !== false && (int) $ehlo['code'] === 250);
        $result['steps']['ehlo'] = [
            'success' => $ehloOk,
            'detail'  => $ehlo ? implode(' | ', $ehlo['lines']) : 'EHLO failed',
        ];
        $this->stepResult('EHLO', $ehloOk, $ehlo ? 'OK' : 'Failed');

        // Step 5: Check AUTH support
        $authMethods = [];
        if ($ehlo && isset($ehlo['lines'])) {
            foreach ($ehlo['lines'] as $line) {
                if (preg_match('/AUTH\s+(.+)/i', $line, $m)) {
                    $authMethods = array_merge($authMethods, preg_split('/\s+/', trim($m[1])));
                }
            }
        }
        $hasAuth = !empty($authMethods);
        $result['steps']['auth_check'] = [
            'success' => $hasAuth,
            'detail'  => $hasAuth ? 'Methods: ' . implode(', ', $authMethods) : 'No AUTH advertised',
        ];
        $this->stepResult('AUTH Support', $hasAuth, $hasAuth ? implode(', ', $authMethods) : 'None advertised');

        // Step 6: STARTTLS (if tls requested and not already ssl)
        $tlsRequired = ($config['encryption'] === 'tls');
        $tlsOk = null;
        if ($config['encryption'] === 'tls' && $ehloOk) {
            $this->writeSmtpCommand($socket, 'STARTTLS');
            $tlsResp = $this->readSmtpResponse($socket);
            $tlsOk = ($tlsResp !== false && (int) $tlsResp['code'] === 220);

            if ($tlsOk) {
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $tlsOk = ($crypto === true);
            }

            $result['steps']['starttls'] = [
                'success' => $tlsOk,
                'detail'  => $tlsOk ? 'TLS handshake OK' : 'STARTTLS failed',
            ];
            $this->stepResult('STARTTLS', $tlsOk, $tlsOk ? 'OK' : 'Failed');

            if ($tlsOk) {
                // Re-EHLO after STARTTLS
                $this->writeSmtpCommand($socket, "EHLO {$hostname}");
                $postTlsEhlo = $this->readSmtpResponse($socket);

                if ($postTlsEhlo !== false && (int) $postTlsEhlo['code'] === 250 && isset($postTlsEhlo['lines'])) {
                    $authMethods = [];
                    foreach ($postTlsEhlo['lines'] as $line) {
                        if (preg_match('/AUTH\s+(.+)/i', $line, $m)) {
                            $authMethods = array_merge($authMethods, preg_split('/\s+/', trim($m[1])));
                        }
                    }

                    $hasAuth = !empty($authMethods);
                    $result['steps']['auth_check'] = [
                        'success' => $hasAuth,
                        'detail'  => $hasAuth ? 'Methods after STARTTLS: ' . implode(', ', $authMethods) : 'No AUTH advertised after STARTTLS',
                    ];
                }
            }
        }

        if ($tlsRequired && $tlsOk !== true) {
            $result['error'] = 'STARTTLS/TLS handshake failed';
            @fclose($socket);
            $this->line('');

            return $result;
        }

        // Step 7: Authentication (if credentials provided)
        $authAttempted = false;
        $authOk = null;
        $credentialsProvided = !empty($config['username']) && !empty($config['password']);
        if ($credentialsProvided && $hasAuth) {
            $authAttempted = true;
            $this->writeSmtpCommand($socket, "AUTH LOGIN");
            $authResp = $this->readSmtpResponse($socket);

            if ($authResp !== false && (int) $authResp['code'] === 334) {
                $this->writeSmtpCommand($socket, base64_encode($config['username']), true);
                $userResp = $this->readSmtpResponse($socket);

                if ($userResp !== false && (int) $userResp['code'] === 334) {
                    $this->writeSmtpCommand($socket, base64_encode($config['password']), true);
                    $passResp = $this->readSmtpResponse($socket);
                    $authOk = ($passResp !== false && (int) $passResp['code'] === 235);
                    $authDetail = $authOk ? 'Authenticated' : ($passResp['lines'][0] ?? 'Auth failed');
                } else {
                    $authOk = false;
                    $authDetail = 'Username rejected';
                }
            } else {
                $authOk = false;
                $authDetail = 'AUTH LOGIN not accepted';
            }

            $result['steps']['auth_login'] = [
                'success' => $authOk,
                'detail'  => $authDetail,
            ];
            $result['authenticated'] = $authOk;
            $this->stepResult('AUTH LOGIN', $authOk, $authDetail);

            if (!$authOk) {
                $result['error'] = "Authentication failed: {$authDetail}";
            }
        } else {
            if ($credentialsProvided) {
                $authAttempted = true;
                $authOk = false;
                $result['steps']['auth_login'] = [
                    'success' => false,
                    'detail'  => 'Credentials provided but AUTH was not advertised',
                ];
                $result['error'] = $result['error'] ?? 'SMTP server did not advertise AUTH support';
                $this->stepResult('AUTH LOGIN', false, 'AUTH not advertised');
            } else {
                $this->stepResult('AUTH LOGIN', null, 'Skipped (no credentials provided)');
            }
        }

        // Step 8: Optional test email
        $emailOk = null;
        if ($recipient !== null && !empty($config['from_email'])) {
            $emailOk = $this->sendTestEmail($socket, $config, $recipient);
            $result['steps']['test_email'] = [
                'success' => $emailOk,
                'detail'  => $emailOk ? "Sent to {$recipient}" : 'Failed to send',
            ];
            $this->stepResult('Test Email', $emailOk, $emailOk ? "Sent to {$recipient}" : 'Failed');

            if (!$emailOk && $result['error'] === null) {
                $result['error'] = 'Test email failed to send';
            }
        } elseif ($recipient !== null) {
            $emailOk = false;
            $result['steps']['test_email'] = [
                'success' => false,
                'detail'  => 'Missing from_email configuration',
            ];
            $result['error'] = $result['error'] ?? 'Test email requested but from_email is not configured';
            $this->stepResult('Test Email', false, 'Missing from_email configuration');
        }

        // QUIT
        $this->writeSmtpCommand($socket, 'QUIT');
        @fclose($socket);

        // Determine overall success: connection + banner + EHLO, plus requested auth/email checks.
        $tlsPassed = !$tlsRequired || $tlsOk === true;
        $authPassed = !$authAttempted || $authOk === true;
        $emailPassed = $recipient === null || $emailOk === true;
        $result['success'] = $connOk && $bannerOk && $ehloOk && $tlsPassed && $authPassed && $emailPassed;
        if (!isset($result['error']) && !$result['success']) {
            $result['error'] = 'One or more SMTP handshake steps failed';
        }

        $this->line('');
        return $result;
    }

    // ─── SMTP protocol helpers ───────────────────────────────────────

    private function sendTestEmail($socket, array $config, string $recipient): bool
    {
        $hostname = php_uname('n') ?: 'localhost';

        $this->writeSmtpCommand($socket, "MAIL FROM:<{$config['from_email']}>");
        $resp = $this->readSmtpResponse($socket);
        if (!$resp || (int) $resp['code'] !== 250) {
            return false;
        }

        $this->writeSmtpCommand($socket, "RCPT TO:<{$recipient}>");
        $resp = $this->readSmtpResponse($socket);
        if (!$resp || (int) $resp['code'] !== 250) {
            return false;
        }

        $this->writeSmtpCommand($socket, 'DATA');
        $resp = $this->readSmtpResponse($socket);
        if (!$resp || (int) $resp['code'] !== 354) {
            return false;
        }

        $body = "From: {$config['from_name']} <{$config['from_email']}>\r\n"
              . "To: {$recipient}\r\n"
              . "Subject: MailBridge SMTP Test - Server {$config['host']}\r\n"
              . "Date: " . date('r') . "\r\n"
              . "Message-ID: <" . md5(uniqid('', true)) . "@{$hostname}>\r\n"
              . "\r\n"
              . "This is a test email from the MailBridge SMTP test suite.\r\n"
              . "Server: {$config['host']}:{$config['port']}\r\n"
              . "Time: " . date('Y-m-d H:i:s') . "\r\n"
              . "\r\n";

        $this->writeSmtpCommand($socket, $body . '.');
        $resp = $this->readSmtpResponse($socket);

        return ($resp !== false && (int) $resp['code'] === 250);
    }

    private function writeSmtpCommand($socket, string $cmd, bool $sensitive = false): void
    {
        fwrite($socket, $cmd . "\r\n");
        if ($this->verbose) {
            $display = $sensitive ? '[credentials hidden]' : $cmd;
            $this->line("  >> {$display}");
        }
    }

    private function readSmtpResponse($socket): ?array
    {
        $lines = [];
        $code = '';

        while (($line = fgets($socket, 4096)) !== false) {
            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (strlen($line) >= 3) {
                $code = substr($line, 0, 3);
                // Multi-line response: 4th char is '-' continuation, ' ' is end
                if (strlen($line) === 3 || $line[3] === ' ') {
                    break;
                }
            }
        }

        if (empty($lines)) {
            return null;
        }

        if ($this->verbose) {
            foreach ($lines as $l) {
                $this->line("  << {$l}");
            }
        }

        return ['code' => $code, 'lines' => $lines];
    }

    // ─── Output formatting ───────────────────────────────────────────

    private function header(string $text): void
    {
        $this->line(str_repeat('=', 70));
        $this->line("  {$text}");
        $this->line(str_repeat('=', 70));
    }

    private function stepResult(string $step, ?bool $ok, string $detail): void
    {
        if ($ok === true) {
            $icon = '[PASS]';
        } elseif ($ok === false) {
            $icon = '[FAIL]';
        } else {
            $icon = '[SKIP]';
        }
        $padded = str_pad("{$step}:", 16);
        $this->line("  {$icon} {$padded} {$detail}");
    }

    private function line(string $msg = ''): void
    {
        echo $msg . PHP_EOL;
    }

    // ─── Results persistence ─────────────────────────────────────────

    private function saveResults(): void
    {
        $timestamp = date('Y-m-d_His');
        $file = rtrim($this->resultsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "test_{$timestamp}.json";

        $data = [
            'timestamp'  => date('Y-m-d H:i:s'),
            'php'        => phpversion(),
            'sapi'       => php_sapi_name(),
            'servers'    => $this->results,
            'summary'    => [
                'total'   => count($this->results),
                'passed'  => count(array_filter($this->results, fn($r) => $r['success'])),
                'failed'  => count(array_filter($this->results, fn($r) => !$r['success'])),
            ],
        ];

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("Results saved: {$file}");
    }

    // ─── Masking helper ──────────────────────────────────────────────

    public static function mask(string $value, int $visible = 2): string
    {
        if (strlen($value) <= $visible) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, $visible) . str_repeat('*', max(0, strlen($value) - $visible));
    }
}
