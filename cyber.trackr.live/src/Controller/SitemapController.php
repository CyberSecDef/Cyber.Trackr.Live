<?php
namespace App\Controller;

use App\Service\Plans\PlanRegistry;
use App\Service\StigTocBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dynamic /sitemap.xml — walks the STIG and SCAP tocs every request so the
 * sitemap stays current without any rebuild step. Covers every page Google
 * could plausibly want to index: the static surfaces, every STIG and SCAP
 * version, every plan-generator family, plus the catalog and reference
 * pages.
 *
 * Replaces the old /public/sitemap.xml static file (which captured a
 * point-in-time snapshot from 2023 and missed every STIG version added
 * since, plus the entire post-2023 feature set).
 */
class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(Request $request, StigTocBuilder $tocBuilder, PlanRegistry $registry): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        $stigToc = $this->loadToc($tocBuilder->tocPath());
        $scapTocPath = realpath(__DIR__ . '/../../resources/data/scap_toc.json');
        $scapToc = $scapTocPath ? $this->loadToc($scapTocPath) : [];

        // Latest entry date across each side, used as lastmod for index pages.
        $stigLatest = $this->latestDate($stigToc);
        $scapLatest = $this->latestDate($scapToc);
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $urls = [];

        // ---- Static surfaces -----------------------------------------------
        $urls[] = $this->urlEntry($base . '/',                $today,       'weekly',  '1.0');
        $urls[] = $this->urlEntry($base . '/stig',             $stigLatest,  'weekly',  '0.9');
        $urls[] = $this->urlEntry($base . '/stig/index',       $stigLatest,  'weekly',  '0.9');
        $urls[] = $this->urlEntry($base . '/scap',             $scapLatest,  'weekly',  '0.8');
        $urls[] = $this->urlEntry($base . '/cci',              null,         'monthly', '0.8');
        $urls[] = $this->urlEntry($base . '/rmf/4',            null,         'yearly',  '0.7');
        $urls[] = $this->urlEntry($base . '/rmf/5',            null,         'monthly', '0.9');
        $urls[] = $this->urlEntry($base . '/rmf/4-to-5',       null,         'monthly', '0.8');
        $urls[] = $this->urlEntry($base . '/baselines',        null,         'monthly', '0.8');
        $urls[] = $this->urlEntry($base . '/plans',            $today,       'weekly',  '0.9');
        $urls[] = $this->urlEntry($base . '/ckl-viewer',       $today,       'monthly', '0.8');
        $urls[] = $this->urlEntry($base . '/report_generator', $today,       'monthly', '0.7');
        $urls[] = $this->urlEntry($base . '/search',           null,         'monthly', '0.5');
        $urls[] = $this->urlEntry($base . '/api',              null,         'monthly', '0.6');
        $urls[] = $this->urlEntry($base . '/mission',          null,         'yearly',  '0.5');
        $urls[] = $this->urlEntry($base . '/contactus',        null,         'yearly',  '0.5');
        $urls[] = $this->urlEntry($base . '/changelog',        $today,       'daily',   '0.6');
        $urls[] = $this->urlEntry($base . '/stig/feed.atom',   $stigLatest,  'daily',   '0.7');

        // ---- Plan-generator family wizards (20 of them) --------------------
        foreach ($registry->availablePlans() as $code => $_) {
            $urls[] = $this->urlEntry($base . '/plans/' . $code, $today, 'monthly', '0.7');
        }

        // ---- Every STIG version -------------------------------------------
        // Iterate the toc in date-desc order so the freshest entries appear
        // earlier in the sitemap (a hint to crawlers about prioritization).
        foreach ($stigToc as $title => $entries) {
            $sortable = is_array($entries) ? $entries : [];
            usort($sortable, fn($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));
            foreach ($sortable as $e) {
                if (empty($e['version']) || empty($e['release'])) continue;
                $url = $base . '/stig/' . rawurlencode((string) $title)
                       . '/' . rawurlencode((string) $e['version'])
                       . '/' . rawurlencode((string) $e['release']);
                $urls[] = $this->urlEntry(
                    $url,
                    $this->normalizeDate($e['date'] ?? null),
                    'monthly',
                    '0.6'
                );
            }
        }

        // ---- Every SCAP version -------------------------------------------
        foreach ($scapToc as $title => $entries) {
            $sortable = is_array($entries) ? $entries : [];
            usort($sortable, fn($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));
            foreach ($sortable as $e) {
                if (empty($e['version']) || empty($e['release'])) continue;
                $url = $base . '/scap/' . rawurlencode((string) $title)
                       . '/' . rawurlencode((string) $e['version'])
                       . '/' . rawurlencode((string) $e['release']);
                $urls[] = $this->urlEntry(
                    $url,
                    $this->normalizeDate($e['date'] ?? null),
                    'monthly',
                    '0.5'
                );
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= implode("\n", $urls);
        $xml .= "\n</urlset>\n";

        $resp = new Response($xml, 200);
        $resp->headers->set('Content-Type', 'application/xml; charset=utf-8');
        return $resp;
    }

    /** @return array<string, mixed> */
    private function loadToc(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if (!is_string($raw)) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Latest 'date' across all toc entries, formatted as YYYY-MM-DD. Used as
     * lastmod for the catalog index pages so crawlers know they are fresh
     * whenever any STIG / SCAP gets bumped.
     */
    private function latestDate(array $toc): ?string
    {
        $best = null;
        foreach ($toc as $entries) {
            if (!is_array($entries)) continue;
            foreach ($entries as $e) {
                $d = $this->normalizeDate($e['date'] ?? null);
                if ($d !== null && ($best === null || $d > $best)) $best = $d;
            }
        }
        return $best;
    }

    private function normalizeDate(?string $raw): ?string
    {
        if (!$raw) return null;
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function urlEntry(string $url, ?string $lastmod, string $changefreq, string $priority): string
    {
        $loc = htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $parts = ['  <url>', "    <loc>{$loc}</loc>"];
        if ($lastmod) $parts[] = "    <lastmod>{$lastmod}</lastmod>";
        $parts[] = "    <changefreq>{$changefreq}</changefreq>";
        $parts[] = "    <priority>{$priority}</priority>";
        $parts[] = '  </url>';
        return implode("\n", $parts);
    }
}
