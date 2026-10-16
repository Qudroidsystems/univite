<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The university's own details (name, address, contacts, officers, logo ...), stored as "institution.*" settings and
 * edited under Academic Setup → Institution Profile. Use Institution::get('name') or the $institution view variable.
 */
class Institution
{
    /** key => [group, label, type (text|textarea|email|url|color|year|image), help] */
    public const FIELDS = [
        'name' => ['Identity', 'Full name of the institution', 'text', 'Shown everywhere: page titles, the menu, slips, receipts and transcripts.'],
        'short_name' => ['Identity', 'Short name or acronym', 'text', 'Used where space is tight, for example AFUED.'],
        'motto' => ['Identity', 'Motto', 'text', ''],
        'kind' => ['Identity', 'Type of institution', 'text', 'For example Federal University, State University, Private University, College of Education.'],
        'established' => ['Identity', 'Year established', 'year', ''],
        'regulator_code' => ['Identity', 'Regulator / institution code', 'text', 'For example the NUC, JAMB or NCCE institution code.'],
        'about' => ['Identity', 'Short description', 'textarea', 'Shown on the public welcome and application pages.'],

        'address' => ['Address', 'Street address', 'textarea', ''],
        'city' => ['Address', 'Town / city', 'text', ''],
        'state' => ['Address', 'State / region', 'text', ''],
        'country' => ['Address', 'Country', 'text', ''],
        'postal_code' => ['Address', 'Postal code / P.M.B.', 'text', 'For example P.M.B. 520.'],
        'home_nationality' => ['Address', 'Home nationality for fees', 'text', 'Students of this nationality pay the home fees; everyone else pays foreign fees. For example Nigerian.'],

        'phone' => ['Contact', 'Phone number', 'text', ''],
        'phone_alt' => ['Contact', 'Other phone number', 'text', ''],
        'email' => ['Contact', 'General email', 'email', ''],
        'support_email' => ['Contact', 'Portal support email', 'email', 'Where students and applicants should write when the portal gives them trouble.'],
        'website' => ['Contact', 'Website', 'url', ''],
        'facebook' => ['Contact', 'Facebook page', 'url', ''],
        'twitter' => ['Contact', 'X (Twitter) page', 'url', ''],
        'instagram' => ['Contact', 'Instagram page', 'url', ''],

        'head_title' => ['Principal officers', 'Title of the head of the institution', 'text', 'Vice-Chancellor, Provost, Rector ...'],
        'head_name' => ['Principal officers', 'Name of the head of the institution', 'text', ''],
        'registrar_name' => ['Principal officers', 'Registrar', 'text', 'Printed under the signature on transcripts and slips.'],
        'bursar_name' => ['Principal officers', 'Bursar', 'text', 'Printed on payment receipts.'],
        'librarian_name' => ['Principal officers', 'University Librarian', 'text', ''],

        'logo' => ['Branding', 'Logo', 'image', 'PNG or JPG, ideally square, up to 2 MB. Shown in the menu, sign-in page and on every printed document.'],
        'favicon' => ['Branding', 'Browser tab icon', 'image', 'A small square image. The logo is used when this is empty.'],
        'registrar_signature' => ['Branding', 'Registrar\'s signature', 'image', 'A scanned signature on a white or transparent background, printed on transcripts.'],
        'stamp' => ['Branding', 'Official stamp', 'image', 'Printed on transcripts and clearance slips.'],
        'primary_color' => ['Branding', 'Main colour', 'color', 'Buttons, links and highlights across the portal.'],
        'tagline' => ['Branding', 'Portal tagline', 'text', 'The line under the name on the sign-in page, for example "Student and staff portal".'],
        'footer_text' => ['Branding', 'Footer text', 'text', 'Shown at the bottom of every page after the copyright line.'],
    ];

    public const DEFAULTS = ['country' => 'Nigeria', 'home_nationality' => 'Nigerian', 'head_title' => 'Vice-Chancellor', 'tagline' => 'University portal', 'primary_color' => '#405189'];

    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = Setting::where('key', 'like', 'institution.%')->pluck('value', 'key');
            self::$cache = [];
            foreach (array_keys(self::FIELDS) as $k) {
                self::$cache[$k] = $stored["institution.{$k}"] ?? (self::DEFAULTS[$k] ?? null);
            }
            self::$cache['name'] = self::$cache['name'] ?: config('app.name');
        }

        return self::$cache;
    }

    /** The stored values exactly as saved (no defaults), for change records. */
    public static function raw(): array
    {
        $stored = Setting::where('key', 'like', 'institution.%')->pluck('value', 'key');

        return collect(array_keys(self::FIELDS))->mapWithKeys(fn ($k) => [$k => ($stored["institution.{$k}"] ?? null) ?: null])->all();
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return (self::all()[$key] ?? null) ?: $default;
    }

    public static function put(string $key, ?string $value): void
    {
        Setting::put("institution.{$key}", $value);
        self::flush();
    }

    /** Public URL of an uploaded image (logo, favicon, signature, stamp), or null. */
    public static function image(string $key): ?string
    {
        $path = self::get($key);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The top of a spreadsheet: logo, name, address, contacts, then the document's own title lines.
     *
     * @param  string[]  $title
     * @return array{lines: array<int, array{0: string, 1: string}>, logo: ?string, logo_ext: ?string}
     */
    public static function letterhead(array $title = []): array
    {
        $contacts = collect([self::get('phone'), self::get('email'), self::get('website')])->filter()->implode('  ·  ');
        $lines = [[(string) self::get('name'), 'name']];
        foreach ([self::get('motto'), self::addressLine(), $contacts] as $l) {
            if ($l) {
                $lines[] = [$l, 'line'];
            }
        }
        foreach ($title as $t) {
            if ($t) {
                $lines[] = [$t, 'title'];
            }
        }
        $path = self::get('logo');
        $disk = Storage::disk('public');
        $logo = $path && $disk->exists($path) ? $disk->get($path) : null;

        return ['lines' => $lines, 'logo' => $logo, 'logo_ext' => $logo ? pathinfo($path, PATHINFO_EXTENSION) : null];
    }

    public static function initials(): string
    {
        $short = self::get('short_name');

        return mb_strtoupper(mb_substr($short ?: self::get('name', 'U'), 0, 1));
    }

    /** One-line postal address. */
    public static function addressLine(): string
    {
        return collect([self::get('address'), self::get('postal_code'), self::get('city'), self::get('state'), self::get('country')])
            ->filter()->map(fn ($v) => trim(preg_replace('/\s+/', ' ', $v)))->implode(', ');
    }

    /** "r, g, b" of the main colour, for Bootstrap's --vz-primary-rgb. */
    public static function primaryRgb(): string
    {
        $hex = ltrim(self::get('primary_color', '#405189'), '#');

        return strlen($hex) === 6 ? implode(', ', array_map('hexdec', str_split($hex, 2))) : '64, 81, 137';
    }
}
