<?php
declare(strict_types=1);

/** Static-site publisher. No shell commands; all mutable state stays outside docroot. */
final class Publisher
{
    private array $c;
    private string $target;
    private string $storage;
    private $lock;
    private ?Closure $fault;

    public function __construct(array $config, ?Closure $fault = null)
    {
        $this->c = $config;
        $this->target = rtrim((string)realpath($config['target']), '/');
        if ($this->target === '' || $this->target === '/' || !is_dir($this->target)) {
            throw new RuntimeException('Target must be an existing directory.');
        }
        self::mkdir($config['storage']);
        $this->storage = rtrim((string)realpath($config['storage']), '/');
        if (self::inside($this->storage, $this->target) || self::inside($this->target, $this->storage)) {
            throw new RuntimeException('Storage and target must be separate non-nested directories.');
        }
        if (!is_writable($this->target) || !is_writable($this->storage)) {
            throw new RuntimeException('Target and storage must be writable.');
        }
        $this->fault = $fault;
    }

    public static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    public static function mkdir(string $path, int $mode = 0700): void
    {
        if (is_dir($path)) { return; }
        if (dirname($path) !== $path && !is_dir(dirname($path))) { self::mkdir(dirname($path), $mode); }
        if ((!mkdir($path, $mode) && !is_dir($path)) || !chmod($path, $mode)) {
            throw new RuntimeException('Cannot create directory with required permissions.');
        }
    }

    public function lock(): bool
    {
        $this->lock = fopen($this->storage . '/update.lock', 'c');
        if (!$this->lock) { throw new RuntimeException('Cannot open lock.'); }
        return flock($this->lock, LOCK_EX | LOCK_NB);
    }

