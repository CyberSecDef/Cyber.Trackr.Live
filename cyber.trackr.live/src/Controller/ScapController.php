<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ScapController extends AbstractController
{
    #[Route('/scap', name: 'scap')]
    public function scap(\App\Service\ScapTocBuilder $tocBuilder): Response
    {
        $scaps = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // Index every filename already in the toc so we only parse genuinely new files.
        $existing_files = [];
        foreach ($scaps as $instances) {
            foreach ($instances as $instance) {
                $existing_files[] = $instance['filename'];
            }
        }

        $new = 0;
        $finder = (new Finder())->files()->in($tocBuilder->scapDir())->name('*.xml');
        foreach ($finder as $file) {
            if (in_array($file->getFilename(), $existing_files, true)) {
                continue;
            }
            $parsed = $tocBuilder->parseScap($file->getRealPath());
            if ($parsed === null) {
                continue;
            }
            if (!array_key_exists($parsed['title'], $scaps)) {
                $scaps[$parsed['title']] = [];
            }
            $scaps[$parsed['title']][] = $parsed['entry'];
            $new++;
        }

        if ($new > 0) {
            $tocBuilder->writeToc($scaps);
        }

        $scaps_latest = $tocBuilder->latestPerTitle($scaps);

        return $this->render('scap/index.html.twig', [
            'controller_name' => 'ScapController',
            'scaps_latest' => $scaps_latest,
            'scap_count'   => count($scaps),
        ]);
    }

    #[Route('/scap/{title}/{version}/{release}/download', name: 'scap_download')]
    public function scap_download($title, $version, $release): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $scaps = (array)json_decode(file_get_contents($toc_path));

        $scap = array_filter($scaps[$title], function ($obj) use ($version, $release) {
            if ($obj->version == $version  && $obj->release == $release) {
                return true;
            }
            return false;
        });

        $scap = array_pop($scap);
        if ($scap === null) {
            throw $this->createNotFoundException('The SCAP does not exist');
        }
        $scap_filename = realpath(__DIR__ . "/../../resources/data/scap/" . $scap->filename);

        if (file_exists($scap_filename)) {
            $response = new BinaryFileResponse($scap_filename);
            $response->headers->set('Content-Type', 'text/xml');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($scap_filename)
            );
        } else {
            throw $this->createNotFoundException('The SCAP does not exist');
        }

        return $response;
    }


    #[Route('/scap/{title}/{version}/{release}', name: 'scap_view')]
    public function scap_view($title, $version, $release): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $scaps = (array)json_decode(file_get_contents($toc_path));

        $scap = array_filter($scaps[$title], function ($obj) use ($version, $release) {
            if ($obj->version == $version  && $obj->release == $release) {
                return true;
            }
            return false;
        });
        $scap = array_pop($scap);
        if ($scap === null) {
            throw $this->createNotFoundException('The SCAP does not exist');
        }
        $scap_filename = realpath(__DIR__ . "/../../resources/data/scap/" . $scap->filename);
        if ($scap_filename === false || !is_file($scap_filename)) {
            throw $this->createNotFoundException('The SCAP does not exist');
        }
        $scap_xml = simplexml_load_file($scap_filename);
        if ($scap_xml === false) {
            throw new ServiceUnavailableHttpException(null, 'SCAP file could not be parsed.');
        }

        foreach ($scap_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $scap_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        
        $scap_xml->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");
        $scap_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/scap/source/1.2");
        $scap_xml->registerXPathNamespace("xccdf", "http://checklists.nist.gov/xccdf/1.2");

        $cci_filename = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        if ($cci_filename === false || !is_file($cci_filename)) {
            throw new ServiceUnavailableHttpException(null, 'CCI data file is not available.');
        }
        $cci_xml = simplexml_load_file($cci_filename);
        if ($cci_xml === false) {
            throw new ServiceUnavailableHttpException(null, 'CCI data file could not be parsed.');
        }

        foreach ($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $cci_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");

        return $this->render('scap/view.html.twig', [
            'controller_name' => 'ScapController',
            'cci' => $cci_xml,
            'scap' => $scap_xml,
            'scaps' => $scaps[$title],
            'title' => $title,
            'version' => $version,
            'release' => $release,
        ]);
    }

    #[Route('/scap/disa', name: 'scap_disa')]
    public function scap_disa(): Response
    {
        return $this->render('scap/download.html.twig', [
            'controller_name' => 'ScapController',
        ]);
    }


    /**
     * Admin-triggered DISA mirror — same engine as
     * `app:disa:sync-scap` (run nightly via bin/refresh-data.sh).
     * Streams progress to the browser so the operator can watch the
     * sync proceed.
     */
    #[Route('/scap/disa_download', name: 'scap_disa_download')]
    public function scap_disa_download(\App\Service\DisaCatalogSyncer $syncer): Response
    {
        set_time_limit(60 * 15);
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($syncer) {
            echo "<pre style='color:rgb(236, 226, 200);'>\n";
            @ob_flush(); flush();
            try {
                $stats = $syncer->sync(
                    \App\Service\DisaCatalogSyncer::KIND_SCAP,
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

