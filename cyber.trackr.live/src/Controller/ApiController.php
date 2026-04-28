<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ApiController extends AbstractController
{

    #[Route('/api', name: 'api_summary')]
    public function api_summary(Request $request): Response
    {
        $results = [
            "server_api_root" => "{$request->getScheme()}://{$request->getHttpHost()}/{$request->getBasePath()}api",
            "/rmf/4" => [
                "summary" => "The list of tracked RMF rev 4 controls."
            ],
            "/rmf/4/{control}" => [
                "summary" => "The details on the selected RMF rev 4 control",
                "parameters" => [
                    "control" => "The control to be detailed, i.e. AC-1 or CM-6"
                ]
            ],
            "/rmf/5" => [
                "summary" => "The list of tracked RMF rev 5 controls."
            ],
            "/rmf/5/{control}" => [
                "summary" => "The details on the selected RMF rev 5 control",
                "parameters" => [
                    "control" => "The control to be detailed, i.e. AC-1 or CM-6"
                ]
            ],
            "/cci" => [
                "summary" => "The list of tracked Common Control Identifiers (CCIs)."
            ],
            "/cci/{item}" => [
                "summary" => "The details on the selected CCI",
                "parameters" => [
                    "item" => "The CCI to be detailed, i.e. CCI-123456"
                ]
            ],
            "/stig" => [
                "summary" => "The list of tracked Security Technical Implementaiton Guides."
            ],
            "/stig/{title}/{version}/{release}" => [
                "summary" => "The summary on the selected STIG",
                "parameters" => [
                    "title" => "The title of the STIG to be viewed.",
                    "version" => "The version of the specific STIG.",
                    "release" => "The release of the specific STIG.",
                ]
            ],
            "/stig/{title}/{version}/{release}/{vuln}" => [
                "summary" => "The details on a requirement in the selected STIG",
                "parameters" => [
                    "title" => "The title of the STIG to be viewed.",
                    "version" => "The version of the specific STIG.",
                    "release" => "The release of the specific STIG.",
                    "vuln" => "The vuln_id to be viewed, i.e. V-123456"
                ]
            ],
            "/scap" => [
                "summary" => "The list of tracked Security Content Automation Protocols (SCAPs)."
            ],
            "/scap/{title}/{version}/{release}" => [
                "summary" => "The summary on the selected SCAP",
                "parameters" => [
                    "title" => "The title of the SCAP to be viewed.",
                    "version" => "The version of the specific SCAP.",
                    "release" => "The release of the specific SCAP.",
                ]
            ],
            "/scap/{title}/{version}/{release}/{vuln}" => [
                "summary" => "The details on a requirement in the selected SCAP",
                "parameters" => [
                    "title" => "The title of the SCAP to be viewed.",
                    "version" => "The version of the specific SCAP.",
                    "release" => "The release of the specific SCAP.",
                    "vuln" => "The vuln_id to be viewed, i.e. V-123456"
                ]
            ],
        ];
        return new Response( json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/rmf/4', name: 'api_rmfv4_list')]
    public function api_rmfv4_list(): Response
    {
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

        $controls_xml = $rmf_v4_xml->xpath("/controls:controls/controls:control");
        $c = [];
        foreach ($controls_xml as $control) {
            $c[(string)$control->number] = (string)$control->title;
        }

        return $this->json(['controls' => $c]);
    }

    #list all rmf 5 controls
    #[Route('/api/rmf/4/{con}', name: 'api_rmfv4_show')]
    public function api_rmfv4_show($con): Response
    {
        $con = strtoupper(trim((string) $con));

        $rmf_json_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");
        if (file_exists($rmf_json_path)) {
            $rmfs_json = json_decode(file_get_contents($rmf_json_path));
        } else {
            $rmfs_json = [];
        }

        $rmf_v4_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v4-controls.xml");

        $feed = file_get_contents($rmf_v4_filename);
        $feed = preg_replace('/(<\/|<)[a-zA-Z]+:([a-zA-Z0-9]+[ =>])/', '$1$2', $feed);

        $rmf_v4_xml = simplexml_load_string((string) $feed);
        $rmf_v4_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/feed/2.0");
        $rmf_v4_xml->registerXPathNamespace("aaa", "http://scap.nist.gov/schema/sp800-53/2.0");

        $controls_xml = $rmf_v4_xml->xpath("/aaa:controls/aaa:control[./aaa:number='{$con}']")[0];
        $controls_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/feed/2.0");
        $controls_xml->registerXPathNamespace("aaa",   "http://scap.nist.gov/schema/sp800-53/2.0");

        $results = [];

        $results['number'] = (string)current($controls_xml->xpath("./aaa:number/text()"));
        $results['title'] = (string)current($controls_xml->xpath("./aaa:title/text()"));
        $results['family'] = (string)current($controls_xml->xpath("./aaa:family/text()"));
        $results['baseline'] = explode(",", implode(",", ($controls_xml->xpath("./aaa:baseline-impact"))));

        $discussion = "";
        $nodes = $controls_xml->xpath(
            "./aaa:supplemental-guidance/aaa:description/descendant-or-self::*/text()"
        );
        if (isset($nodes)) {
            foreach ($nodes as $node) {
                $discussion .= trim($node) . "\n";
            }
        }
        $results["discussion"] = $discussion;

        $statements = "";
        $nodes = json_decode(json_encode($controls_xml->xpath("./aaa:statement")))[0];
        if (isset($nodes->statement)) {
            $statements .= "{$nodes->description}\n";
            foreach ($nodes->statement as $node) {
                $statements .= "{$node->number}\n";
                $statements .= "{$node->description}\n";

                if (isset($node->statement)) {
                    foreach ($node->statement as $node2) {
                        $statements .= "{$node2->number}\n";
                        $statements .= "{$node2->description}\n";

                        if (isset($node2->statement)) {
                            foreach ($node2->statement as $node3) {
                                $statements .= "{$node3->number}\n";
                                $statements .= "{$node3->description}\n";

                                if (isset($node3->statement)) {
                                    foreach ($node3->statement as $node4) {
                                        $statements .= "{$node4->number}\n";
                                        $statements .= "{$node4->description}\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $results["statements"] = $statements;

        $results['assessment_procedures'] = [];
        foreach ($rmfs_json as $r) {
            if ($r->id == (string)current($controls_xml->xpath("./aaa:number/text()"))) {
                foreach ($r->ccis as $ap) {
                    $results['assessment_procedures'][] = [
                        "assessment_procedures" => $ap->ap_acronym,
                        "cci" => $ap->id,
                        "description" => $ap->definition,
                    ];
                }
            }
        }

        $results['related'] = [];
        foreach ($controls_xml->xpath("./aaa:supplemental-guidance/aaa:related/text()") as $r) {
            $results['related'][((string)$r)] = ((string)$rmf_v4_xml->xpath("/aaa:controls/aaa:control[aaa:number='" . ((string)$r) . "']/aaa:title")[0]);
        }

        $results['references'] = [];
        foreach ($controls_xml->xpath("./aaa:references/aaa:reference") as $ref) {

            $results['references'][] = [
                "href" => str_replace("\/", "/", ($ref->item)['href']),
                "text" => (string)$ref->item,
            ];
        }

        $results['enhancements'] = [];
        foreach ($controls_xml->xpath("./aaa:control-enhancements/aaa:control-enhancement") as $enhancement) {
            $results['enhancements'][current($enhancement->number)] = current($enhancement->title);
        }

        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/rmf/5', name: 'api_rmf_list')]
    public function api_rmf_list(): Response
    {
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

        $controls_xml = $rmf_v5_xml->xpath("/controls:controls/controls:control");
        $c = [];
        foreach ($controls_xml as $control) {
            $c[(string)$control->number] = (string)$control->title;
        }

        return $this->json(['controls' => $c], Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #list all rmf 5 controls
    #[Route('/api/rmf/5/{con}', name: 'api_rmf_show')]
    public function api_rmf_show($con): Response
    {
        $con = strtoupper(trim((string) $con));

        $rmf_json_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");
        if (file_exists($rmf_json_path)) {
            $rmfs_json = json_decode(file_get_contents($rmf_json_path));
        } else {
            $rmfs_json = [];
        }

        $rmf_v5_filename = realpath(__DIR__ . "/../../resources/data/rmf/800-53v5-controls.xml");

        $feed = file_get_contents($rmf_v5_filename);
        $feed = preg_replace('/(<\/|<)[a-zA-Z]+:([a-zA-Z0-9]+[ =>])/', '$1$2', $feed);

        $rmf_v5_xml = simplexml_load_string((string) $feed);
        $rmf_v5_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/2.0");
        $controls_xml = $rmf_v5_xml->xpath("/xmlns:controls/xmlns:control[./xmlns:number='{$con}']")[0];
        $controls_xml->registerXPathNamespace("xmlns", "http://scap.nist.gov/schema/sp800-53/2.0");

        $results = [];

        $results['number'] = (string)current($controls_xml->xpath("./xmlns:number/text()"));
        $results['title'] = (string)current($controls_xml->xpath("./xmlns:title/text()"));
        $results['family'] = (string)current($controls_xml->xpath("./xmlns:family/text()"));
        $results['baseline'] = explode(",", implode(",", ($controls_xml->xpath("./xmlns:baseline"))));

        $discussion = "";
        $nodes = $controls_xml->xpath(
            "./xmlns:discussion/xmlns:description/descendant-or-self::*/text()"
        );
        if (isset($nodes)) {
            foreach ($nodes as $node) {
                $discussion .= trim($node) . "\n";
            }
        }
        $results["discussion"] = $discussion;

        $statements = "";
        $nodes = json_decode(json_encode($controls_xml->xpath(
            "./xmlns:statement/descendant-or-self::*/text()"
        )[0]));

        if (isset($nodes->statement)) {
            foreach ($nodes->statement as $node) {
                $statements .= "{$node->number}\n";
                $statements .= "{$node->description}\n";

                if (isset($node->statement)) {
                    foreach ($node->statement as $node2) {
                        $statements .= "{$node2->number}\n";
                        $statements .= "{$node2->description}\n";

                        if (isset($node2->statement)) {
                            foreach ($node2->statement as $node3) {
                                $statements .= "{$node3->number}\n";
                                $statements .= "{$node3->description}\n";

                                if (isset($node3->statement)) {
                                    foreach ($node3->statement as $node4) {
                                        $statements .= "{$node4->number}\n";
                                        $statements .= "{$node4->description}\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $results["statements"] = $statements;

        $results['assessment_procedures'] = [];
        foreach ($rmfs_json as $r) {
            if ($r->id == (string)current($controls_xml->xpath("./xmlns:number/text()"))) {
                foreach ($r->ccis as $ap) {
                    $results['assessment_procedures'][] = [
                        "assessment_procedures" => $ap->ap_acronym,
                        "cci" => $ap->id,
                        "description" => $ap->definition,
                    ];
                }
            }
        }

        $results['related'] = [];
        foreach ($controls_xml->xpath("./xmlns:related/text()") as $r) {
            $results['related'][((string)$r)] = ((string)$rmf_v5_xml->xpath("/xmlns:controls/xmlns:control[xmlns:number='" . ((string)$r) . "']/xmlns:title")[0]);
        }

        $results['references'] = [];
        foreach ($controls_xml->xpath("./xmlns:references/xmlns:reference") as $ref) {
            $results['references'][] = [
                "href" => str_replace("\/", "/", current($ref->item)['href']),
                "text" => current($ref->item->text),
                "name" => current($ref->short_name)
            ];
        }

        $results['enhancements'] = [];
        foreach ($controls_xml->xpath("./xmlns:control-enhancements/xmlns:control-enhancement") as $enhancement) {
            $results['enhancements'][current($enhancement->number)] = current($enhancement->title);
        }

        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/cci', name: 'api_cci_list')]
    public function api_cci_list(): Response
    {
        $cci_path = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        if (file_exists($cci_path)) {
            $cci_xml = simplexml_load_file($cci_path);
        }

        foreach ($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $cci_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");

        $results = [];

        foreach ($cci_xml->xpath('//xmlns:cci_item') as $cci) {
            $results[(string)$cci->attributes()['id']] = (string)$cci->definition;
        }

        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/cci/{item}', name: 'api_cci_show')]
    public function api_cci_show($item): Response
    {
        $results = [];

        $item = sprintf("CCI-%06d", (int)(preg_replace("/[^0-9]/", "", (string) $item)));

        $cci_path = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        $rmf_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");

        if (file_exists($cci_path)) {
            $cci_xml = simplexml_load_file($cci_path);
        }

        foreach ($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a"; //Assign an arbitrary namespace prefix.
            }
            $cci_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");

        if (file_exists($rmf_path)) {
            $rmfs_json = json_decode(file_get_contents($rmf_path));
        }

        $cci = current($cci_xml->xpath("//xmlns:cci_item[@id='$item']"));
        $results['cci'] = (string)$cci->attributes()['id'];
        $results["cci_definition"] = (string)$cci->definition;
        foreach ($rmfs_json as $item) {
            foreach ($item->ccis as $c) {
                if ($c->id == (string)$cci->attributes()['id']) {
                    $results["cci_auditor"] = $c->guidance_auditor;
                    $results["cci_guidance"] = $c->guidance_org;

                    $results["rmf"] = $item->id;
                    $results["ap_acronym"] = $c->ap_acronym;
                    $results['family'] = $item->family;
                    $results['name'] = $item->name;

                    $results['rmf_guidance'] = $item->guidance;
                    $results["rmf_definition"] = $item->definition;
                }
            }
        }

        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/stig', name: 'api_stig_list')]
    public function api_stig_list(): Response
    {

        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $results = (array)json_decode(file_get_contents($toc_path));
        foreach (array_keys($results) as $stig_name) {
            foreach ($results[$stig_name] as $stig) {
                unset($stig->filename);
                $stig->link = "/stig/{$stig_name}/{$stig->version}/{$stig->release}";
            }
        }
        ksort($results);
        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/stig/tip', name: 'api_stig_tip')]
    public function api_stig_tip(): Response
    {
        $toc_path = realpath(__DIR__ . "/../../resources/data/stig_toc.json");
        $stigs = (array)json_decode(file_get_contents($toc_path));
        $collection = [];
        foreach (array_keys($stigs) as $stig_name) {
            foreach ($stigs[$stig_name] as $stig) {
                $stig->title = $stig_name;
                $stig->version = $stig->version;
                $stig->release = $stig->release;
                $collection[] = $stig;
            }
        }

        $key = array_rand($collection);
        
        $filesystem = new Filesystem();
        $finder = new Finder();

        $stig_filename = realpath(__DIR__ . "/../../resources/data/stig/" . $collection[$key]->filename);
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

        $groups = $stig_xml->xpath('/xmlns:Benchmark/xmlns:Group');
        shuffle($groups);
        $group = $groups[0];
        $vuln_id = (string)$group->attributes()['id'];

        $collection[$key]->tip = [
            "title" => (string)current($group->Rule->title),
            "rule" => (string)current($group->Rule->attributes()['id']),
            "severity" => (string)current($group->Rule->attributes()['severity']),
            "vuln_id" => $vuln_id,        
        ];
        


        return new Response(json_encode($collection[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }




    #[Route('/api/stig/{title}/{version}/{release}', name: 'api_stig_summary')]
    public function api_stig_summary($title, $version, $release): Response
    {
        $results = [];

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

        $results["id"] = (string)$stig_xml->attributes()['id'][0];
        $results["title"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:title'));
        $results["description"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:description'));
        $results["status"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:status'));
        $results["published"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:status/@date'));

        $results["profiles"] = [];
        foreach ($stig_xml->xpath('/xmlns:Benchmark/xmlns:Profile') as $profile) {
            $results["profiles"][(string)$profile->attributes()['id']] = (string)current($profile->title);
        }

        $results["requirements"] = [];
        foreach ($stig_xml->xpath('/xmlns:Benchmark/xmlns:Group') as $group) {
            $vuln_id = (string)$group->attributes()['id'];

            $results["requirements"][$vuln_id] = [
                "title" => (string)current($group->Rule->title),
                "rule" => (string)current($group->Rule->attributes()['id']),
                "severity" => (string)current($group->Rule->attributes()['severity']),
                "link" => "/stig/{$title}/{$version}/{$release}/{$vuln_id}"
            ];
        }

        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
    }

    #[Route('/api/stig/{title}/{version}/{release}/{vuln}', name: 'api_stig_vuln')]
    public function api_stig_vuln(Request $request, $title, $version, $release, $vuln): Response
    {
        $results = [];

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


        $results["stig"] = (string)$stig_xml->attributes()['id'][0];
        $results["stig-title"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:title'));
        $results["stig-description"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:description'));
        $results["stig-status"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:status'));
        $results["stig-published"] = (string)current($stig_xml->xpath('/xmlns:Benchmark/xmlns:status/@date'));

        $vuln = current($stig_xml->xpath("/xmlns:Benchmark/xmlns:Group[@id='{$vuln}']"));
        $results["id"] = (string)current($vuln->attributes()['id']);
        $results["group"] = (string)$vuln->title;
        $results["rule"] = (string)$vuln->Rule->attributes()['id'];
        $results["severity"] = (string)$vuln->Rule->attributes()['severity'];
        $results["version"] = (string)$vuln->Rule->version;
        $results["requirement-title"] = (string)$vuln->Rule->title;
        $results["requirement-description"] =  preg_replace("/<[^>]*>/is", "", str_replace("<Documentable>false</Documentable>", "", (string)$vuln->Rule->description));

        if( $request->query->get('m','') == sha1($results['requirement-title'])){
	    $results["mitigation-statement"] = shell_exec("/usr/bin/python3 /home/dh_t7zn6y/bin/google-ai.py $title $version $release {$results['id']} ");
	}

        $results["identifiers"] = [];
        foreach ((array)$vuln->Rule->ident as $k => $ident) {
            if (is_numeric($k)) {
                $results["identifiers"][] = $ident;
            }
        }

        $results["check-id"] = (string)$vuln->Rule->check->attributes()['system'];
        $results["check-text"] = (string)$vuln->Rule->check->{'check-content'};

        $results["fix-id"] = (string)$vuln->Rule->fix->attributes()['id'];
        $results["fix-text"] = (string)$vuln->Rule->fixtext;

	return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
        //return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }


    #[Route('/api/scap', name: 'api_scap_list')]
    public function api_scap_list(): Response
    {

        $toc_path = realpath(__DIR__ . "/../../resources/data/scap_toc.json");
        $results = (array)json_decode(file_get_contents($toc_path));
        foreach (array_keys($results) as $scap_name) {
            foreach ($results[$scap_name] as $scap) {
                unset($scap->filename);
                $scap->link = "/scap/{$scap_name}/{$scap->version}/{$scap->release}";
            }
        }
        ksort($results);
        return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );	
	//return new Response(str_replace("\\n", "\n", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
    }


    #[Route('/api/scap/{title}/{version}/{release}', name: 'api_scap_summary')]
    public function api_scap_summary($title, $version, $release): Response
    {
        $results = [];

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
        $scap_filename = realpath(__DIR__ . "/../../resources/data/scap/" . $scap->filename);
        if (file_exists($scap_filename)) {
            $scap_xml = simplexml_load_file($scap_filename);
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

        $results["id"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/@id'));
        $results["title"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:title'));
        $results["description"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:description'));
        $results["status"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:status'));
        $results["published"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:status/@date'));

        $results["requirements"] = [];
        foreach ($scap_xml->xpath('//xccdf:Benchmark/xccdf:Group') as $group) {
            $vuln_id = str_replace("xccdf_mil.disa.stig_group_", "", (string)$group->attributes()['id']);

            $results["requirements"][$vuln_id] = [
                "title" => (string)current($group->xpath("./xccdf:Rule/xccdf:title")),
                "rule" => str_replace("xccdf_mil.disa.stig_rule_", "", (string)current($group->xpath("./xccdf:Rule/@id"))),
                "severity" => (string)current($group->xpath("./xccdf:Rule/@severity")),
                "link" => "/scap/{$title}/{$version}/{$release}/{$vuln_id}"
            ];
        }

	return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
	//return new Response(str_replace("\\n", "\n", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
    }


    #[Route('/api/scap/{title}/{version}/{release}/{vuln}', name: 'api_scap_vuln')]
    public function api_scap_vuln($title, $version, $release, $vuln): Response
    {
        $results = [];

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
        $scap_filename = realpath(__DIR__ . "/../../resources/data/scap/" . $scap->filename);
        if (file_exists($scap_filename)) {
            $scap_xml = simplexml_load_file($scap_filename);
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

        $results["scap-id"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/@id'));
        $results["scap-title"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:title'));
        $results["scap-description"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:description'));
        $results["scap-status"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:status'));
        $results["scap-published"] = (string)current($scap_xml->xpath('//xccdf:Benchmark/xccdf:status/@date'));

        $results["requirements"] = [];

        $vuln = current($scap_xml->xpath("//xccdf:Benchmark/xccdf:Group[@id='xccdf_mil.disa.stig_group_{$vuln}']"));

        $results["id"] = (string)current($vuln->attributes()['id']);
        $results["group"] = (string)current($vuln->xpath("./xccdf:title"));
        $results["rule"] = (string)current($vuln->xpath("./xccdf:Rule/@id"));
        $results["severity"] = (string)current($vuln->xpath("./xccdf:Rule/@severity"));
        $results["version"] = (string)current($vuln->xpath("./xccdf:Rule/xccdf:version"));
        $results["requirement-title"] = (string)current($vuln->xpath("./xccdf:Rule/xccdf:title"));
        $results["requirement-description"] = preg_replace(
            "/<[^>]*>/is",
            "",
            str_replace(
                "<Documentable>false</Documentable>",
                "",
                (string)current($vuln->xpath("./xccdf:Rule/xccdf:description"))
            )
        );

        $results["identifiers"] = [];
        foreach ($vuln->xpath("./xccdf:Rule/xccdf:ident") as $ident) {
            $results["identifiers"][] = (string)$ident;
        }

        $results["fix-id"] = (string)current($vuln->xpath("./xccdf:Rule/xccdf:fix/@id"));
        $results["fix-text"] = (string)current($vuln->xpath("./xccdf:Rule/xccdf:fixtext"));

	return new Response(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['content-type' => 'application/json'] );
	//return new Response(str_replace("\\n", "\n", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
    }
}
