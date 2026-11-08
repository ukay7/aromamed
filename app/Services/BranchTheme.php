<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class BranchTheme
{
    public function current(): array
    {
        $context = app(BranchContext::class);
        $settings = $context->branchId && ! $context->aggregate ? BranchDB::table('settings')->whereIn('key', ['theme_key','theme_primary','theme_sidebar','theme_background'])->pluck('value','key')->all() : [];
        $key = $settings['theme_key'] ?? 'sage';
        $palette = config('clinic_themes.'.$key, config('clinic_themes.sage'));
        if ($key === 'custom') {
            foreach (['primary','sidebar','background'] as $color) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $settings['theme_'.$color] ?? '')) { $palette[$color] = $settings['theme_'.$color]; }
            }
        }
        return ['key'=>$key] + $palette;
    }

    public function validateColors(array $data): void
    {
        if (($data['theme_key'] ?? null) !== 'custom') { return; }
        foreach (['primary','sidebar'] as $color) {
            if ($this->contrast($data['theme_'.$color], '#ffffff') < 4.5) { throw ValidationException::withMessages(['theme_'.$color=>'Choose a darker '.$color.' color so white text stays readable.']); }
        }
        if ($this->contrast($data['theme_background'], '#293f39') < 4.5) { throw ValidationException::withMessages(['theme_background'=>'Choose a lighter page background so text stays readable.']); }
    }

    private function contrast(string $first, string $second): float
    {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function (string $channel): float {
                $value = hexdec($channel) / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));
            return $rgb[0]*0.2126 + $rgb[1]*0.7152 + $rgb[2]*0.0722;
        };
        $a=$luminance($first); $b=$luminance($second);
        return (max($a,$b)+0.05)/(min($a,$b)+0.05);
    }
}
