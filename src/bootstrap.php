<?php

use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\signal;

/**
 * Turn file streams asynchronous transparently for the underlying code
 * by utilizing the event loop scheduler
 */
class AsyncFileStreamWrapper
{
    private $resource;
    private $directory;

    public static function register()
    {
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', static::class);
    }
    public static function unregister()
    {
        stream_wrapper_unregister('file');
        stream_wrapper_restore('file');
    }
    private function wrap(callable $callback, mixed ...$args)
    {
        self::unregister();
        $result = @$callback(...$args);
        self::register();

        return $result;
    }

    private function async(callable $fn, mixed ...$args): mixed
    {
        return signal(fn ($resume) => $resume($this->wrap($fn, ...$args)));
    }

    public function dir_closedir(): bool
    {
        $this->async(closedir(...), $this->directory);

        return $this->directory === false;
    }

    public function dir_opendir(string $path, int $options = null): bool
    {
        return ($this->directory = $this->async(opendir(...), $path, null)) !== false;
    }

    public function dir_readdir(): string|false
    {
        return $this->async(readdir(...), $this->directory);
    }

    public function dir_rewinddir(): bool
    {
        $this->async(rewinddir(...), $this->directory);

        return true;
    }

    public function mkdir(string $path, $mode, int $options = 0): bool
    {
        return $this->async(mkdir(...), $path, $mode, ($options & STREAM_MKDIR_RECURSIVE));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->async(rename(...), $from, $to);
    }

    public function rmdir(string $path): bool
    {
        return $this->async(rename(...), $path);
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$opened_path,
    ): bool {
        $this->resource = $this->async(fopen(...), $path, $mode);

        if (!$this->resource) {
            trigger_error("Unable to open stream {$path}", E_USER_ERROR);
        }

        if (($options & STREAM_USE_PATH) === $options) {
            $opened_path = $path;
        }

        $this->reportErrors = ($options & STREAM_REPORT_ERRORS) === $options;

        return $this->resource !== false;
    }

    public function stream_cast(int $as): mixed
    {
        return $this->resource  ?
            $this->resource : false;
    }

    public function stream_close()
    {
        $this->async(fclose(...), $this->resource);
        $this->resource = false;
    }

    public function stream_eof(): bool
    {
        return $this->async(feof(...), $this->resource);
    }

    public function stream_flush(): bool
    {
        return $this->async(fflush(...), $this->resource);
    }

    public function stream_lock(int $operation): bool
    {
        return $this->async(flock(...), $this->resource, $operation);
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return match ($option) {
            STREAM_META_TOUCH => empty($value) ? touch($path, $value[0], $value[1]) : touch($path),
            STREAM_META_OWNER => chown($path, $value),
            STREAM_META_OWNER_NAME => chown($path, $value),
            STREAM_META_GROUP => chgrp($path, $value),
            STREAM_META_ACCESS => chmod($path, $value),
            default => false,
        };
    }

    public function stream_read(int $count): string | false
    {
        return $this->async(fread(...), $this->resource, $count);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->async(fseek(...), $this->resource, $offset, $whence);
    }

    public function stream_set_option(int $option, ?int $arg1 = null, ?int $arg2 = null): bool
    {
        return match ($option) {
            STREAM_OPTION_BLOCKING => $this->async(stream_set_blocking(...), $this->resource, $arg1),
            STREAM_OPTION_READ_TIMEOUT => $this->async(stream_set_timeout(...), $this->resource, $arg1, $arg2),
            STREAM_OPTION_WRITE_BUFFER => $this->async(stream_set_write_buffer(...), $this->resource, $arg2),
            STREAM_OPTION_READ_BUFFER => $this->async(stream_set_write_buffer(...), $this->resource, $arg2),
        };
    }

    public function stream_stat(): array | false
    {
        return $this->async(fstat(...), $this->resource);
    }

    public function stream_tell(): int
    {
        return $this->async(ftell(...), $this->resource);
    }

    public function stream_truncate(int $size): bool
    {
        return $this->async(ftruncate(...), $this->resource, $size);
    }

    public function stream_write(string $data): int
    {
        return $this->async(fwrite(...), $this->resource, $data);
    }

    public function unlink(string $path): bool
    {
        return $this->async(unlink(...), $path);
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return (($flags & STREAM_URL_STAT_LINK) === $flags) ?
            $this->async(lstat(...), $path) :
            $this->async(stat(...), $path);
    }
}

AsyncFileStreamWrapper::register();
register_shutdown_function(fn () => scheduler()->start());