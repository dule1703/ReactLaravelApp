<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Real image bytes without the GD extension (the fake image helper needs GD), as real
 * UploadedFile objects: their MIME type comes from the CONTENT, like in production, not from
 * the name the "client" gives them.
 */
trait MakesImages
{
    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /**
     * A valid PNG (gray, 8 bit).
     */
    protected function pngBytes(int $width = 800, int $height = 500): string
    {
        $rows = str_repeat("\0".str_repeat("\0", $width), $height);

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$this->chunk('IDAT', gzcompress($rows))
            .$this->chunk('IEND', '');
    }

    /**
     * Header-only PNG that claims huge dimensions (enough for getimagesize and the MIME check).
     */
    protected function pngHeaderOnly(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$this->chunk('IEND', '');
    }

    /**
     * Minimal JPEG: SOI, JFIF, SOF0 with the given size, EOI.
     */
    protected function jpegBytes(int $width = 800, int $height = 500): string
    {
        return "\xFF\xD8"
            ."\xFF\xE0".pack('n', 16)."JFIF\0".pack('CC', 1, 1).pack('C', 0).pack('nn', 1, 1).pack('CC', 0, 0)
            ."\xFF\xC0".pack('n', 17).pack('C', 8).pack('nn', $height, $width).pack('C', 3)."\x01\x11\x00\x02\x11\x00\x03\x11\x00"
            ."\xFF\xD9";
    }

    /**
     * Minimal extended WEBP (VP8X) with the given canvas size.
     */
    protected function webpBytes(int $width = 800, int $height = 500): string
    {
        $canvas = pack('V', $width - 1);
        $canvasHeight = pack('V', $height - 1);
        $payload = "\0\0\0\0".substr($canvas, 0, 3).substr($canvasHeight, 0, 3);

        return 'RIFF'.pack('V', 4 + 8 + strlen($payload)).'WEBP'.'VP8X'.pack('V', strlen($payload)).$payload;
    }

    protected function svgBytes(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="800" height="500"><rect width="800" height="500"/></svg>';
    }

    /**
     * A real uploaded file with the given bytes and the (untrusted) client file name.
     */
    protected function upload(string $clientName, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
