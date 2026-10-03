<?php

namespace App\Support;

/**
 * Validation of catalog images (car models, equipment items): jpg/jpeg/png/webp only, up to
 * 2 MB, 400x250 to 4000x4000 pixels. The `mimes` rule judges the REAL content of the file,
 * never the name or extension the client sends, and SVG is not an allowed type.
 */
final class ImageRules
{
    public const MAX_KB = 2048;

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp',
            'max:'.self::MAX_KB,
            'dimensions:min_width=400,min_height=250,max_width=4000,max_height=4000',
        ];
    }

    /**
     * @return array<string, string> messages keyed for the given field
     */
    public static function messages(string $field = 'image'): array
    {
        $tooLarge = __('The image is larger than the allowed 2 MB.');

        return [
            "$field.max" => $tooLarge,
            "$field.uploaded" => $tooLarge,
            "$field.mimes" => __('The image must be a JPG, PNG or WEBP file.'),
            "$field.dimensions" => __('The image must be from 400x250 to 4000x4000 pixels.'),
        ];
    }
}
