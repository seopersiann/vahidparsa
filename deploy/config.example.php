<?php
// Copy to /home/USERNAME/vahidparsa-deploy/config.php, OUTSIDE public_html.
// chmod 0600 via DirectAdmin File Manager. Never commit your real config.
return [
    'enabled' => false, // Keep false until dry-run succeeds and you review plan.json.
    'repository' => 'seopersiann/vahidparsa',
    'branch' => 'main',
    'token' => '', // Fine-grained PAT: selected repo, Contents: Read-only. Public repo: optional.
    'target' => '/home/USERNAME/domains/vahidparsa.com/public_html',
    'storage' => '/home/USERNAME/vahidparsa-deploy/data',
    'keep_backups' => 5,
    // Only these repository paths may be published. No PHP/backend execution.
    'publish' => ['index.html', '404.html', 'robots.txt', 'sitemap.xml',
                  'assets/', 'about/', 'articles/', 'contact/'],
    // Add any hosting-specific files/directories you want to preserve.
    'protect' => ['.htaccess', '.user.ini', '.well-known/', 'cgi-bin/',
                  'uploads/', 'wp-content/', 'error_log', 'php.ini'],
    'max_archive_bytes' => 52428800,
    'max_unpacked_bytes' => 157286400,
    'max_files' => 5000,
    'max_file_bytes' => 16777216,
];
