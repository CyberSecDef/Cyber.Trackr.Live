<?php

namespace App\Controller;

use App\Service\Vulns\VulnsRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public-facing routes for the /vulnerabilities tree.
 *
 *   /vulnerabilities                — landing
 *   /vulnerabilities/kev            — full CISA KEV catalog
 *   /vulnerabilities/kev/{cve}      — single KEV entry
 *   /vulnerabilities/iavm           — IAVMs/CTOs harvested from STIG/SCAP
 *   /vulnerabilities/iavm/{id}      — single IAVM/CTO with backlinks
 *   /vulnerabilities/cve/{cve}      — unifying CVE page (KEV + corpus)
 *
 * All data is read from the local mirrors (resources/data/kev/ and
 * resources/data/vulns_toc.json). No live HTTP calls happen at request
 * time; refresh is via app:kev:refresh and app:vulns:rebuild-toc.
 */
class VulnsController extends AbstractController
{
    #[Route('/vulnerabilities', name: 'vulns')]
    public function landing(VulnsRegistry $registry): Response
    {
        return $this->render('vulns/index.html.twig', [
            'summary'          => $registry->summary(),
            'related_controls' => VulnsRegistry::RELATED_CONTROLS,
        ]);
    }

    #[Route('/vulnerabilities/kev', name: 'vulns_kev_list')]
    public function kevList(VulnsRegistry $registry): Response
    {
        $entries = $registry->listKev();

        // Sort newest-added first so the catalog reads chronologically.
        usort($entries, fn($a, $b) => strcmp(
            (string) ($b['dateAdded'] ?? ''),
            (string) ($a['dateAdded'] ?? '')
        ));

        return $this->render('vulns/kev_list.html.twig', [
            'entries'  => $entries,
            'kev_meta' => $registry->kev()->meta(),
        ]);
    }

    #[Route('/vulnerabilities/kev/{cve}', name: 'vulns_kev_view', requirements: ['cve' => 'CVE-\d{4}-\d{4,7}'])]
    public function kevView(string $cve, VulnsRegistry $registry): Response
    {
        $entry = $registry->getKev($cve);
        if ($entry === null) {
            throw $this->createNotFoundException("CVE {$cve} is not in the CISA KEV catalog.");
        }
        // Pull the same CVE through the unified view so we can show STIG/SCAP
        // backlinks alongside the KEV record.
        $unified = $registry->getCve($cve);

        return $this->render('vulns/kev_detail.html.twig', [
            'cve'              => strtoupper($cve),
            'entry'            => $entry,
            'rules'            => $unified['rules'] ?? [],
            'iavms'            => $unified['iavms'] ?? [],
            'ctos'             => $unified['ctos'] ?? [],
            'related_controls' => VulnsRegistry::RELATED_CONTROLS,
        ]);
    }

    #[Route('/vulnerabilities/iavm', name: 'vulns_iavm_list')]
    public function iavmList(VulnsRegistry $registry): Response
    {
        return $this->render('vulns/iavm_list.html.twig', [
            'iavms'           => $registry->listIavms(),
            'ctos'            => $registry->listCtos(),
            'prose_count'     => count($registry->proseMentionRules()),
            'toc_generated_at' => $registry->tocGeneratedAt(),
            'toc_stats'       => $registry->tocStats(),
        ]);
    }

    #[Route(
        '/vulnerabilities/iavm/{id}',
        name: 'vulns_iavm_view',
        requirements: ['id' => '20\d{2}-[ABT]-\d{4}|CTO\d{4}']
    )]
    public function iavmView(string $id, VulnsRegistry $registry): Response
    {
        $entry = $registry->getIavm($id);
        if ($entry === null) {
            throw $this->createNotFoundException("No bulletin {$id} found in the corpus.");
        }
        return $this->render('vulns/iavm_detail.html.twig', [
            'entry'            => $entry,
            'related_controls' => VulnsRegistry::RELATED_CONTROLS,
        ]);
    }

    #[Route(
        '/vulnerabilities/cve/{cve}',
        name: 'vulns_cve_view',
        requirements: ['cve' => 'CVE-\d{4}-\d{4,7}']
    )]
    public function cveView(string $cve, VulnsRegistry $registry): Response
    {
        $entry = $registry->getCve($cve);
        if ($entry === null) {
            throw $this->createNotFoundException("CVE {$cve} is not in KEV and not referenced by any STIG or SCAP rule in the corpus.");
        }
        return $this->render('vulns/cve_detail.html.twig', [
            'entry'            => $entry,
            'related_controls' => VulnsRegistry::RELATED_CONTROLS,
        ]);
    }
}
