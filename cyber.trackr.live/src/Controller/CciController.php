<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class CciController extends AbstractController
{

    #[Route('/cci', name: 'cci')]
    public function cci(): Response
    {
        $results = [];

        $cci_path = realpath(__DIR__ . "/../../resources/data/cci/U_CCI_List.xml");
        $rmf_path = realpath(__DIR__ . "/../../resources/data/800-53r4.json");

        if(file_exists($cci_path)){
            $cci_xml = simplexml_load_file($cci_path );
        }

        foreach($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if(strlen((string) $strPrefix)==0) {
                $strPrefix="a"; //Assign an arbitrary namespace prefix.
            }
            $cci_xml->registerXPathNamespace($strPrefix,$strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns","http://iase.disa.mil/cci");
        
        if(file_exists($rmf_path)){
            $rmfs_json = json_decode(file_get_contents($rmf_path));
        }        
        
        foreach($cci_xml->xpath('//xmlns:cci_item') as $cci){
            $control = [];
            $control['cci'] = (string)$cci->attributes()['id'];
            $control["cci_definition"] = (string)$cci->definition;
            foreach( $rmfs_json as $item){
                foreach($item->ccis as $c){
                    if($c->id == (string)$cci->attributes()['id']){
                        $control["cci_auditor"] = $c->guidance_auditor;
                        $control["cci_guidance"] = $c->guidance_org;
                        
                        $control["rmf"] = $item->id;
                        $control["ap_acronym"] = $c->ap_acronym;
                        $control['family'] = $item->family;
                        $control['name'] = $item->name;
                        
                        $control['rmf_guidance'] = $item->guidance;
                        $control["rmf_definition"] = $item->definition;
                    }
                }
            }

            $results[] = $control;
        }

        return $this->render('cci/index.html.twig', [
            'controller_name' => 'HomeController',
            'results' => $results,
        ]);
    }
    
}