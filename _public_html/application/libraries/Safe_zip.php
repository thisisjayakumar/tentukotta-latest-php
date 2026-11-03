<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Safe archive validation and extraction.
 *
 * The web updater accepts an admin-supplied ZIP. Before anything is written
 * to disk this class rejects:
 *
 *   - path traversal (`../`, backslash variants) and absolute paths
 *   - symlinks and entries with NUL bytes
 *   - archives that are too large, contain too many entries, or expand past
 *     a sane total (zip-bomb guard)
 *   - server-configuration and executable file types, unless the caller
 *     explicitly opted in (the updater does, after its own admin gate)
 *
 * Validation reads the central directory first, so a rejected archive writes
 * nothing to the destination.
 */
class Safe_zip
{
    const MAX_ARCHIVE_BYTES = 209715200; // 200 MB on disk
    const MAX_TOTAL_BYTES   = 524288000; // 500 MB uncompressed
    const MAX_ENTRIES       = 2000;
    const MAX_ENTRY_BYTES   = 52428800;  // 50 MB per entry

    const ALWAYS_BLOCKED = ['htaccess'];

    const PHP_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phtml', 'pht', 'phar',
    ];

    /**
     * Extract an archive, or return false without writing anything.
     *
     * @param bool $allowPhp Permit executable PHP entries. Only the updater
     *                       passes true, and only behind ALLOW_WEB_UPDATER.
     */
    public static function extract(string $zipPath, string $destination, bool $allowPhp = false): bool
    {
        if (self::validate($zipPath, $allowPhp) !== null) {
            return false;
        }

        if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        $ok = $zip->extractTo($destination);
        $zip->close();

        return $ok === true;
    }

    /**
     * Return a human-readable rejection reason, or null when the archive is
     * safe to extract.
     */
    public static function validate(string $zipPath, bool $allowPhp = false): ?string
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            return 'archive is not readable';
        }

        if (filesize($zipPath) > self::MAX_ARCHIVE_BYTES) {
            return 'archive is too large';
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'archive is not a valid zip file';
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                return 'archive has too many entries';
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || !isset($stat['name'])) {
                    return 'unreadable archive entry';
                }

                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === '' || strpos($name, "\0") !== false) {
                    return 'invalid archive entry name';
                }
                if ($name[0] === '/' || preg_match('#^[A-Za-z]:/#', $name) === 1) {
                    return 'archive contains an absolute path';
                }

                $segments = explode('/', $name);
                if (in_array('..', $segments, true)) {
                    return 'archive contains a path traversal';
                }

                $size = (int) ($stat['size'] ?? 0);
                if ($size > self::MAX_ENTRY_BYTES) {
                    return 'archive entry is too large';
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    return 'archive expands beyond the allowed size';
                }

                if (self::isSymlink($zip, $i)) {
                    return 'archive contains a symlink';
                }

                $basename = strtolower(basename($name));
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if ($basename === '.user.ini' || $basename === '.htaccess'
                    || in_array($extension, self::ALWAYS_BLOCKED, true)) {
                    return 'archive contains a blocked file type';
                }

                if (!$allowPhp && in_array($extension, self::PHP_EXTENSIONS, true)) {
                    return 'archive contains a blocked file type';
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Detect a Unix symlink entry via the external attributes field.
     */
    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        // Only Unix (3) stores a mode in the high 16 bits.
        if ($opsys !== 3) {
            return false;
        }

        $mode = ($attributes >> 16) & 0xFFFF;

        return ($mode & 0170000) === 0120000;
    }
}
