<?php

namespace App\Controller;

use App\Service\StigTocBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends AbstractController
{

    #[Route('/', name: 'home')]
    public function index(StigTocBuilder $tocBuilder): Response
    {
        $stigs = json_decode(file_get_contents($tocBuilder->tocPath()), true) ?: [];

        // Top 100 most-recently-released — the homepage table is curated.
        $recent_stigs = array_slice($tocBuilder->latestPerTitle($stigs), 0, 100);

        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
            'recent_stigs'   => $recent_stigs,
            'stig_count'     => count($stigs),
            'controls_count' => $this->countControlsV5(),
            'cci_count'      => $this->countCciItems(),
        ]);
    }

    /**
     * Cheap proxy count for the homepage trust strip — substring match on the
     * file is ~100x faster than parsing the full XML and accurate enough for
     * a decorative number. Returns 0 if the file is missing.
     */
    private function countControlsV5(): int
    {
        $path = realpath(__DIR__ . "/../../resources/data/rmf/800-53v5-controls.xml");
        if ($path === false) return 0;
        $raw = @file_get_contents($path);
        return $raw === false ? 0 : substr_count($raw, '<controls:control ') + substr_count($raw, '<controls:control>');
    }

    private function countCciItems(): int
    {
        $path = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        if ($path === false) return 0;
        $raw = @file_get_contents($path);
        return $raw === false ? 0 : substr_count($raw, '<cci_item ');
    }

    #[Route('/search/{query}', name: 'search')]
    public function search($query = ""): Response
    {
        $original_query = $query;
        $terms = [];
        $matches = [];
        $results = [
            "rmfv4" => [],
            "rmfv5" => [],
            "vulns" => [],
            "ccis" => [],
            "aps" => [],
        ];

        preg_match_all('/".+"/', (string) $query, $matches);
        foreach ($matches[0] as $quoted) {
            array_push($terms, trim(str_ireplace('"', '', $quoted)));
            $query = str_ireplace($quoted, "", $query);
        }
        foreach (array_filter(explode(" ", (string) $query), fn($t) => strlen((string) $t) > 3) as $t) {
            array_push($terms, trim($t));
        }

        $rmf_v4_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v4-controls.xml");
        $rmf_v4_xml = simplexml_load_file($rmf_v4_filename);
        foreach ($rmf_v4_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {$strPrefix = "a";}
            $rmf_v4_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $rmf_v4_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/feed/2.0");
        $rmf_v4_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");

        $rmf_v5_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v5-controls.xml");
        $rmf_v5_xml = simplexml_load_file($rmf_v5_filename);
        foreach ($rmf_v5_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {$strPrefix = "a";}
            $rmf_v5_xml->registerXPathNamespace($strPrefix, $strNamespace);        
        }
        $rmf_v5_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/2.0");
        $rmf_v5_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");

        $cci_path = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        $cci_xml = simplexml_load_file($cci_path );
        foreach($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if(strlen((string) $strPrefix)==0) {$strPrefix="a";}
            $cci_xml->registerXPathNamespace($strPrefix,$strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns","http://iase.disa.mil/cci");
        
        $rmf_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");
        $rmfs_json = json_decode(file_get_contents($rmf_path));
        
        $stig_toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $current_stigs = (array)json_decode(file_get_contents($stig_toc_path));

        $scap_toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $current_scaps = (array)json_decode(file_get_contents($scap_toc_path));

        foreach($terms as $term){            
            $term = trim(strtolower($term));

            //search in rmfv4
            $xpath = "/controls:controls/controls:control[.//aaa:description/text()[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),'{$term}')]]";
            foreach($rmf_v4_xml->xpath($xpath) as $res){
                $res->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");
                if( !in_array( (string)($res->xpath('./aaa:number')[0]), array_column( $results['rmfv4'], 'id'))){
                    $results['rmfv4'][] = [
                        "id" => (string)($res->xpath('./aaa:number')[0]),
                        "title" => (string)($res->xpath('./aaa:title')[0]),
                        "note" => "",
                        "description" => implode("\n", ($res->xpath('.//aaa:description'))),
                    ];
                }                
            }

            //search in rmfv5
            $xpath = "/controls:controls/controls:control[.//aaa:description/text()[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),'{$term}')]]";
            foreach($rmf_v5_xml->xpath($xpath) as $res){
                $res->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");
                if( !in_array( (string)($res->xpath('./aaa:number')[0]), array_column( $results['rmfv5'], 'id'))){
                    $results['rmfv5'][] = [
                        "id" => (string)($res->xpath('./aaa:number')[0]),
                        "title" => (string)($res->xpath('./aaa:title')[0]),
                        "note" => "",
                        "description" => implode("\n", ($res->xpath('.//aaa:description'))),
                    ];
                }                
            }
            
            //search in cci
            $xpath = "/xmlns:cci_list/xmlns:cci_items/xmlns:cci_item[.//xmlns:definition/text()[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),'{$term}')]]";
            foreach($cci_xml->xpath($xpath) as $res){
                $res->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");
                if( !in_array( (string)($res->xpath('./@id')[0]), array_column( $results['ccis'], 'id'))){
                    $results['ccis'][] = [
                        "id" => (string)($res->xpath('./@id')[0]),
                        "title" => "",
                        "note" => "",
                        "description" => implode("\n", ($res->xpath('.//xmlns:definition'))),
                    ];
                }                
            }

            
            foreach($rmfs_json as $control){
                foreach($control->ccis as $ap){
                    if(stristr((string) $ap->definition,$term) > 0){
                        if( !in_array( $ap->ap_acronym, array_column( $results['aps'], 'id'))){
                            $results['aps'][] = [
                                "id" => $ap->ap_acronym,
                                "title" => $ap->id,
                                "note" => $ap->guidance_org,
                                "description" => $ap->definition
                            ];
                        }
                    }
                }
            }

            foreach(array_keys($current_stigs) as $stig_title){
                $stigs = $current_stigs[$stig_title];
                usort(
                    $stigs, 
                    fn($a, $b) => strtotime((string) $b->date) - strtotime((string) $a->date)
                );

                $stig_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $stigs[0]->filename);
                if (file_exists($stig_filename)) {$stig_xml = simplexml_load_file($stig_filename);}
                foreach ($stig_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
                    if (strlen((string) $strPrefix) == 0) {$strPrefix = "a";}
                    $stig_xml->registerXPathNamespace($strPrefix, $strNamespace);
                }
                $stig_xml->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");
                foreach($stig_xml->xpath("//xmlns:Group[.//*/text()[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),'{$term}')]]") as $group){
                    $group->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");
                    $results['vulns'][] = [
                        "stig_title" => $stig_title,
                        "version" => $stigs[0]->version,
                        "release" => $stigs[0]->release,
                        "released" => $stigs[0]->released ?? '',
                        "filename" => $stigs[0]->filename,
                        "group" => "{$group->xpath('./@id')[0]}",
                        "vuln" => $group->xpath('./@id')[0],
                        "rule" => $group->xpath('./xmlns:Rule/@id')[0],
                        "note" => implode(",", $group->xpath('./xmlns:Rule/xmlns:title/text()')),
                        "description" => implode(",", $group->xpath('./xmlns:Rule/xmlns:description/text()'))
                    ];
                }

                foreach($stig_xml->xpath("//xmlns:Group[
                    ./@id[translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = '{$term}']
                    or
                    ./xmlns:Rule/@id[translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = '{$term}']
                    or
                    ./xmlns:Rule/ident[translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = '{$term}']
                    ]") as $group){
                    $group->registerXPathNamespace("xmlns", "http://checklists.nist.gov/xccdf/1.1");
                    $results['vulns'][] = [
                        "stig_title" => $stig_title,
                        "version" => $stigs[0]->version,
                        "release" => $stigs[0]->release,
                        "released" => $stigs[0]->released ?? '',
                        "filename" => $stigs[0]->filename,
                        "group" => "{$group->xpath('./@id')[0]}",
                        "vuln" => $group->xpath('./@id')[0],
                        "rule" => $group->xpath('./xmlns:Rule/@id')[0],
                        "note" => implode(",", $group->xpath('./xmlns:Rule/xmlns:title/text()')),
                        "description" => implode(",", $group->xpath('./xmlns:Rule/xmlns:description/text()'))
                    ];
                }

            }
        }
        
        usort($results['rmfv4'], fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        usort($results['rmfv5'], fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        usort($results['ccis'], fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        usort($results['aps'], fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
        shuffle($results['vulns']);
        
        return $this->render('home/search.html.twig', [
            'controller_name' => 'HomeController',
            'query' => $original_query,
            'terms' => $terms,
            'results' => $results,
        ]);
    }

    #[Route('/report_generator', name: 'report_generator')]
    public function report_generator(): Response
    {
        return $this->render('home/report_generator.html.twig', [
            'controller_name' => 'HomeController'
        ]);
    }

    #[Route('/contactus', name: 'contact_us')]
    public function contact_us(): Response
    {
        return $this->render('home/contact.html.twig', [
            'controller_name' => 'HomeController'
        ]);
    }

    #[Route('/mission', name: 'mission')]
    public function mission(): Response
    {
        return $this->render('home/mission.html.twig', [
            'controller_name' => 'HomeController'
        ]);
    }
}
