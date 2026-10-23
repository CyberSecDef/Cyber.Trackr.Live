<?php

namespace App\Controller;

use App\Service\CciControlMap;
use App\Service\ScapTocBuilder;
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
    #[Route('/scap', name: 'scap', methods: ['GET'])]
    public function scap(\App\Service\ScapTocBuilder $tocBuilder): Response
    {
        $scaps = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // The toc is rebuilt by bin/refresh-data.sh and bin/deploy.sh
        // (app:stig:rebuild, which covers SCAP too). This page used to scan
        // the data dir for files missing from it and rewrite the toc
        // mid-request, racing the cron's rebuild (review S7, P7).

        $scaps_latest = $tocBuilder->latestPerTitle($scaps);

        return $this->render('scap/index.html.twig', [
            'controller_name' => 'ScapController',
            'scaps_latest' => $scaps_latest,
            'scap_count'   => count($scaps),
        ]);
    }

    #[Route('/scap/{title}/{version}/{release}/download', name: 'scap_download', methods: ['GET'])]
    public function scap_download($title, $version, $release, ScapTocBuilder $tocBuilder): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $scaps = (array)json_decode(file_get_contents($toc_path));

        if (!isset($scaps[$title])) {
            // Firefox titles retired by the platform split (ScapTocBuilder).
            $moved = $tocBuilder->platformRedirect((string) $title, (string) $version, (string) $release, $scaps);
            if ($moved !== null) {
                return $this->redirectToRoute('scap_download', ['title' => $moved, 'version' => $version, 'release' => $release], 301);
            }
            throw $this->createNotFoundException('The SCAP does not exist');
        }
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


    #[Route('/scap/{title}/{version}/{release}', name: 'scap_view', methods: ['GET'])]
    public function scap_view($title, $version, $release, CciControlMap $cciControlMap, ScapTocBuilder $tocBuilder): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $scaps = (array)json_decode(file_get_contents($toc_path));

        if (!isset($scaps[$title])) {
            // Firefox titles retired by the platform split (ScapTocBuilder).
            $moved = $tocBuilder->platformRedirect((string) $title, (string) $version, (string) $release, $scaps);
            if ($moved !== null) {
                return $this->redirectToRoute('scap_view', ['title' => $moved, 'version' => $version, 'release' => $release], 301);
            }
            throw $this->createNotFoundException('The SCAP does not exist');
        }
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

        return $this->render('scap/view.html.twig', [
            'controller_name' => 'ScapController',
            'cci_controls' => $cciControlMap->all(),
            'scap' => $scap_xml,
            'scaps' => $scaps[$title],
            'title' => $title,
            'version' => $version,
            'release' => $release,
        ]);
    }
}
