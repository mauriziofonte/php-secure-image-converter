<?php

declare(strict_types=1);

namespace Mfonte\SecureImageConverter\Contract\Exception;

/**
 * The input's format is not acceptable: its content matches no format the library can identify (for example HEIC, PDF
 * or random bytes), the identified format is not enabled for input on this converter or is SVG (never rasterised, even
 * when enabled), or the input is a variant of an identified format that the library does not accept: a coding the
 * backends do not decode (12- or 16-bit, lossless or hierarchical JPEG; BMP and TIFF compression schemes outside the
 * supported set; AVIF images not coded in AV1), a structure it does not convert (AVIF grids and other derived images,
 * AVIF items stored in another file, by reference or in several extents, AVIF tracks with several sample descriptions,
 * several images in one AVIF or BMP file, BMP files with embedded JPEG or PNG data, OS/2 2.x BMP headers, BMP colour
 * profiles linked by file name), or more scans, runs, items or tracks than the validation reads; or an input that
 * no available backend decodes, such as a CMYK JPEG when only cwebp and avifenc are enabled (BackendResolver). Also
 * thrown by ImageFormat::fromMimeType() for a MIME type that is not a known image type.
 *
 * Error code ErrorCode::UNSUPPORTED_FORMAT; expected HTTP status in the API: 415 Unsupported Media Type.
 */
final class UnsupportedFormatException extends \UnexpectedValueException implements ExceptionInterface
{
    public function getErrorCode(): string
    {
        return ErrorCode::UNSUPPORTED_FORMAT;
    }
}
