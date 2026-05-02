<?php
namespace App\Controller;

use App\Service\OverlayLoader;
use App\Service\R4ToR5Mapper;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;

class RmfController extends AbstractController
{

    #[Route('/rmf/5', name: 'rmf_v5_view')]
    public function rmf_v5_view(OverlayLoader $overlays): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();

        $rmf_json_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");
        if(file_exists($rmf_json_path)){
            $rmfs_json = json_decode(file_get_contents($rmf_json_path));
        }
        
        $rmf_v5_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v5-controls.xml");
        if (file_exists($rmf_v5_filename)) {
            $rmf_v5_xml = simplexml_load_file($rmf_v5_filename);
        }

        foreach ($rmf_v5_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $rmf_v5_xml->registerXPathNamespace($strPrefix, $strNamespace);
        
        }
        $rmf_v5_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/2.0");
        $rmf_v5_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");

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

        // Pre-compute control → overlays once per request so the template doesn't
        // call OverlayLoader on every <controls:control> iteration.
        $overlay_map = [];
        $xml_base_nums = [];
        foreach ($rmf_v5_xml->xpath("/controls:controls/controls:control") as $c) {
            $num = trim((string) $c->number);
            if ($num !== '') {
                $overlay_map[$num] = $overlays->getControlOverlays($num);
                $xml_base_nums[]   = $num;
            }
        }

        // Augment overlay metadata with the *visible row count* for the
        // chips. Without this, a chip says "NIST High: 370" but only ~188
        // rows show after filtering (the other 182 are enhancements
        // rendered inside parent rows, not as separate top-level rows).
        $overlay_meta  = $overlays->getOverlays();
        $row_counts    = $overlays->getRowCounts($xml_base_nums);
        foreach ($overlay_meta as &$o) {
            $o['row_count'] = $row_counts[$o['id']] ?? 0;
        }
        unset($o);

        return $this->render('rmf/view_v5.html.twig', [
            'controller_name' => 'RmfController',
            'cci' => $cci_xml,
            'rmf' => $rmf_v5_xml,
            'rmfs_json' => $rmfs_json,
            'overlays' => $overlay_meta,
            'overlay_map' => $overlay_map,
        ]);
    }

    #[Route('/rmf/4', name: 'rmf_v4_view')]
    public function rmf_v4_view(): Response
    {
        $filesystem = new Filesystem();
        $finder = new Finder();
        
        $rmf_json_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");
        if(file_exists($rmf_json_path)){
            $rmfs_json = json_decode(file_get_contents($rmf_json_path));
        }

        $rmf_v4_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v4-controls.xml");
        if (file_exists($rmf_v4_filename)) {
            $rmf_v4_xml = simplexml_load_file($rmf_v4_filename);
        }

        foreach ($rmf_v4_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $rmf_v4_xml->registerXPathNamespace($strPrefix, $strNamespace);
        
        }
        $rmf_v4_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/feed/2.0");
        $rmf_v4_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");

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

        return $this->render('rmf/view_v4.html.twig', [
            'controller_name' => 'RmfController',
            'cci' => $cci_xml,
            'rmf' => $rmf_v4_xml,
            'rmfs_json' => $rmfs_json,
        ]);
    }

    #[Route('/rmf/4-to-5', name: 'rmf_4_to_5_view')]
    public function rmf_4_to_5_view(R4ToR5Mapper $mapper): Response
    {
        return $this->render('rmf/view_4_to_5.html.twig', $mapper->map());
    }

    #[Route('/baselines', name: 'baselines_view')]
    public function baselines_view(OverlayLoader $overlays): Response
    {
        // Heat map covers the four NIST baselines published as OSCAL profiles.
        // Adding more is one line — drop new overlay IDs in the array below.
        $matrix = $overlays->getFamilyMatrix([
            'nist-low',
            'nist-moderate',
            'nist-high',
            'nist-privacy',
        ]);

        // Map family code → human label so each row carries context. Pulled
        // from the 20 plan-generator family JSONs we already author since
        // they're the authoritative naming we use sitewide.
        $familyNames = [
            'AC' => 'Access Control',
            'AT' => 'Awareness and Training',
            'AU' => 'Audit and Accountability',
            'CA' => 'Assessment, Authorization, and Monitoring',
            'CM' => 'Configuration Management',
            'CP' => 'Contingency Planning',
            'IA' => 'Identification and Authentication',
            'IR' => 'Incident Response',
            'MA' => 'Maintenance',
            'MP' => 'Media Protection',
            'PE' => 'Physical and Environmental Protection',
            'PL' => 'Planning',
            'PM' => 'Program Management',
            'PS' => 'Personnel Security',
            'PT' => 'PII Processing and Transparency',
            'RA' => 'Risk Assessment',
            'SA' => 'System and Services Acquisition',
            'SC' => 'System and Communications Protection',
            'SI' => 'System and Information Integrity',
            'SR' => 'Supply Chain Risk Management',
        ];

        return $this->render('rmf/baselines.html.twig', array_merge($matrix, [
            'family_names' => $familyNames,
        ]));
    }
}