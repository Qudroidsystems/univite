<?php

namespace App\Support;

use App\Data\Appearance\AppearancePreferences;
use App\Models\User;

/**
 * Works out how the portal looks for a person: the school's defaults (AppearanceSettings) with the person's own
 * choices on top, where the school allows them. Used by the layouts, the "Display & reading" page and the mobile API.
 */
class Appearance
{
    /** key => [label, Google Fonts family (null for fonts already on the device), CSS font stack, note] */
    public const FONTS = [
        'poppins' => ['Poppins', 'Poppins:wght@300;400;500;600;700', '"Poppins", sans-serif', null],
        'inter' => ['Inter', 'Inter:wght@300;400;500;600;700', '"Inter", sans-serif', null],
        'roboto' => ['Roboto', 'Roboto:wght@300;400;500;700', '"Roboto", sans-serif', null],
        'open_sans' => ['Open Sans', 'Open+Sans:wght@300;400;500;600;700', '"Open Sans", sans-serif', null],
        'nunito' => ['Nunito', 'Nunito:wght@300;400;500;600;700', '"Nunito", sans-serif', null],
        'lato' => ['Lato', 'Lato:wght@300;400;700', '"Lato", sans-serif', null],
        'source_sans_3' => ['Source Sans 3', 'Source+Sans+3:wght@300;400;500;600;700', '"Source Sans 3", sans-serif', null],
        'public_sans' => ['Public Sans', 'Public+Sans:wght@300;400;500;600;700', '"Public Sans", sans-serif', null],
        'noto_sans' => ['Noto Sans', 'Noto+Sans:wght@300;400;500;600;700', '"Noto Sans", sans-serif', null],
        'merriweather_sans' => ['Merriweather Sans', 'Merriweather+Sans:wght@300;400;500;600;700', '"Merriweather Sans", sans-serif', null],
        'atkinson' => ['Atkinson Hyperlegible (very clear letters)', 'Atkinson+Hyperlegible:wght@400;700', '"Atkinson Hyperlegible", sans-serif', 'Designed so similar letters such as I, l and 1 are easy to tell apart.'],
        'lexend' => ['Lexend (easier reading)', 'Lexend:wght@300;400;500;600;700', '"Lexend", sans-serif', 'Wider letters that many people find easier to read.'],
        'system' => ['The device\'s own font (fastest)', null, 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', 'Nothing to download.'],
    ];

    /** Text sizes in pixels => label. The portal's text was 13px before these settings. */
    public const SIZES = [13 => '13 (small)', 14 => '14', 15 => '15 (recommended)', 16 => '16', 17 => '17 (large)', 18 => '18', 20 => '20 (extra large)', 22 => '22 (largest)'];

    /** The size every px font size in the stylesheets was written for. */
    private const DESIGN_SIZE = 13;

    public function __construct(private AppearanceSettings $settings)
    {
    }

    /** The school's defaults, every field filled. */
    public function defaults(): AppearancePreferences
    {
        $s = $this->settings;

        return new AppearancePreferences(
            $this->validFont($s->get('font')) ?? 'poppins', $this->validSize((int) $s->get('size')) ?? 15,
            $s->get('theme') ?: 'light', $s->get('menu') ?: 'side', $s->get('density') ?: 'comfortable', $s->get('line_spacing') ?: 'normal', false,
        );
    }

    /** What this person saved, as stored (empty fields mean the school default). */
    public function saved(?User $user): AppearancePreferences
    {
        return AppearancePreferences::fromArray($user?->preferences['appearance'] ?? null);
    }

    /** What this person sees: their choices over the school's defaults, minus any the school does not allow. */
    public function for(?User $user): AppearancePreferences
    {
        $mine = $this->saved($user);
        $mine = new AppearancePreferences(
            $this->usersChooseFont() ? $this->validFont($mine->font) : null,
            $this->usersChooseSize() ? $this->validSize($mine->size) : null,
            $mine->theme, $mine->menu, $mine->density, $mine->lineSpacing, $mine->reduceMotion,
        );

        return $mine->over($this->defaults());
    }

    public function save(User $user, AppearancePreferences $prefs): void
    {
        $all = $user->preferences ?? [];
        $all['appearance'] = array_filter($prefs->toArray(), fn ($v) => $v !== null);
        $user->forceFill(['preferences' => $all])->save();
    }

    public function usersChooseFont(): bool
    {
        return $this->settings->on('users_choose_font');
    }

    public function usersChooseSize(): bool
    {
        return $this->settings->on('users_choose_size');
    }

    public function headingFont(): ?string
    {
        return $this->validFont($this->settings->get('heading_font'));
    }

    public function stack(string $font): string
    {
        return (self::FONTS[$font] ?? self::FONTS['poppins'])[2];
    }

    /** The Google Fonts stylesheet for the fonts in use, or null when only device fonts are used. */
    public function fontsUrl(?string ...$fonts): ?string
    {
        $families = collect($fonts)->filter()->unique()->map(fn ($f) => self::FONTS[$f][1] ?? null)->filter()->values();

        return $families->isEmpty() ? null : 'https://fonts.googleapis.com/css2?family=' . $families->implode('&family=') . '&display=swap';
    }

    /** How much bigger than the 13px design size the text is (1.0 = as designed). */
    public function scale(int $size): float
    {
        return round($size / self::DESIGN_SIZE, 4);
    }

    /** Everything a page needs to apply the look, for the layouts. */
    public function page(?User $user): array
    {
        $p = $this->for($user);
        $heading = $this->headingFont();

        return [
            'prefs' => $p, 'fontsUrl' => $this->fontsUrl($p->font, $heading), 'stack' => $this->stack($p->font),
            'headingStack' => $heading ? $this->stack($heading) : null, 'scale' => $this->scale($p->size),
        ];
    }

    /** For the mobile app: the font name and how much to scale text (the app's own design size is 14). */
    public function forApp(?User $user): array
    {
        $p = $this->for($user);

        return $p->toArray() + [
            'font_name' => self::FONTS[$p->font][0] === self::FONTS['system'][0] ? null : explode(' (', self::FONTS[$p->font][0])[0],
            'text_scale' => round($p->size / 14, 3),
            'users_choose_font' => $this->usersChooseFont(), 'users_choose_size' => $this->usersChooseSize(),
        ];
    }

    /** Choices for the forms and the app. */
    public function options(): array
    {
        return [
            'fonts' => collect(self::FONTS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'note' => $f[3]])->values()->all(),
            'sizes' => collect(self::SIZES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()->all(),
            'themes' => AppearancePreferences::THEMES, 'menus' => AppearancePreferences::MENUS,
            'densities' => AppearancePreferences::DENSITIES, 'line_spacings' => AppearancePreferences::LINE_SPACINGS,
        ];
    }

    private function validFont(?string $font): ?string
    {
        return $font && isset(self::FONTS[$font]) ? $font : null;
    }

    private function validSize(?int $size): ?int
    {
        return $size && isset(self::SIZES[$size]) ? $size : null;
    }
}
