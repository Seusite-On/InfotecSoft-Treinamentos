<?php

namespace App\Core;

class RateLimiter
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'rate_limits.json';
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $entry = $this->getEntry($key);

        if ($entry === null) {
            return false;
        }

        if (($entry['expires_at'] ?? 0) < time()) {
            $this->reset($key);
            return false;
        }

        return (int)($entry['attempts'] ?? 0) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        return $this->mutate(function (array &$data) use ($key, $decaySeconds): int {
            $now = time();
            $entry = $data[$key] ?? ['attempts' => 0, 'expires_at' => 0];

            if ((int)($entry['expires_at'] ?? 0) < $now) {
                $entry = ['attempts' => 0, 'expires_at' => 0];
            }

            $entry['attempts'] = (int)($entry['attempts'] ?? 0) + 1;
            $entry['expires_at'] = $now + $decaySeconds;
            $data[$key] = $entry;

            return (int) $entry['attempts'];
        });
    }

    public function reset(string $key): void
    {
        $this->mutate(function (array &$data) use ($key): array {
            unset($data[$key]);
            return $data;
        });
    }

    public function availableIn(string $key): int
    {
        $entry = $this->getEntry($key);

        if ($entry === null) {
            return 0;
        }

        return max(0, (int)($entry['expires_at'] ?? 0) - time());
    }

    private function getEntry(string $key): ?array
    {
        $data = $this->readData();
        return $data[$key] ?? null;
    }

    private function mutate(callable $callback): mixed
    {
        $this->ensureDirectory();

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo de rate limit.');
        }

        try {
            flock($handle, LOCK_EX);
            rewind($handle);
            $contents = stream_get_contents($handle);
            $data = json_decode($contents ?: '[]', true);
            if (!is_array($data)) {
                $data = [];
            }

            $data = $this->prune($data);
            $result = $callback($data);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readData(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $contents = file_get_contents($this->file);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $this->prune($data) : [];
    }

    private function prune(array $data): array
    {
        $now = time();

        foreach ($data as $key => $entry) {
            if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < $now) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
