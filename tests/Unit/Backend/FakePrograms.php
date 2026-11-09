<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Backend;

use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Isolation\WorkingDirectory;

/**
 * A directory of empty executable files named like the backend programs, for runners that start nothing.
 */
trait FakePrograms
{
    /**
     * @var WorkingDirectory|null
     */
    private $programs;

    /**
     * @param list<string> $names
     */
    private function fakePrograms(array $names = ['vips', 'cwebp', 'gif2webp', 'avifenc', 'php']): string
    {
        if ($this->programs === null) {
            $this->programs = WorkingDirectory::create();
        }
        foreach ($names as $name) {
            $path = $this->programs->getPath() . '/' . $name;
            if (!file_exists($path)) {
                touch($path);
                chmod($path, 0700);
            }
        }

        return $this->programs->getPath();
    }

    private function programOptions(): ProcessOptions
    {
        return (new ProcessOptions())->withSearchPath([$this->fakePrograms()]);
    }

    private function removeFakePrograms(): void
    {
        if ($this->programs !== null) {
            $this->programs->remove();
            $this->programs = null;
        }
    }
}
