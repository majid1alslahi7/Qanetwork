<?php

namespace App\Providers\MikroTik;

final class StreamRouterOsTransport implements RouterOsTransport
{
    private float $deadline;

    /** @param resource $stream */
    public function __construct(private mixed $stream, int $requestTimeout)
    {
        $this->deadline = hrtime(true) / 1e9 + $requestTimeout;
    }

    public function write(#[\SensitiveParameter] string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->setRemainingTimeout();
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $this->throwIoFailure();
            }
            $offset += $written;
        }
    }

    public function read(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->setRemainingTimeout();
            $chunk = @fread($this->stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                $this->throwIoFailure();
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    private function setRemainingTimeout(): void
    {
        if (! is_resource($this->stream)) {
            throw new RouterOsException(RouterOsFailure::DISCONNECTED);
        }
        $remaining = $this->deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RouterOsException(RouterOsFailure::TIMEOUT);
        }
        $seconds = (int) $remaining;
        $microseconds = max(1, (int) (($remaining - $seconds) * 1e6));
        if (! stream_set_timeout($this->stream, $seconds, $microseconds)) {
            throw new RouterOsException(RouterOsFailure::CONNECTION);
        }
    }

    private function throwIoFailure(): never
    {
        $metadata = stream_get_meta_data($this->stream);
        throw new RouterOsException(
            ($metadata['timed_out'] ?? false) ? RouterOsFailure::TIMEOUT : RouterOsFailure::DISCONNECTED,
        );
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __debugInfo(): array
    {
        return ['connected' => is_resource($this->stream)];
    }
}
