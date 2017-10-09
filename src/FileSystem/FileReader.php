<?php
/**
 * @copyright 2017 Hostnet B.V.
 */
declare(strict_types=1);

namespace Hostnet\Component\Resolver\FileSystem;

use Hostnet\Component\Resolver\File;

/**
 * Implementation of the ReaderInterface which reads a file.
 */
final class FileReader implements ReaderInterface
{
    private $cwd;

    public function __construct(string $cwd)
    {
        $this->cwd = $cwd;
    }

    public function read(File $file): string
    {
        $path = $file->path;

        if (!File::isAbsolutePath($path)) {
            $path = $this->cwd . '/' . $path;
        }

        return file_get_contents($path);
    }
}