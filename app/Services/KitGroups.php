<?php

namespace App\Services;

/**
 * Which slot of the kit builder an item belongs to. A blank value means "guess from the name".
 */
class KitGroups
{
    public const ALL = ['figure', 'paint', 'brush', 'packing', 'other', 'figure_set'];

    /** Checked in order, so "Paint brush" is a brush. */
    private const KEYWORDS = [
        'brush' => '/brush/i',
        'packing' => '/pack|box|bag|wrap|sticker|card|tape|shred|polythene|cover/i',
        'paint' => '/paint/i',
        'figure' => '/figure/i',
    ];

    public static function resolve(?string $chosen, string $name, bool $costOnly = false): string
    {
        if ($chosen) {
            return $chosen;
        }

        if ($costOnly) {
            return 'other';
        }

        foreach (self::KEYWORDS as $group => $pattern) {
            if (preg_match($pattern, $name)) {
                return $group;
            }
        }

        return 'other';
    }
}
