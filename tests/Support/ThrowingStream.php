<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** A lazily streamed body whose transfer fails when it is read, as a streaming PSR-18 client can. */
final class ThrowingStream implements StreamInterface
{
    public function __construct(private string $message = 'transfer failed for https://datadis.test/x?cups=ES0031300000000001JN0F&authorizedNif=87654321X') {}

    public function __toString(): string
    {
        throw new RuntimeException($this->message);
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        throw new RuntimeException($this->message);
    }

    public function getContents(): string
    {
        throw new RuntimeException($this->message);
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
