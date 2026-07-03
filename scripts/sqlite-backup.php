<?php

declare(strict_types=1);

/**
 * WAL-safe SQLite file copy. Usage: php sqlite-backup.php SOURCE DEST
 */

$src = $argv[1] ?? '';
$dest = $argv[2] ?? '';

if ($src === '' || $dest === '') {
    fwrite(STDERR, "Usage: php sqlite-backup.php SOURCE DEST\n");
    exit(1);
}

if (!is_file($src)) {
    fwrite(STDERR, "Source not found: {$src}\n");
    exit(1);
}

$destDir = dirname($dest);
if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
    fwrite(STDERR, "Cannot create directory: {$destDir}\n");
    exit(1);
}

if (is_file($dest)) {
    unlink($dest);
}

if (class_exists(SQLite3::class, false)) {
    try {
        $in = new SQLite3($src, SQLITE3_OPEN_READONLY);
        $out = new SQLite3($dest);
        if ($in->backup($out)) {
            $in->close();
            $out->close();
            fwrite(STDOUT, "Backed up via SQLite3 API\n");
            exit(0);
        }
        $in->close();
        $out->close();
        if (is_file($dest)) {
            unlink($dest);
        }
        fwrite(STDERR, "SQLite3 backup failed — falling back to WAL checkpoint\n");
    } catch (\Throwable $e) {
        if (is_file($dest)) {
            unlink($dest);
        }
        fwrite(STDERR, "SQLite3 backup error ({$e->getMessage()}) — falling back to WAL checkpoint\n");
    }
}

$pages = 0;
try {
    $pdo = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    $pages = (int) $pdo->query('PRAGMA page_count')->fetchColumn();
    $pdo = null;
} catch (\Throwable $e) {
    fwrite(STDERR, "WAL checkpoint skipped ({$e->getMessage()}) — copying file as-is\n");
}

if (!copy($src, $dest)) {
    fwrite(STDERR, "Copy failed\n");
    exit(1);
}

$check = new PDO('sqlite:' . $dest, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = (string) $check->query('PRAGMA integrity_check')->fetchColumn();
if ($result !== 'ok') {
    fwrite(STDERR, "Integrity check failed on backup: {$result}\n");
    unlink($dest);
    exit(1);
}

fwrite(STDOUT, "Backed up via WAL checkpoint + copy ({$pages} pages)\n");