    public static function safePath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !preg_match('/[\x00-\x1f\x7f:]/', $path)
            && !array_intersect(explode('/', $path), ['.', '..', '']);
    }

    public function allowed(string $path): bool
    {
        if (!self::safePath($path)) { return false; }
        foreach (explode('/', $path) as $part) {
            if (str_starts_with($part, '.') || preg_match('/^(?:config|secrets?|credentials?|error_log)(?:\.|$)/i', $part)) {
                return false;
            }
        }
        if (preg_match('/\.(?:php[0-9]*|phtml|phar|cgi|pl|sh)(?:\.|$)/i', $path)) { return false; }
        if (!preg_match('/\.(?:html|css|js|json|svg|jpe?g|png|webp|gif|avif|woff2?|ttf|otf|ico|txt|xml)$/i', $path)) {
            return false;
        }
        foreach ($this->c['protect'] as $rule) {
            if ($path === rtrim($rule, '/') || (str_ends_with($rule, '/') && str_starts_with($path, $rule))) {
                return false;
            }
        }
        foreach ($this->c['publish'] as $rule) {
            if ($path === $rule || (str_ends_with($rule, '/') && str_starts_with($path, $rule))) { return true; }
        }
        return false;
    }

    /** Refuse symlinks in the document tree, including a dangling link at the leaf. */
    private function targetPath(string $path): string
    {
        if (!$this->allowed($path)) { throw new RuntimeException('Refused protected or invalid target path.'); }
        $current = $this->target;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) { throw new RuntimeException('Symlink in target path; no changes made to it.'); }
        }
        if (file_exists($current) && !is_file($current)) { throw new RuntimeException('File/directory conflict in target.'); }
        return $current;
    }

    public static function atomic(string $path, string $bytes, int $mode = 0600): void
    {
        self::mkdir(dirname($path));
        $tmp = tempnam(dirname($path), '.vp-');
        if ($tmp === false) { throw new RuntimeException('Cannot create temporary file.'); }
        try {
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !chmod($tmp, $mode) || !rename($tmp, $path)) {
                throw new RuntimeException('Atomic write failed.');
            }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
    }

    private function json(string $name, array $value): void
    {
        self::atomic($this->storage . '/' . $name, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function state(): array
    {
        $file = $this->storage . '/state.json';
        return is_file($file) ? json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : ['sha' => null, 'files' => []];
    }

    /** Extract entries individually; never trust ZipArchive::extractTo paths or symlink entries. */
    public function extract(string $archive, string $destination): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('Invalid GitHub ZIP archive.'); }
        $total = 0; $seen = []; $files = []; $wrapper = null;
        try {
            if ($zip->numFiles > $this->c['max_files']) { throw new RuntimeException('Archive has too many entries.'); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name']; $trimmed = rtrim($name, '/');
                if (!self::safePath($trimmed)) { throw new RuntimeException('Unsafe archive path.'); }
                $parts = explode('/', $trimmed, 2);
                $wrapper ??= $parts[0];
                if ($parts[0] !== $wrapper) { throw new RuntimeException('Archive must have one root directory.'); }
                $opsys = 0; $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                $type = ($attr >> 16) & 0170000;
                if ($type && !in_array($type, [0100000, 0040000], true)) { throw new RuntimeException('Archive contains a link or special file.'); }
                $total += $entry['size'];
                if ($total > $this->c['max_unpacked_bytes']) { throw new RuntimeException('Archive exceeds expanded size limit.'); }
                if (str_ends_with($name, '/') || count($parts) === 1) { continue; }
                $path = $parts[1];
                if (isset($seen[$path])) { throw new RuntimeException('Duplicate archive path.'); }
                $seen[$path] = true;
                if (!$this->allowed($path)) { continue; }
                if ($entry['size'] > ($this->c['max_file_bytes'] ?? 16777216)) { throw new RuntimeException('Static file exceeds individual size limit.'); }
                $bytes = $zip->getFromIndex($i, $entry['size'] + 1);
                if ($bytes === false || strlen($bytes) !== $entry['size']) { throw new RuntimeException('ZIP entry failed integrity check.'); }
                self::atomic($destination . '/' . $path, $bytes);
                $files[$path] = hash('sha256', $bytes);
            }
        } finally { $zip->close(); }
        ksort($files);
        $this->validate($destination, $files);
        return $files;
    }

    public function validate(string $stage, array $files): void
    {
        $stage = (string)realpath($stage);
        if ($stage === '') { throw new RuntimeException('Stage directory is missing.'); }
        if (!isset($files['index.html']) || !str_contains((string)file_get_contents($stage . '/index.html'), '<html')) {
            throw new RuntimeException('Valid root index.html is required.');
        }
        foreach ($files as $path => $hash) {
            if (!$this->allowed($path) || hash_file('sha256', $stage . '/' . $path) !== $hash) { throw new RuntimeException('Stage integrity check failed.'); }
            // Verify local HTML assets without requesting external services.
            if (!str_ends_with($path, '.html')) { continue; }
            preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', (string)file_get_contents($stage . '/' . $path), $matches);
            foreach ($matches[1] as $url) {
                if ($url === '' || $url[0] === '#' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) { continue; }
                $urlPath = rawurldecode((string)parse_url(html_entity_decode($url), PHP_URL_PATH));
                if ($urlPath === '') { continue; }
                $base = str_starts_with($urlPath, '/') ? $stage : dirname($stage . '/' . $path);
                $resolved = realpath($base . '/' . ltrim($urlPath, '/'));
                if (!$resolved || !self::inside($resolved, $stage)) { throw new RuntimeException('Broken local HTML link or asset in stage.'); }
            }
        }
    }

    public function plan(array $files): array
    {
        $before = $this->state(); $changes = [];
        foreach ($files as $path => $hash) {
            $dest = $this->targetPath($path);
            if (!is_file($dest) || hash_file('sha256', $dest) !== $hash) { $changes[$path] = $hash; }
        }
        foreach ($before['files'] as $path => $_) {
            // Only previously managed files may be deleted. Current protections always win.
            if (!isset($files[$path]) && $this->allowed($path) && is_file($this->targetPath($path))) { $changes[$path] = null; }
        }
        ksort($changes);
        return $changes;
    }

    public function publish(string $stage, array $files, string $sha): string
    {
        $this->validate($stage, $files);
        if (is_file($this->storage . '/pending.json')) { throw new RuntimeException('Pending transaction requires recovery.'); }
        $plan = $this->plan($files);
        $bytes = 0;
        foreach ($plan as $path => $hash) { $dest = $this->targetPath($path); $bytes += is_file($dest) ? filesize($dest) : 0; $bytes += $hash ? filesize($stage . '/' . $path) : 0; }
        $free = disk_free_space($this->storage); $targetFree = disk_free_space($this->target);
        if ($free === false || $targetFree === false || min($free, $targetFree) < $bytes * 2 + 10485760) {
            throw new RuntimeException('Insufficient free disk space.');
        }
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backup = $this->storage . '/backups/' . $id;
        self::mkdir($backup . '/files');
        $journal = ['id' => $id, 'target' => $this->target, 'previous' => $this->state(), 'sha' => $sha, 'changes' => []];
        foreach ($plan as $path => $hash) {
            $dest = $this->targetPath($path); $exists = is_file($dest);
            $journal['changes'][$path] = ['before' => $exists ? hash_file('sha256', $dest) : null, 'after' => $hash,
                'mode' => $exists ? (fileperms($dest) & 0777) : 0644];
            if ($exists) { self::atomic($backup . '/files/' . $path, (string)file_get_contents($dest)); }
        }
        foreach ($journal['changes'] as $path => $entry) {
            if ($entry['before'] !== null && hash_file('sha256', $backup . '/files/' . $path) !== $entry['before']) {
                throw new RuntimeException('Backup verification failed before publication.');
            }
        }
        // Persist a complete, validated backup before the first mutation of the live tree.
        self::atomic($backup . '/backup.json', json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->json('pending.json', ['id' => $id]);
        try {
            foreach ($plan as $path => $hash) {
                $dest = $this->targetPath($path);
                if ($hash === null) { if (!unlink($dest)) { throw new RuntimeException('Managed file removal failed.'); } }
                else {
                    self::mkdir(dirname($dest), 0755);
                    self::atomic($dest, (string)file_get_contents($stage . '/' . $path), 0644);
                    if (hash_file('sha256', $dest) !== $hash) { throw new RuntimeException('Published file hash mismatch.'); }
                }
                if ($this->fault) { ($this->fault)($path); }
            }
            foreach ($files as $path => $hash) {
                if (hash_file('sha256', $this->targetPath($path)) !== $hash) { throw new RuntimeException('Final verification failed.'); }
            }
            $this->json('state.json', ['sha' => $sha, 'files' => $files, 'backup' => $id, 'at' => gmdate(DATE_ATOM)]);
            if (!unlink($this->storage . '/pending.json')) { throw new RuntimeException('Cannot finalize transaction.'); }
        } catch (Throwable $e) {
            $this->rollback($id);
            throw new RuntimeException('Publication failed; previous version restored.', 0, $e);
        }
        return $id;
    }

    public function rollback(string $id): void
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $id)) { throw new RuntimeException('Invalid backup identifier.'); }
        $backup = $this->storage . '/backups/' . $id;
        $journal = json_decode((string)file_get_contents($backup . '/backup.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($journal['target'] !== $this->target) { throw new RuntimeException('Backup target mismatch.'); }
        // Validate all conflicts and backup hashes before restoring any file.
        foreach ($journal['changes'] as $path => $entry) {
            $dest = $this->targetPath($path); $now = is_file($dest) ? hash_file('sha256', $dest) : null;
            if ($now !== $entry['before'] && $now !== $entry['after']) { throw new RuntimeException('External file change conflicts with rollback; resolve manually.'); }
            if ($entry['before'] !== null && hash_file('sha256', $backup . '/files/' . $path) !== $entry['before']) {
                throw new RuntimeException('Backup integrity check failed.');
            }
        }
        foreach ($journal['changes'] as $path => $entry) {
            $dest = $this->targetPath($path);
            if ($entry['before'] !== null) {
                self::mkdir(dirname($dest), 0755);
                self::atomic($dest, (string)file_get_contents($backup . '/files/' . $path), $entry['mode']);
            } elseif (is_file($dest) && !unlink($dest)) { throw new RuntimeException('Rollback removal failed.'); }
        }
        $this->json('state.json', $journal['previous']);
        if (is_file($this->storage . '/pending.json') && !unlink($this->storage . '/pending.json')) { throw new RuntimeException('Recovery journal cleanup failed.'); }
    }

    public function recover(): bool
    {
        $file = $this->storage . '/pending.json';
        if (!is_file($file)) { return false; }
        $this->rollback(json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['id']);
        return true;
    }

    /** Dry-run uses the same publication and rollback engine against a private clone. */
    public function rehearse(string $stage, array $files, string $sha): array
    {
        $plan = $this->plan($files); $sandbox = $this->storage . '/rehearsal';
        self::remove($sandbox); self::mkdir($sandbox . '/site');
        foreach (array_unique(array_merge(array_keys($files), array_keys($plan))) as $path) {
            if (!$this->allowed($path)) { continue; }
            $source = $this->targetPath($path);
            if (is_file($source)) { self::atomic($sandbox . '/site/' . $path, (string)file_get_contents($source)); }
        }
        $config = $this->c; $config['target'] = $sandbox . '/site'; $config['storage'] = $sandbox . '/data';
        $trial = new self($config);
        $trial->json('state.json', $this->state());
        $before = [];
        foreach ($plan as $path => $_) { $p = $sandbox . '/site/' . $path; $before[$path] = is_file($p) ? hash_file('sha256', $p) : null; }
        $id = $trial->publish($stage, $files, $sha);
        $trial->rollback($id);
        foreach ($before as $path => $hash) {
            $p = $sandbox . '/site/' . $path;
            if ((is_file($p) ? hash_file('sha256', $p) : null) !== $hash) { throw new RuntimeException('Rehearsal rollback failed.'); }
        }
        $this->json('plan.json', ['sha' => $sha, 'at' => gmdate(DATE_ATOM), 'rehearsal_passed' => true, 'changes' => $plan]);
        self::remove($sandbox);
        return $plan;
    }

    public function prune(): void
    {
        $paths = glob($this->storage . '/backups/*', GLOB_ONLYDIR) ?: [];
        rsort($paths);
        foreach (array_slice($paths, max(2, (int)$this->c['keep_backups'])) as $path) { self::remove($path); }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { if (!unlink($path)) { throw new RuntimeException('Cannot remove private temporary file.'); } return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { self::remove($path . '/' . $name); } }
        if (!rmdir($path)) { throw new RuntimeException('Cannot remove private temporary directory.'); }
    }
}
