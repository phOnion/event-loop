<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Resources;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;

class Buffer extends Descriptor implements ResourceInterface
{
    private string $contents = '';
    private int $size = 0;
    private int $cursor = 0;

    private bool $closed = false;
    private mixed $resource = null;

    public function __construct(
        private readonly int $limit = -1
    ) {

        parent::__construct($this->resource = fopen('file://' . tempnam(sys_get_temp_dir(), 'buffer'), 'r+b'));
    }

    public function read(int $length): string|false
    {
        $current = substr($this->contents, $this->cursor, $length);
        $this->cursor += strlen($current);
        parent::read(1);

        return $current;
    }

    public function write(string $data): int|false
    {
        rewind($this->getResource());
        parent::write('.');

        $length = strlen($data);
        if ($this->limit !== -1 && $this->limit < ($this->size + $length)) {
            throw new \OverflowException('Buffer limit reached');
        }

        $this->contents .= $data;
        $this->size += $length;

        return $length;
    }

    public function seek(int $position, int $whence = SEEK_SET): void
    {
        $this->cursor = match ($whence) {
            SEEK_SET => $position,
            SEEK_CUR => $this->cursor + $position,
            SEEK_END => $this->size + ($position > 0 ? ($position * -1): $position),
        };
    }

    public function tell(): int
    {
        return $this->cursor;
    }

    public function rewind(): void
    {
        $this->cursor = 0;
    }

    // public function flush(): void
    // {
    //     $this->contents = '';
    //     $this->size = 0;
    //     $this->cursor = 0;
    // }

    // public function drain(int $cursor = null): void
    // {
    //     $this->contents = substr($this->contents, 0, $cursor ?? $this->cursor);
    //     $this->size = strlen($this->contents);
    //     $this->cursor = 0;
    // }

    public function eof(): bool
    {
        return feof($this->resource);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function __toString(): string
    {
        return $this->contents;
    }
    /**
     * Close the underlying resource
     * @return bool Whether the operation succeeded or not
     */
    public function close(): bool
    {
        return $this->closed = true && parent::close();
    }

    /**
     * Detaches the underlying resource from the current object and
     * returns it, making the current object obsolete
     */
    public function detach(): mixed
    {
        $this->closed = true;
        $this->contents = '';
        $this->size = 0;
        $this->cursor = 0;

        return parent::detach();;
    }
}
