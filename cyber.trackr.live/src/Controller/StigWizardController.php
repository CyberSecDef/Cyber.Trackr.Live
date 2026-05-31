<?php

namespace App\Controller;

use App\Service\Stig\StigWizardRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interview-style STIG wizard (GitHub issue #15). Schema-driven: the page is
 * rendered entirely from the matching resources/data/stig-wizard/<key>.json,
 * and all answer-to-CKLB logic runs client-side in public/js/stig_wizard.js.
 * The wizard fetches the blank skeleton from StigController::stig_skeleton_cklb,
 * applies the user's answers in the browser, and hands the result to the
 * CKL/CKLB viewer via sessionStorage. The user's answers never reach the server.
 */
class StigWizardController extends AbstractController
{
    #[Route('/stig-wizard/{title}/{version}/{release}', name: 'stig_wizard')]
    public function wizard(string $title, string $version, string $release, StigWizardRegistry $registry): Response
    {
        $match = $registry->findForStig($title, $version);
        if ($match === null) {
            throw $this->createNotFoundException('No wizard is available for this STIG.');
        }

        return $this->render('stig/wizard.html.twig', [
            'wizard_key' => $match['key'],
            'schema'     => $match['schema'],
            'title'      => $title,
            'version'    => $version,
            'release'    => $release,
        ]);
    }
}
