<?php
declare(strict_types=1);
require dirname(__DIR__) . '/Publisher.php';
function ok(bool $condition, string $name): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } echo "PASS: $name\n"; }
function fails(Closure $fn, string $name): void { try { $fn(); } catch (Throwable $e) { echo "PASS: $name\n"; return; } throw new RuntimeException('FAIL: expected rejection: ' . $name); }
function fixture(string $dir, array $entries): array {
    $files = [];
    foreach ($entries as $p => $bytes) { Publisher::atomic($dir . '/' . $p, $bytes); $files[$p] = hash('sha256', $bytes); }
    return $files;
}
$root = sys_get_temp_dir() . '/vp-publisher-test-' . bin2hex(random_bytes(5));
$c = require dirname(__DIR__) . '/config.example.php';
$c['target'] = $root . '/site'; $c['storage'] = $root . '/data';
Publisher::mkdir($c['target']);
try {
    $publisher = new Publisher($c); ok($publisher->lock(), 'first lock acquired');
    $other = new Publisher($c); ok(!$other->lock(), 'concurrent update blocked');
    Publisher::atomic($c['target'] . '/index.html', '<html>old</html>');
    Publisher::atomic($c['target'] . '/.htaccess', 'host configuration');
    Publisher::atomic($c['target'] . '/config.php', '<?php secret');
    Publisher::atomic($c['target'] . '/assets/manual.txt', 'unmanaged');
    $stage = $root . '/stage';
    $files = fixture($stage, ['index.html' => '<html><link href="assets/site.css"></html>', 'assets/site.css' => 'body{}', 'articles/old.html' => '<html>old article</html>']);
    $plan = $publisher->rehearse($stage, $files, str_repeat('a', 40));
    ok(file_get_contents($c['target'] . '/index.html') === '<html>old</html>' && !is_file($c['storage'] . '/state.json'), 'dry-run leaves live files and state unchanged');
    $backup = $publisher->publish($stage, $files, str_repeat('a', 40));
    ok(file_get_contents($c['target'] . '/assets/site.css') === 'body{}', 'new assets published');
    ok((fileperms($c['target'] . '/articles') & 0777) === 0755, 'new public directories readable');
    ok(is_file($c['storage'] . '/backups/' . $backup . '/backup.json'), 'backup precedes deployment');
    $files2 = fixture($root . '/stage2', ['index.html' => '<html>next</html>', 'assets/new.css' => 'new']);
    $publisher->publish($root . '/stage2', $files2, str_repeat('b', 40));
    ok(!is_file($c['target'] . '/articles/old.html') && !is_file($c['target'] . '/assets/site.css'), 'removed managed files deleted');
    ok(file_get_contents($c['target'] . '/assets/manual.txt') === 'unmanaged', 'unmanaged file retained');
    ok(file_get_contents($c['target'] . '/.htaccess') === 'host configuration' && file_get_contents($c['target'] . '/config.php') === '<?php secret', 'hosting config preserved');
    $publisher->rollback($publisher->state()['backup']);
    ok($publisher->state()['sha'] === str_repeat('a', 40) && is_file($c['target'] . '/articles/old.html'), 'rollback restores prior files and state');
    $broken = new Publisher($c, static function (): void { throw new RuntimeException('simulated I/O failure'); });
    fails(fn() => $broken->publish($root . '/stage2', $files2, str_repeat('c', 40)), 'injected mid-update failure');
    ok($publisher->state()['sha'] === str_repeat('a', 40) && file_get_contents($c['target'] . '/assets/site.css') === 'body{}' && !is_file($c['storage'] . '/pending.json'), 'automatic rollback after partial update');
    $id = $publisher->publish($root . '/stage2', $files2, str_repeat('d', 40));
    Publisher::atomic($c['storage'] . '/pending.json', json_encode(['id' => $id]));
    ok($publisher->recover() && $publisher->state()['sha'] === str_repeat('a', 40), 'interrupted journal recovery');
    foreach (['../escape.html', '/absolute.html', 'assets/../../escape.html', 'assets/secret.php', '.htaccess', 'assets/.env', 'assets/config.json', 'assets/evil.php.css'] as $path) { ok(!$publisher->allowed($path), 'protected path: ' . $path); }
    symlink($root . '/stage2', $c['target'] . '/assets/link');
    fails(fn() => $publisher->plan(['assets/link/new.css' => 'x']), 'target symlink refused');
    $zipfile = $root . '/test.zip';
    $zip = new ZipArchive(); $zip->open($zipfile, ZipArchive::CREATE); $zip->addFromString('repo/index.html', '<html>zip</html>'); $zip->addFromString('repo/.htaccess', 'overwrite'); $zip->addFromString('repo/deploy/deploy.php', 'no'); $zip->close();
    $extracted = $publisher->extract($zipfile, $root . '/extract');
    ok(array_keys($extracted) === ['index.html'], 'archive publishes only allowed static files');
    unlink($zipfile); $zip = new ZipArchive(); $zip->open($zipfile, ZipArchive::CREATE); $zip->addFromString('repo/../escape.html', 'bad'); $zip->close();
    fails(fn() => $publisher->extract($zipfile, $root . '/bad'), 'ZIP traversal blocked');
    unlink($zipfile); $zip = new ZipArchive(); $zip->open($zipfile, ZipArchive::CREATE); $zip->addFromString('repo/index.html', 'target'); $zip->setExternalAttributesName('repo/index.html', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
    fails(fn() => $publisher->extract($zipfile, $root . '/bad2'), 'ZIP symlink blocked');
    unlink($zipfile); $zip = new ZipArchive(); $zip->open($zipfile, ZipArchive::CREATE); $zip->addFromString('repo/index.html', '<html>too large</html>'); $zip->close();
    $c['max_unpacked_bytes'] = 1; $small = new Publisher($c);
    fails(fn() => $small->extract($root . '/test.zip', $root . '/bad3'), 'expanded archive size limit enforced');
    fails(fn() => $publisher->validate($root . '/stage2', ['index.html' => str_repeat('0', 64)]), 'stage tampering detected');
    $badFiles = fixture($root . '/broken-links', ['index.html' => '<html><img src="missing.webp"></html>']);
    fails(fn() => $publisher->validate($root . '/broken-links', $badFiles), 'missing local asset rejected');
    $id = $publisher->publish($root . '/stage2', $files2, str_repeat('e', 40));
    Publisher::atomic($c['target'] . '/index.html', '<html>external edit</html>');
    fails(fn() => $publisher->rollback($id), 'external edit conflict is preserved on rollback');
    ok(file_get_contents($c['target'] . '/index.html') === '<html>external edit</html>', 'conflicting file not overwritten');
    echo "All tests passed. Only isolated temporary fixtures were changed.\n";
} finally { Publisher::remove($root); }
