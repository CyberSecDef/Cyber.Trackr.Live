<?php
namespace App\Controller;

use App\Service\OverlayLoader;
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
        foreach ($rmf_v5_xml->xpath("/controls:controls/controls:control") as $c) {
            $num = trim((string) $c->number);
            if ($num !== '') {
                $overlay_map[$num] = $overlays->getControlOverlays($num);
            }
        }

        return $this->render('rmf/view_v5.html.twig', [
            'controller_name' => 'RmfController',
            'cci' => $cci_xml,
            'rmf' => $rmf_v5_xml,
            'rmfs_json' => $rmfs_json,
            'overlays' => $overlays->getOverlays(),
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


}