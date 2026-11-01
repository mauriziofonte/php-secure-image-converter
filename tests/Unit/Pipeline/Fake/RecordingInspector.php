<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Tests\Unit\Pipeline\Fake;

use Mfonte\SecureImageConverter\Validation\ImageInfo;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use Mfonte\SecureImageConverter\Validation\ImageInspectorInterface;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * An inspector that records the paths it is given and the bytes it read there, and can replace the result: the real
 * ImageInspector does the work unless an override is set.
 */
final class RecordingInspector implements ImageInspectorInterface
{
    /**
     * @var ImageInspector
     */
    private $inspector;

    /**
     * @var (callable(ImageInfo): ImageInfo)|null
     */
    private $override;

    /**
     * @var list<array{0: string, 1: string}>
     */
    public $inspected = [];

    /**
     * @param (callable(ImageInfo): ImageInfo)|null $override changes what the real inspector found
     */
    public function __construct(ImageInspector $inspector, ?callable $override = null)
    {
        $this->inspector = $inspector;
        $this->override = $override;
    }

    public function getLimits(): Limits
    {
        return $this->inspector->getLimits();
    }

    public function getInputFormats(): array
    {
        return $this->inspector->getInputFormats();
    }

    public function inspect(string $path): ImageInfo
    {
        $this->inspected[] = [$path, (string) file_get_contents($path)];
        $info = $this->inspector->inspect($path);

        return $this->override !== null ? ($this->override)($info) : $info;
    }

    public function inspectStream($stream): ImageInfo
    {
        return $this->inspector->inspectStream($stream);
    }
}
