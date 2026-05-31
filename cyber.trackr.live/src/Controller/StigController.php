<?php

namespace App\Controller;

use App\Service\BulkDownloadIndex;
use App\Service\BulkDownloadPlanner;
use App\Service\StigCompanionZipFinder;
use App\Service\StigDigestBuilder;
use App\Service\StigTocBuilder;
use App\Service\Stig\CklbSkeletonBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class StigController extends AbstractController
{

    #[Route('/stig', name: 'stig')]
    public function stig(StigTocBuilder $tocBuilder, BulkDownloadIndex $bulkIndex): Response
    {
        $stigs = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // Index every filename already in the toc so we only parse genuinely new files.
        $existing_files = [];
        foreach ($stigs as $instances) {
            foreach ($instances as $instance) {
                $existing_files[] = $instance['filename'];
            }
        }

        $new = 0;
        $finder = (new Finder())->files()->in($tocBuilder->stigDir())->name('*.xml');
        foreach ($finder as $file) {
            if (in_array($file->getFilename(), $existing_files, true)) {
                continue;
            }
            $parsed = $tocBuilder->parseStig($file->getRealPath());
            if ($parsed === null) {
                continue;
            }
            if (!array_key_exists($parsed['title'], $stigs)) {
                $stigs[$parsed['title']] = [];
            }
            $stigs[$parsed['title']][] = $parsed['entry'];
            $new++;
        }

        if ($new > 0) {
            $tocBuilder->writeToc($stigs);
        }

        // Roll up to one record per title (latest version), sorted by released desc.
        $stigs_latest = $tocBuilder->latestPerTitle($stigs);

        return $this->render('stig/index.html.twig', [
            'controller_name' => 'StigController',
            'stigs_latest' => $stigs_latest,
            'stig_count'   => count($stigs),
            'bulk_entries' => $bulkIndex->entries(),
        ]);
    }

    /**
     * Flat index of every STIG title with all versions inline. Plain HTML —
     * no DataTables, no pagination — so search-engine crawlers reach every
     * version in one hop instead of clicking through paginated index pages.
     * Also a useful page for humans who want a single Cmd-F surface.
     *
     * Declared with a fixed-string requirement on the path so it doesn't
     * collide with /stig/{title}/{version}/{release} (segment count differs
     * anyway, but being explicit avoids future routing drift).
     */
    #[Route('/stig/index', name: 'stig_index_titles')]
    public function stig_index_titles(StigTocBuilder $tocBuilder): Response
    {
        $stigs = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // Sort each title's versions by date desc so the latest reads first;
        // sort titles alphabetically.
        ksort($stigs, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($stigs as $title => &$entries) {
            usort($entries, fn($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));
        }
        unset($entries);

        $version_count = 0;
        foreach ($stigs as $entries) $version_count += count($entries);

        return $this->render('stig/index_all.html.twig', [
            'stigs'          => $stigs,
            'title_count'    => count($stigs),
            'version_count'  => $version_count,
        ]);
    }

    #[Route('/stig/feed.atom', name: 'stig_feed')]
    public function stig_feed(StigDigestBuilder $digestBuilder, StigTocBuilder $tocBuilder): Response
    {
        $stigs = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // Build a flat list of (title, entry) sorted by date desc — one entry
        // per STIG version, so V1R5 and V1R6 of the same title both appear.
        $versions = [];
        foreach ($stigs as $title => $entries) {
            foreach ($entries as $entry) {
                $entry['title'] = $title;
                $versions[] = $entry;
            }
        }
        usort($versions, fn($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));

        // Walk the date-sorted list collecting entries that have a buildable
        // digest (i.e., have a previous version on the same title). Cap at 25.
        $items = [];
        foreach ($versions as $v) {
            if (count($items) >= 25) break;
            $titleEntries = $stigs[$v['title']] ?? [];
            $digest = $digestBuilder->buildOrLoad($tocBuilder->stigDir(), $v, $titleEntries);
            if ($digest === null) continue;
            $items[] = ['entry' => $v, 'digest' => $digest];
        }

        $latestUpdated = $items[0]['entry']['date'] ?? date('c');
        $body = $this->renderView('stig/feed.atom.twig', [
            'items'          => $items,
            'latest_updated' => $latestUpdated,
        ]);

        $resp = new Response($body, 200);
        $resp->headers->set('Content-Type', 'application/atom+xml; charset=utf-8');
        if ($latestUpdated) {
            $resp->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($latestUpdated)) . ' GMT');
        }
        return $resp;
    }

    /**
     * Bulk-download landing page — single virtual-scrolled DataTable with
     * a checkbox per (STIG version, file kind). Lets a user grab every
     * historical STIG/SCAP they care about in one go, split across
     * ≤500MB ZIP chunks streamed sequentially by the client.
     */
    #[Route('/stig/bulk', name: 'stig_bulk')]
    public function stig_bulk(BulkDownloadIndex $index): Response
    {
        return $this->render('stig/bulk.html.twig', [
            'entries' => $index->entries(),
        ]);
    }

    /**
     * POST {selections: [{kind:"xml"|"zip", id:"..."}, ...]}
     * → JSON {chunks: [{index, of, files, bytes}, ...], total_files, total_bytes}.
     * Stateless — the client re-sends each chunk's file list when it
     * actually wants the ZIP bytes.
     */
    #[Route('/stig/bulk-download/plan', name: 'stig_bulk_plan', methods: ['POST'])]
    public function stig_bulk_plan(Request $request, BulkDownloadPlanner $planner): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $selections = is_array($payload['selections'] ?? null) ? $payload['selections'] : [];
        $resolved = $planner->resolve($selections);
        $plan = $planner->plan($resolved);
        $plan['chunk_limit'] = BulkDownloadPlanner::CHUNK_LIMIT_BYTES;
        return new JsonResponse($plan);
    }

    /**
     * POST {files: [{kind, id, name, size}, ...], index, of}
     * → streamed ZIP containing those files. The client constructs each
     * request from a chunk returned by /stig/bulk-download/plan.
     */
    #[Route('/stig/bulk-download/chunk', name: 'stig_bulk_chunk', methods: ['POST'])]
    public function stig_bulk_chunk(Request $request, BulkDownloadPlanner $planner): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
        $index = (int)($payload['index'] ?? 1);
        $of    = (int)($payload['of'] ?? 1);
        if ($index < 1) $index = 1;
        if ($of < 1)    $of = 1;

        $resolved = $planner->resolve($files);
        if (empty($resolved)) {
            throw $this->createNotFoundException('No valid files in chunk.');
        }

        $stamp = date('Ymd-His');
        $zipName = sprintf('cyber-trackr-bulk-%s-part%d-of-%d.zip', $stamp, $index, $of);
        return $planner->streamChunk($resolved, $zipName);
    }

    #[Route('/stig/{title}/{v1}/{r1}/{v2}/{r2}', name: 'stig_compare')]
    public function stig_compare($title, $v1, $r1, $v2, $r2): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $stigs = (array)json_decode(file_get_contents($toc_path));

        $stig1 = array_filter($stigs[$title], function ($obj) use ($v1, $r1) {
            if ($obj->version == $v1  && $obj->release == $r1) {
                return true;
            }
            return false;
        });
        $stig1 = array_pop($stig1);
        $stig1_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stig1->filename);
        if (file_exists($stig1_filename)) {
            $stig1_xml = simplexml_load_file($stig1_filename);
        }

        $stig2 = array_filter($stigs[$title], function ($obj) use ($v2, $r2) {
            if ($obj->version == $v2  && $obj->release == $r2) {
                return true;
            }
            return false;
        });
        $stig2 = array_pop($stig2);
        $stig2_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stig2->filename);
        if (file_exists($stig2_filename)) {
            $stig2_xml = simplexml_load_file($stig2_filename);
        }

        if ($stig1_xml && $stig2_xml) {
            foreach ($stig1_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
                if (strlen((string) $strPrefix) == 0) {
                    $strPrefix = "a"; //Assign an arbitrary namespace prefix.
                }
                $stig1_xml->registerXPathNamespace($strPrefix, $strNamespace);
            }
            $stig1_xml->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");

            foreach ($stig2_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
                if (strlen((string) $strPrefix) == 0) {
                    $strPrefix = "a"; //Assign an arbitrary namespace prefix.
                }
                $stig2_xml->registerXPathNamespace($strPrefix, $strNamespace);
            }
            $stig2_xml->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");
        }

        return $this->render('stig/compare.html.twig', [
            'controller_name' => 'StigController',
            'stig1' => $stig1_xml,
            'stig2' => $stig2_xml,
            'stigs' => $stigs[$title],
            'title' => $title,
            'v1' => $v1,
            'r1' => $r1,
            'v2' => $v2,
            'r2' => $r2,
        ]);
    }

    #[Route('/stig/{title}/{version}/{release}', name: 'stig_view')]
    public function stig_view($title, $version, $release, StigDigestBuilder $digestBuilder, StigTocBuilder $tocBuilder, StigCompanionZipFinder $companionFinder, \App\Service\Stig\StigWizardRegistry $wizardRegistry): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $stigs = (array)json_decode(file_get_contents($toc_path));

        $stig = array_filter($stigs[$title], function ($obj) use ($version, $release) {
            if ($obj->version == $version  && $obj->release == $release) {
                return true;
            }
            return false;
        });
        $stig = array_pop($stig);
        $stig_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stig->filename);
        if (file_exists($stig_filename)) {
            $stig_xml = simplexml_load_file($stig_filename);
        }

        foreach ($stig_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $stig_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $stig_xml->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");

        $cci_filename = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        if (file_exists($cci_filename)) {
            $cci_xml = simplexml_load_file($cci_filename);
        }

        foreach ($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $cci_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");

        // Resolve previous version + build/load the digest. Returns null if
        // there's no previous version on this title — the template
        // conditionally renders the section only when digest is present.
        $digest = $digestBuilder->buildOrLoad(
            $tocBuilder->stigDir(),
            (array) $stig,
            $stigs[$title]
        );

        $companion = $companionFinder->find($title, $version, $release);

        $wizard = $wizardRegistry->findForStig($title, $version);

        return $this->render('stig/view.html.twig', [
            'controller_name' => 'StigController',
            'cci' => $cci_xml,
            'stig' => $stig_xml,
            'stigs' => $stigs[$title],
            'title' => $title,
            'version' => $version,
            'release' => $release,
            'digest' => $digest,
            'companion' => $companion,
            'wizard_key' => $wizard['key'] ?? null,
        ]);
    }

    #[Route('/stig/{title}/{version}/{release}/companion.zip', name: 'stig_companion_zip')]
    public function stig_companion_zip(string $title, string $version, string $release, StigCompanionZipFinder $companionFinder): Response
    {
        $path = $companionFinder->resolveZipPath($title, $version, $release);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException('Companion ZIP not found.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($path)
        );
        return $response;
    }

    #[Route('/stig/{title}/{version}/{release}/companion.pdf', name: 'stig_companion_pdf')]
    public function stig_companion_pdf(string $title, string $version, string $release, Request $request, StigCompanionZipFinder $companionFinder): Response
    {
        $entry = (string) $request->query->get('file', '');
        $pdf = $companionFinder->readPdfEntry($title, $version, $release, $entry);
        if ($pdf === null) {
            throw $this->createNotFoundException('Companion PDF not found.');
        }

        $response = new Response($pdf['bytes']);
        $response->headers->set('Content-Type', 'application/pdf');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $pdf['basename']
        );
        $response->headers->set('Content-Disposition', $disposition);
        return $response;
    }

    /**
     * Blank CKLB skeleton for a STIG version — every rule Not_Reviewed.
     * Server-side half of the STIG wizard (issue #15): built from PUBLIC STIG
     * content only. The wizard JS fetches this, applies the user's answers
     * client-side, and hands the result to /ckl-viewer. Also a valid .cklb on
     * its own for DISA STIG Viewer 3.x.
     */
    #[Route('/stig/{title}/{version}/{release}/skeleton.cklb', name: 'stig_skeleton_cklb')]
    public function stig_skeleton_cklb($title, $version, $release, CklbSkeletonBuilder $builder): Response
    {
        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $stigs = (array) json_decode(file_get_contents($toc_path));

        if (!isset($stigs[$title])) {
            throw $this->createNotFoundException('Unknown STIG.');
        }
        $stig = array_filter($stigs[$title], fn($o) => $o->version == $version && $o->release == $release);
        $stig = array_pop($stig);
        if (!$stig) {
            throw $this->createNotFoundException('Unknown STIG version/release.');
        }
        $stig_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stig->filename);
        if (!$stig_filename || !is_file($stig_filename)) {
            throw $this->createNotFoundException('STIG file missing.');
        }

        $cklb = $builder->build($stig_filename, ['version' => $version, 'release' => $release]);

        $stem = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title)), '-') ?: 'stig';
        $filename = sprintf('%s-v%sr%s-skeleton.cklb', $stem, $version, $release);

        return new JsonResponse($cklb, 200, [
            'Content-Disposition'     => 'inline; filename="' . $filename . '"',
            'X-Content-Type-Options'  => 'nosniff',
        ]);
    }

    #[Route('/stig/{title}/{version}/{release}/download', name: 'stig_download')]
    public function stig_download($title, $version, $release): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $stigs = (array)json_decode(file_get_contents($toc_path));

        $stig = array_filter($stigs[$title], function ($obj) use ($version, $release) {
            if ($obj->version == $version  && $obj->release == $release) {
                return true;
            }
            return false;
        });

        $stig = array_pop($stig);
        $stig_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stig->filename);

        if (file_exists($stig_filename)) {
            $response = new BinaryFileResponse($stig_filename);
            $response->headers->set('Content-Type', 'text/xml');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($stig_filename)
            );
        } else {
            throw $this->createNotFoundException('The CKL does not exist');
        }

        return $response;
    }

    #[Route('/stig/disa', name: 'stig_disa')]
    public function stig_disa(): Response
    {
        return $this->render('stig/download.html.twig', [
            'controller_name' => 'StigController',
        ]);
    }

    /**
     * Admin-triggered DISA mirror — same engine as
     * `app:disa:sync-stig` (run nightly via bin/refresh-data.sh).
     * Streams progress to the browser so the operator can watch the
     * sync proceed.
     */
    #[Route('/stig/disa_download', name: 'stig_disa_download')]
    public function stig_disa_download(\App\Service\DisaCatalogSyncer $syncer): Response
    {
        set_time_limit(60 * 15);
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($syncer) {
            echo "<pre style='color:rgb(236, 226, 200);'>\n";
            @ob_flush(); flush();
            try {
                $stats = $syncer->sync(
                    \App\Service\DisaCatalogSyncer::KIND_STIG,
                    function (string $line) { echo $line . "\n"; @ob_flush(); flush(); },
                );
                printf("\nDone. downloaded=%d skipped=%d xml=%d errors=%d\n",
                    $stats['downloaded'], $stats['skipped'], $stats['xml_extracted'], $stats['errors']);
            } catch (\Throwable $e) {
                echo "\nERROR: " . htmlspecialchars($e->getMessage()) . "\n";
            }
            echo "</pre>";
        });
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }
}
