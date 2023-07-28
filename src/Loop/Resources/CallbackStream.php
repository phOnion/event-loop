<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Resources;
use Closure;
use Onion\Framework\Loop\Interfaces\ResourceInterface;

use function \Onion\Framework\Loop\suspend;

class CallbackStream implements ResourceInterface
{
    private bool $closed = false;
	private mixed $resource = null;

    public function __construct(
        private readonly Closure $reader,
		private readonly Closure $eof,
        private readonly Closure $writer,
        private readonly Closure $closer,
    ) {
		$this->resource = fopen('php://temp', 'r+');
	}

    public function write(string $data): int|false
    {
        return ($this->writer)($data, $this->close(...));
    }

	/**
	 * Attempt to read data from the underlying resource
	 *
	 * @param int $size Maximum amount of bytes to read
	 * @return bool|string A string containing the data read or false
	 *                     if reading failed
	 */
	public function read(int $size): false|string
    {
        return ($this->reader)($size, $this->close(...)) ?? false;
	}

	/**
	 * Close the underlying resource
	 * @return bool Whether the operation succeeded or not
	 */
	public function close(): bool
    {
        $this->closed = true;
        ($this->closer)();
		fclose($this->resource);

        return true;
	}

	/**
	 * Attempt to make operations on the underlying resource blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function block(): bool
    {
        return false;
	}

	/**
	 * Attempt to make operations on the underlying resource non-blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function unblock(): bool
    {
        return true;
	}

	public function getResource(): mixed
    {
        return null;
	}

	/**
	 * Retrieve the numeric identifier of the underlying resource
	 * @return int
	 */
	public function getResourceId(): int
    {
        return -1;
	}

	/**
	 * Check whether the resource is still alive or not
	 * @return bool
	 */
	public function eof(): bool
    {
        return ($this->eof)() || $this->closed;
	}

	/**
	 * @return bool
	 */
	public function isAlive(): bool
    {
        return !$this->eof();
	}

	/**
	 * Detaches the underlying resource from the current object and
	 * returns it, making the current object obsolete
	 * @return resource
	 */
	public function detach(): mixed
    {
	}
}