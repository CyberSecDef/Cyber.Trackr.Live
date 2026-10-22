<?php

namespace App\Tests\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

/**
 * Review P16: versioned /js and /css URLs are cached for a year as immutable,
 * so every first-party reference must carry a version that changes with the
 * file. Unversioned, they got DreamHost's 30-day max-age.
 */
class AssetVersionTest extends TestCase
{
    private const PUBLIC = __DIR__ . '/../../public';

    public function testAssetVAppendsTheFileMtime(): void
    {
        $ext = new AppExtension();
        $this->assertSame('/js/app.js?v=' . filemtime(self::PUBLIC . '/js/app.js'), $ext->assetV('/js/app.js'));
        $this->assertSame('/js/no-such-file.js', $ext->assetV('/js/no-such-file.js'), 'missing file: unversioned');
    }

    /** Every <script src> / <link href> to /js or /css goes through asset_v(). */
    public function testTemplatesVersionEveryFirstPartyAsset(): void
    {
        $unversioned = [];
        $versioned = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates'));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $twig = (string) file_get_contents($file->getPathname());
            preg_match_all('#(?:src|href)="(/(?:js|css)/[^"]+)"#', $twig, $m);
            foreach ($m[1] as $url) {
                $unversioned[] = basename($file->getPathname()) . ': ' . $url;
            }
            $versioned += preg_match_all("#asset_v\\('/(?:js|css)/[^']+'\\)#", $twig, $v);
            foreach ($v[0] as $call) {
                preg_match("#'([^']+)'#", $call, $p);
                $this->assertFileExists(self::PUBLIC . $p[1], "$call points at a real file");
            }
        }
        $this->assertSame([], $unversioned, 'reference these through asset_v()');
        $this->assertGreaterThan(30, $versioned);
    }

    /** The server configs that act on ?v= and compress JSON, on Apache (prod) and nginx (Docker). */
    public function testServerConfigsCacheVersionedAssetsAndGzipJson(): void
    {
        $htaccess = (string) file_get_contents(self::PUBLIC . '/.htaccess');
        $this->assertStringContainsString('<If "%{QUERY_STRING} =~ /(^|&)v=/ && %{REQUEST_URI} =~ m#^/(js|css)/#">', $htaccess);
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=31536000, immutable"', $htaccess);
        $this->assertMatchesRegularExpression('#AddOutputFilterByType DEFLATE [^\n]*application/json#', $htaccess);
        // AddOutputFilterByType in .htaccess replaces the server's list, so the
        // usual types must be repeated or pages lose compression.
        foreach (['text/html', 'text/css', 'text/javascript', 'application/javascript', 'application/xml'] as $type) {
            $this->assertMatchesRegularExpression('#AddOutputFilterByType DEFLATE [^\n]*' . preg_quote($type, '#') . '[ \n]#', $htaccess, $type);
        }

        $nginx = (string) file_get_contents(__DIR__ . '/../../docker/nginx.conf');
        $this->assertMatchesRegularExpression('#gzip_types [^;]*application/json#', $nginx);
        $this->assertStringContainsString('"public, max-age=31536000, immutable"', $nginx);
        $this->assertStringContainsString('add_header Cache-Control $asset_cache_control;', $nginx);
    }
}
