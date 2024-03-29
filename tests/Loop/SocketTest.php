<?php

namespace Tests\Loop;

use InvalidArgumentException;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Socket;
use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\Test\TestCase;

use function Onion\Framework\Loop\coroutine;

class SocketTest extends TestCase
{
    private $resource;
    private $name;

    protected function setUp(): void
    {
        $this->resource = stream_socket_server('tcp://127.0.0.1:12345');
        $this->name = stream_get_meta_data($this->resource)['uri'] ?? null;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    public function testDataTransfer()
    {
        $fp = stream_socket_client('tcp://1.1.1.1:80');
        $socket = new Socket($fp, stream_socket_get_name($fp, false));
        $socket->unblock();
        $socket->write("HTTP/1.1 GET /\r\n\r\n");
        $this->assertNotSame('', $socket->read(1024));

        $socket->close();
    }
}
