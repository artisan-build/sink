<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkClient\Commands\Concerns;

use JsonException;
use RuntimeException;

trait WritesSinkInstallFiles
{
    private function setEnvironmentValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$this->formatEnvironmentValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace_callback($pattern, static fn (): string => $line, $contents);
        }

        $contents = rtrim($contents, "\r\n");

        return ($contents === '' ? '' : $contents.PHP_EOL).$line.PHP_EOL;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnvFile(string $path, array $values): void
    {
        $this->replaceFile($path, function (string $contents) use ($values): string {
            foreach ($values as $key => $value) {
                $contents = $this->setEnvironmentValue($contents, $key, $value);
            }

            return $contents;
        }, 0600);
    }

    /**
     * @throws JsonException
     */
    private function pinComposerConstraint(string $path, string $package, int $major): void
    {
        $this->replaceFile($path, function (string $contents) use ($package, $major): string {
            $composer = json_decode($contents, flags: JSON_THROW_ON_ERROR);

            if (! $composer instanceof \stdClass) {
                throw new RuntimeException('The Composer target must contain an object.');
            }

            $requirements = property_exists($composer, 'require') ? $composer->require : new \stdClass;

            if (! $requirements instanceof \stdClass) {
                throw new RuntimeException('The Composer require member must contain an object.');
            }

            $requirements->{$package} = '^'.$major;
            $composer->require = $requirements;

            return json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        }, 0644);
    }

    /**
     * @param  callable(string): string  $transform
     */
    private function replaceFile(string $path, callable $transform, int $createMode): void
    {
        $lock = fopen($path.'.sink-client.lock', 'c+b');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The install target lock could not be acquired.');
        }

        try {
            $original = is_file($path) ? file_get_contents($path) : '';

            if (! is_string($original)) {
                throw new RuntimeException('An install target could not be read.');
            }

            $contents = $transform($original);

            if ($contents === $original) {
                return;
            }

            $mode = is_file($path) ? (fileperms($path) & 0777) : $createMode;
            $temporary = tempnam(dirname($path), '.sink-client-install-');

            if (! is_string($temporary)) {
                throw new RuntimeException('A same-directory temporary file could not be created.');
            }

            try {
                if (! chmod($temporary, $mode) || file_put_contents($temporary, $contents) === false) {
                    throw new RuntimeException('The install target temporary file could not be written.');
                }

                if (! rename($temporary, $path)) {
                    throw new RuntimeException('The install target could not be atomically replaced.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function formatEnvironmentValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_:\/.@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\f" => '\\f',
            "\v" => '\\v',
        ]).'"';
    }
}
