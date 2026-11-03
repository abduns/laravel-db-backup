<?php

namespace Abduns\DbBackup\Dumpers;

use Abduns\DbBackup\Exceptions\DumpFailed;
use Abduns\DbBackup\Support\TemporaryDirectory;

trait CompressesDumps
{
    /**
     * Gzip $source into $destination in chunks, so large dumps never sit in
     * memory. The result is readable by this user only.
     *
     * @throws DumpFailed
     */
    protected function gzip(string $source, string $destination): void
    {
        $input = @fopen($source, 'rb');
        $output = @gzopen($destination, 'wb6');

        if ($input === false || $output === false) {
            throw new DumpFailed(sprintf('Could not compress the dump into %s.', $destination));
        }

        TemporaryDirectory::restrict($destination);

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false || gzwrite($output, $chunk) === false) {
                    throw new DumpFailed(sprintf('Could not compress the dump into %s.', $destination));
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }
}
