<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require __DIR__ . '/Publisher.php';

/** HTTPS only. Authorization is sent only to api.github.com, never to redirect hosts. */
function githubRequest(string $url, string $token, ?string $destination = null, int $limit = 1048576): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, ['api.github.com', 'codeload.github.com'], true)) {
        throw new RuntimeException('Refused download host.');
    }
    $headers = ['User-Agent: VahidParsa-Private-Puller/1.0', 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '' && $host === 'api.github.com') { $headers[] = 'Authorization: Bearer ' . $token; }
    $out = $destination ? fopen($destination, 'wb') : null;
    if ($destination && !$out) { throw new RuntimeException('Cannot create archive file.'); }
    $body = ''; $location = null; $size = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FAILONERROR => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) { $location = trim(substr($line, 9)); }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $bytes) use (&$body, &$size, $limit, $out): int {
            $size += strlen($bytes);
            if ($size > $limit) { return 0; }
            if ($out) { return fwrite($out, $bytes); }
            $body .= $bytes;
            return strlen($bytes);
        },
    ]);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); if ($out) { fclose($out); }
    if ($ok === false) { throw new RuntimeException('HTTPS transfer failed or exceeded size limit; check outbound access, TLS and limits.'); }
    if (!in_array($code, [200, 302], true)) {
        throw new RuntimeException('GitHub HTTP ' . $code . '; check token, repository access, rate limits or server connectivity.');
    }
    return ['code' => $code, 'body' => $body, 'location' => $location];
}

function report(string $storage, string $event, array $data = []): void
{
    $file = $storage . '/deploy.log';
    if (is_file($file) && filesize($file) > 1048576) {
        if (is_file($file . '.1')) { unlink($file . '.1'); }
        rename($file, $file . '.1');
    }
    $line = json_encode(['at' => gmdate(DATE_ATOM), 'event' => $event] + $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

$storage = null; $work = null; $publisher = null; $exitCode = 0;
try {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) { return false; }
        throw new RuntimeException('PHP operation failed at ' . basename($file) . ':' . $line . '; inspect permissions, quota and configuration.');
    });
    $args = array_slice($argv, 1); $configFile = __DIR__ . '/config.php'; $command = 'check';
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--config=')) { $configFile = substr($arg, 9); }
        elseif (in_array($arg, ['check', 'dry-run', 'run', 'rollback', 'recover'], true)) { $command = $arg; }
        else { throw new RuntimeException('Usage: php deploy.php [--config=/absolute/config.php] check|dry-run|run|rollback|recover'); }
    }
    if (PHP_VERSION_ID < 80100 || !extension_loaded('curl') || !extension_loaded('zip') || !extension_loaded('openssl')) {
        throw new RuntimeException('Requires PHP CLI >= 8.1 with cURL, ZipArchive and OpenSSL.');
    }
    foreach (['flock', 'hash_file', 'random_bytes', 'disk_free_space', 'rename'] as $function) {
        if (!is_callable($function)) { throw new RuntimeException('A required PHP function is disabled.'); }
    }
    if (!is_file($configFile)) { throw new RuntimeException('Copy config.example.php to private config.php and edit paths first.'); }
    $c = require $configFile;
    if (!is_array($c) || !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $c['repository'] ?? '') || empty($c['branch'])) {
        throw new RuntimeException('Invalid configuration.');
    }
    $target = realpath($c['target']);
    if (!$target || Publisher::inside((string)realpath($configFile), $target) || Publisher::inside(__DIR__, $target)) {
        throw new RuntimeException('Scripts and config must be outside document root.');
    }
    if ((fileperms($configFile) & 0077) !== 0) { throw new RuntimeException('Set config.php permissions to 0600 (owner only).'); }
    if (str_contains($c['token'], "\n") || str_contains($c['token'], "\r")) { throw new RuntimeException('Invalid token.'); }
    $publisher = new Publisher($c); $storage = realpath($c['storage']);
    if ((fileperms($storage) & 0077) !== 0) { throw new RuntimeException('Storage permissions must be 0700 (owner only).'); }
    if (!$publisher->lock()) { report($storage, 'locked_skip'); exit(0); }
    if ($command === 'rollback' || $command === 'recover') {
        if ($c['enabled']) { throw new RuntimeException('Pause first: set enabled=false before rollback/recover.'); }
        if ($command === 'recover') {
            report($storage, 'recovery', ['restored' => $publisher->recover()]);
        } else {
            $state = $publisher->state();
            if (empty($state['backup'])) { throw new RuntimeException('No current deployment backup to restore.'); }
            $publisher->rollback($state['backup']); report($storage, 'rollback', ['backup' => $state['backup']]);
        }
        exit(0);
    }
    if ($command === 'run' && $c['enabled'] && $publisher->recover()) {
        report($storage, 'interrupted_deployment_restored'); exit(0);
    }
    if (is_file($storage . '/pending.json')) { throw new RuntimeException('Pending transaction exists; recover it before testing or publishing.'); }
    if ($command === 'run' && !$c['enabled']) { report($storage, 'paused_no_live_changes'); exit(0); }
    $api = 'https://api.github.com/repos/' . $c['repository'];
    $response = githubRequest($api . '/commits/' . rawurlencode($c['branch']), $c['token']);
    $info = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR); $sha = $info['sha'] ?? '';
    if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) { throw new RuntimeException('GitHub returned an invalid commit.'); }
    if ($command === 'check') {
        report($storage, 'check_passed', ['php' => PHP_VERSION, 'sha' => $sha, 'enabled' => (bool)$c['enabled'],
            'target' => $target, 'existing_index_php' => is_file($target . '/index.php'), 'free_bytes' => disk_free_space($storage), 'zip' => true, 'curl' => true]); exit(0);
    }
    if ($command === 'run' && $publisher->state()['sha'] === $sha) { report($storage, 'unchanged', ['sha' => $sha]); exit(0); }
    $work = $storage . '/work'; Publisher::remove($work); Publisher::mkdir($work . '/stage');
    $response = githubRequest($api . '/zipball/' . $sha, $c['token']);
    if ($response['code'] !== 302 || !$response['location'] || parse_url($response['location'], PHP_URL_HOST) !== 'codeload.github.com') {
        throw new RuntimeException('Unexpected GitHub archive redirect.');
    }
    $download = githubRequest($response['location'], '', $work . '/source.zip', $c['max_archive_bytes']);
    if ($download['code'] !== 200) { throw new RuntimeException('Archive download did not complete.'); }
    $files = $publisher->extract($work . '/source.zip', $work . '/stage');
    $plan = $publisher->rehearse($work . '/stage', $files, $sha);
    report($storage, 'dry_run_passed', ['sha' => $sha, 'changes' => count($plan), 'plan' => 'plan.json']);
    if ($command === 'dry-run' || !$c['enabled']) {
        report($storage, 'no_live_changes', ['reason' => $command === 'dry-run' ? 'dry-run command' : 'publishing disabled']);
    } else {
        $id = $publisher->publish($work . '/stage', $files, $sha);
        report($storage, 'published', ['sha' => $sha, 'backup' => $id]); $publisher->prune();
    }
} catch (Throwable $e) {
    // No HTTP bodies, response headers, redirect URLs or tokens are ever logged.
    $message = $e instanceof JsonException ? 'Invalid JSON in private state or GitHub response.' : $e->getMessage();
    if ($storage) { report($storage, 'error', ['message' => $message]); }
    else { fwrite(STDERR, $message . "\n"); }
    $exitCode = 1;
} finally {
    if ($work && is_dir($work)) { Publisher::remove($work); }
}

exit($exitCode);
