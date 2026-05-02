<?php

namespace App\Controller;

use App\Service\Search\Searcher;
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
    public function search(Searcher $searcher, $query = ""): Response
    {
        $results = $searcher->search((string) $query);

        return $this->render('home/search.html.twig', [
            'controller_name' => 'HomeController',
            'query' => $query,
            'terms' => [],
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

    #[Route('/ckl-viewer', name: 'ckl_viewer')]
    public function ckl_viewer(): Response
    {
        return $this->render('home/ckl_viewer.html.twig', [
            'controller_name' => 'HomeController'
        ]);
    }

    #[Route('/changelog', name: 'changelog')]
    public function changelog(\App\Service\Changelog $changelog): Response
    {
        return $this->render('home/changelog.html.twig', [
            'entries' => $changelog->getEntries(),
        ]);
    }
}
