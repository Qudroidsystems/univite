<?php

namespace App\Data\Appearance;

/**
 * How the portal and the app look for one person: font, text size, light or dark, menu position, spacing and motion.
 * A null field means "use the school's default". Stored on users.preferences['appearance'].
 */
final readonly class AppearancePreferences
{
    public const THEMES = ['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Same as my device'];

    public const MENUS = ['side' => 'Down the side', 'top' => 'Across the top'];

    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact (more on the screen)'];

    public const LINE_SPACINGS = ['normal' => 'Normal', 'relaxed' => 'Relaxed (easier to read)'];

    public function __construct(
        public ?string $font = null,
        public ?int $size = null,
        public ?string $theme = null,
        public ?string $menu = null,
        public ?string $density = null,
        public ?string $lineSpacing = null,
        public ?bool $reduceMotion = null,
    ) {
    }

    public static function fromArray(?array $a): self
    {
        $a ??= [];
        $pick = fn (string $k, array $allowed) => isset($a[$k]) && array_key_exists($a[$k], $allowed) ? (string) $a[$k] : null;

        return new self(
            isset($a['font']) && is_string($a['font']) && $a['font'] !== '' ? $a['font'] : null,
            isset($a['size']) && is_numeric($a['size']) ? (int) $a['size'] : null,
            $pick('theme', self::THEMES),
            $pick('menu', self::MENUS),
            $pick('density', self::DENSITIES),
            $pick('line_spacing', self::LINE_SPACINGS),
            array_key_exists('reduce_motion', $a) && $a['reduce_motion'] !== null ? (bool) $a['reduce_motion'] : null,
        );
    }

    /** Fields this one leaves empty are taken from $fallback. */
    public function over(self $fallback): self
    {
        return new self(
            $this->font ?? $fallback->font, $this->size ?? $fallback->size, $this->theme ?? $fallback->theme, $this->menu ?? $fallback->menu,
            $this->density ?? $fallback->density, $this->lineSpacing ?? $fallback->lineSpacing, $this->reduceMotion ?? $fallback->reduceMotion,
        );
    }

    public function toArray(): array
    {
        return [
            'font' => $this->font, 'size' => $this->size, 'theme' => $this->theme, 'menu' => $this->menu,
            'density' => $this->density, 'line_spacing' => $this->lineSpacing, 'reduce_motion' => $this->reduceMotion,
        ];
    }
}
