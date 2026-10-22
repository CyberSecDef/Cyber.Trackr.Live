<?php
namespace App\Twig;

use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

class AppExtension
{
    /** @var array<string,string> */
    private array $versioned = [];

    /**
     * A first-party /js or /css URL with ?v=<file mtime> appended (review
     * P16). Versioned URLs are cached for a year as immutable (public/.htaccess,
     * docker/nginx.conf), so the version must change whenever the file does:
     * a deploy that edits stig_wizard.js changes its mtime and so its URL,
     * while untouched files keep theirs. Unversioned they got DreamHost's
     * 30-day max-age, so an edit could reach returning visitors a month late.
     */
    #[AsTwigFunction(name: 'asset_v')]
    public function assetV(string $path): string
    {
        if (!isset($this->versioned[$path])) {
            $mtime = @filemtime(dirname(__DIR__, 2) . '/public' . $path);
            $this->versioned[$path] = $mtime === false ? $path : $path . '?v=' . $mtime;
        }
        return $this->versioned[$path];
    }

    #[AsTwigFilter(name: 'regex_replace')]
    public function regex_replace($string, $pattern = '', $replacement = '')
    {
        return preg_replace($pattern, (string) $replacement, (string) $string);
    }

    #[AsTwigFilter(name: 'sha1')]
    public function sha1(string $value): string
    {
        return sha1($value);
    }

    /**
     * Editorial title case: capitalize each word but lowercase short
     * conjunctions / articles / prepositions (and, or, of, for, etc.).
     * Used by the plan generator since 800-53 r5 stores control titles
     * in ALL CAPS ("POLICY AND PROCEDURES") and the default `|title`
     * filter would render that as "Policy And Procedures."
     */
    #[AsTwigFilter(name: 'editorial_title')]
    public function editorialTitle(string $value): string
    {
        $s = ucwords(strtolower($value));
        $small = ['And','Or','Of','For','To','In','On','At','By','The','A','An','As','With','From'];
        return preg_replace_callback(
            '/\b(' . implode('|', $small) . ')\b/',
            fn($m) => strtolower($m[1]),
            $s
        );
    }

    /**
     * Bucket a date by age into one of four freshness tags, matching the
     * .dot CSS modifiers in app.css. Boundaries: ≤1y fresh, ≤3y stale,
     * ≤5y aged, else old. Empty/invalid dates collapse to 'old'.
     */
    #[AsTwigFilter(name: 'freshness_tag')]
    public function freshnessTag(\DateTimeInterface|string|null $date): string
    {
        $dt = $this->normalizeDate($date);
        if ($dt === null) {
            return 'old';
        }
        $days = (int) (new \DateTimeImmutable())->diff($dt)->days;
        if ($days <= 365)  return 'fresh';
        if ($days <= 1095) return 'stale';
        if ($days <= 1825) return 'aged';
        return 'old';
    }

    /**
     * Human-readable "X ago" label scaled to the magnitude of the gap.
     * <14d → days, <60d → weeks, <365d → months, <10y → 1-decimal years,
     * else integer years. Empty/invalid dates render as 'unknown'.
     */
    #[AsTwigFilter(name: 'rel_time')]
    public function relTime(\DateTimeInterface|string|null $date): string
    {
        $dt = $this->normalizeDate($date);
        if ($dt === null) {
            return 'unknown';
        }
        $days = (int) (new \DateTimeImmutable())->diff($dt)->days;
        if ($days < 14) {
            return $days . ($days === 1 ? ' day' : ' days') . ' ago';
        }
        if ($days < 60) {
            $weeks = (int) round($days / 7);
            return $weeks . ($weeks === 1 ? ' week' : ' weeks') . ' ago';
        }
        if ($days < 365) {
            $months = (int) round($days / 30);
            return $months . ($months === 1 ? ' month' : ' months') . ' ago';
        }
        $years = $days / 365.0;
        if ($years < 10) {
            return number_format($years, 1) . ' years ago';
        }
        return ((int) floor($years)) . ' years ago';
    }

    private function normalizeDate(\DateTimeInterface|string|null $date): ?\DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }
        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
