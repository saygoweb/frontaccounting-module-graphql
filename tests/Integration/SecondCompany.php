<?php

namespace FA\GraphQL\Tests\Integration;

/**
 * A second FrontAccounting company, in this process only: company 1, pointing at
 * company 0's database under the same table prefix — a configuration
 * FrontAccounting allows, and the one the Checkpoint D attack needed.
 *
 * set_global_connection() re-includes config_db.php on every call, so adding an
 * entry to $db_connections does not last. Instead this replaces the `file://`
 * stream wrapper and serves config_db.php with one extra line appended; every
 * other file operation is passed through to PHP's own wrapper. Nothing on disk
 * changes, and the process ends with the test (@runTestsInSeparateProcesses).
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName -- stream wrapper protocol
 */
final class SecondCompany
{
    /** @var resource|null */
    public $context;

    private static string $target = '';
    private static string $appended = '';

    /** @var resource|false|null */
    private $handle = null;

    /** @var resource|false|null */
    private $dir = null;

    private ?string $buffer = null;
    private int $position = 0;

    public static function install(string $faRoot): void
    {
        self::$target = (string) realpath($faRoot . '/config_db.php');
        self::$appended = "\n\$db_connections[1] = array('name' => 'Company One') + \$db_connections[0];\n";
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function uninstall(): void
    {
        if (self::$target !== '') {
            stream_wrapper_restore('file');
            self::$target = '';
        }
    }

    /**
     * @return mixed
     */
    private static function native(callable $fn)
    {
        stream_wrapper_restore('file');
        try {
            return $fn();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    private static function isTarget(string $path): bool
    {
        return basename($path) === 'config_db.php'
            && self::native(fn () => realpath($path)) === self::$target;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strpbrk($mode, 'waxc+') === false && self::isTarget($path)) {
            $this->buffer = self::native(fn () => (string) file_get_contents($path)) . self::$appended;
            $this->position = 0;

            return true;
        }
        $usePath = (bool) ($options & STREAM_USE_PATH);
        $quiet = !($options & STREAM_REPORT_ERRORS);
        $this->handle = self::native(function () use ($path, $mode, $usePath, $quiet) {
            if ($this->context !== null) {
                return $quiet
                    ? @fopen($path, $mode, $usePath, $this->context)
                    : fopen($path, $mode, $usePath, $this->context);
            }

            return $quiet ? @fopen($path, $mode, $usePath) : fopen($path, $mode, $usePath);
        });

        return $this->handle !== false;
    }

    /**
     * @return string|false
     */
    public function stream_read(int $count)
    {
        if ($this->buffer !== null) {
            $chunk = (string) substr($this->buffer, $this->position, $count);
            $this->position += strlen($chunk);

            return $chunk;
        }

        return fread($this->handle, $count);
    }

    /**
     * @return int|false
     */
    public function stream_write(string $data)
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->buffer !== null ? $this->position >= strlen($this->buffer) : feof($this->handle);
    }

    /**
     * @return int|false
     */
    public function stream_tell()
    {
        return $this->buffer !== null ? $this->position : ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->buffer === null) {
            return fseek($this->handle, $offset, $whence) === 0;
        }
        $base = $whence === SEEK_CUR ? $this->position : ($whence === SEEK_END ? strlen($this->buffer) : 0);
        $this->position = $base + $offset;

        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat()
    {
        if ($this->buffer !== null) {
            $stat = self::native(fn () => stat(self::$target));
            if (is_array($stat)) {
                $stat['size'] = $stat[7] = strlen($this->buffer);
            }

            return $stat;
        }

        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function stream_flush(): bool
    {
        return $this->buffer !== null ? true : fflush($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        return $this->buffer !== null ? true : flock($this->handle, $operation);
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /**
     * @return resource|false
     */
    public function stream_cast(int $as)
    {
        return $this->buffer !== null ? false : $this->handle;
    }

    /**
     * @param mixed $value
     */
    public function stream_metadata(string $path, int $option, $value): bool
    {
        return self::native(function () use ($path, $option, $value) {
            switch ($option) {
                case STREAM_META_TOUCH:
                    return touch($path, ...(array) $value);
                case STREAM_META_OWNER_NAME:
                case STREAM_META_OWNER:
                    return chown($path, $value);
                case STREAM_META_GROUP_NAME:
                case STREAM_META_GROUP:
                    return chgrp($path, $value);
                case STREAM_META_ACCESS:
                    return chmod($path, $value);
            }

            return false;
        });
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags)
    {
        return self::native(function () use ($path, $flags) {
            $link = (bool) ($flags & STREAM_URL_STAT_LINK);
            if ($flags & STREAM_URL_STAT_QUIET) {
                return $link ? @lstat($path) : @stat($path);
            }

            return $link ? lstat($path) : stat($path);
        });
    }

    public function unlink(string $path): bool
    {
        return self::native(fn () => unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(fn () => rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::native(fn () => mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE)));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(fn () => rmdir($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $this->dir = self::native(fn () => opendir($path));

        return $this->dir !== false;
    }

    /**
     * @return string|false
     */
    public function dir_readdir()
    {
        return readdir($this->dir);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->dir);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->dir);

        return true;
    }
}
