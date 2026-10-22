<?php

namespace App\Service;

/**
 * Replace a file's contents so readers see either the old file or the new
 * one, never a truncated or half-written one.
 *
 * Plain file_put_contents() truncates first and then writes, so a request
 * reading stig_toc.json during a cron rebuild could decode an empty or
 * partial file (review S7). This writes a uniquely named temp file in the
 * same directory and rename()s it over the target, which is atomic on one
 * filesystem. Same pattern SyncStatus and IndexStore already use.
 */
final class AtomicFile
{
    /**
     * @param int $mode final permissions; 0644 matches bin/fix-perms.sh
     * @return bool false if the temp file couldn't be written or renamed;
     *              the existing file is untouched in that case
     */
    public static function write(string $path, string $contents, int $mode = 0644): bool
    {
        $dir = dirname($path);
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            return false;
        }
        $ok = fwrite($fh, $contents) === strlen($contents) && fflush($fh);
        fclose($fh);

        if (!$ok || !@chmod($tmp, $mode) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
