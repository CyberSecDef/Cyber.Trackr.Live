<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use ZipArchive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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


    #[Route('/scap/disa_download', name: 'scap_disa_download')]
    public function scap_disa_download()
    {
        $destination_dir = realpath(__DIR__ . "/../../resources/data/scap/");

        set_time_limit(60 * 15);

        $zip = new ZipArchive;
        
        // Set up the session
        $session = curl_init();
        curl_setopt(
            $session, 
            CURLOPT_USERAGENT, 
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0"
        );
        curl_setopt($session, CURLOPT_ENCODING, "");
        curl_setopt($session, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($session, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);


        // Set the cookies
        $cookies = [
            "CookieConsentPolicy" => "0:1",
            'LSKey-c$CookieConsentPolicy' => "0:1",
            "_ga" => "GA1.1.2133560557.1755112757",
            "_ga_6XQ570DY75" => 'GS2.1.s175511275$o1$g$t1755112873$j5$l0$h0'
        ];
        
        $headers = [
            "Accept: */*",
            "Accept-Encoding: gzip, deflate, br, zstd",
            "Accept-Language: en-US,en;q=0.9",
            "Origin: https://www.cyber.mil",
            "Referer: https://www.cyber.mil/stigs/downloads",
            "Sec-Fetch-Dest: empty",
            "Sec-Fetch-Mode: cors",
            "Sec-Fetch-Site: same-origin",
            "X-B3-Sampled: 0",
            "X-B3-SpanId: 2c49e714f67fbe18",
            "X-B3-TraceId: cc1d2f44f74587f28d6010e49537e8d4",
            "X-SFDC-Request-Id: 175511287321871eb0",
            "sec-ch-ua: Not;A=Brand;v=99, Microsoft Edge;v=139, Chromium;v=139",
            "sec-ch-ua-mobile: ?0",
            "sec-ch-ua-platform: Windows"
        ];
        
        $body =  '{"namespace":"","classname":"@udd/01pRw0000002mOj","method":"getCyberDocumentCatalogByDocumentLibrary","isContinuation":false,"params":{"documentLibrary":"STIGs"},"cacheable":false}';

        curl_setopt($session, CURLOPT_COOKIE, http_build_query($cookies));
        curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($session, CURLOPT_POSTFIELDS, $body);

        curl_setopt($session, CURLOPT_URL, "https://www.cyber.mil/webruntime/api/apex/execute?language=en-US&asGuest=true&htmlEncode=false");
        curl_setopt($session, CURLOPT_POST, true);
        curl_setopt($session, CURLOPT_HTTPHEADER, array_merge($headers, ["Content-Type: application/json; charset=utf-8"]));

        $web_context = stream_context_create(
            [
                "http" => [
                    "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36"
                ]
            ]
        );

        // Execute the request
        $response = curl_exec($session);
        
        $phpObject = json_decode($response);
        $index = 0;
        echo "<pre style='color:rgb(236, 226, 200);'>\n";
        foreach($phpObject->returnValue as $item){
            //preg_match_all('/https:.*wp-content.*stigs.*U_.*\.zip/', $web_contents, $matches);
            $link = $item->DownloadLink;
            if (stristr((string) $link, "Benchmark") && !stristr((string) $link, "STIGViewer") && !stristr((string) $link, "STIG_Library") && stristr((string) $link, "wp-content") && stristr((string) $link, "U_") && stristr((string) $link, ".zip")) {
                $index++;
                echo "{$index}. {$item->DownloadLink}\n";

                $temp_file = tempnam(sys_get_temp_dir(), 'STIG');
                $temp_file = realpath(__DIR__ . "/../../resources/data/zips/" );
                
                //see if the file is already downloaded
                if(!file_exists( $temp_file . "/" . basename((string) $link) )){
                    file_put_contents(
                        $temp_file . "/" . basename((string) $link),
                        file_get_contents($link, false, $web_context)
                    );

                    if ($zip->open($temp_file . "/" . basename((string) $link)) == TRUE) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = basename($zip->getNameIndex($i));
                            if (stristr($filename, ".xml") && str_starts_with($filename, "U_")) {
                                if (!file_exists("{$destination_dir}/{$filename}")) {
                                    echo "\t{$filename}\n";
                                    file_put_contents(
                                        "{$destination_dir}/{$filename}",
                                        $zip->getFromIndex($i)
                                    );
                                }
                            }
                        }
                    } else {
                        echo "could not open\n";
                    }
                    $zip->close();
                }

            }
        }
        curl_close($session);
        
        return new Response(
            "Done.",
            Response::HTTP_OK,
            ['content-type' => 'text/html']
        );

    }
}  
