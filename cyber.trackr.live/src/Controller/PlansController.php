<?php

namespace App\Controller;

use App\Service\OverlayLoader;
use App\Service\Plans\ControlResolver;
use App\Service\Plans\PlanBuilder;
use App\Service\Plans\PlanRegistry;
use App\Service\Plans\Renderer\DocxRenderer;
use App\Service\Plans\Renderer\HtmlRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plan-generation pages. Schema-driven: discovering a new family is
 * automatic via PlanRegistry, so a fresh resources/data/plans/ac.json
 * gets a working /plans/ac wizard with no code change.
 */
class PlansController extends AbstractController
{
    #[Route('/plans', name: 'plans')]
    public function index(PlanRegistry $registry): Response
    {
        return $this->render('plans/index.html.twig', [
            'plans' => $registry->availablePlans(),
        ]);
    }

    #[Route('/plans/{family}', name: 'plans_wizard', requirements: ['family' => '[a-z]{2,3}'])]
    public function wizard(string $family, PlanRegistry $registry, OverlayLoader $overlays): Response
    {
        if (!$registry->has($family)) {
            throw $this->createNotFoundException("No plan generator for family: $family");
        }

        return $this->render('plans/wizard.html.twig', [
            'family'   => strtolower($family),
            'schema'   => $registry->getSchema($family),
            'shared'   => $registry->getShared(),
            'overlays' => $overlays->getOverlays(),
        ]);
    }

    /**
     * Exposes the per-family schema as JSON for the wizard JS, which
     * uses it to drive conditional show_when logic and to validate
     * uploaded BYOJSON drafts.
     */
    #[Route('/plans/{family}/schema.json', name: 'plans_schema', requirements: ['family' => '[a-z]{2,3}'])]
    public function schema(string $family, PlanRegistry $registry): JsonResponse
    {
        if (!$registry->has($family)) {
            throw $this->createNotFoundException("No plan generator for family: $family");
        }
        return new JsonResponse([
            'shared' => $registry->getShared(),
            'family' => $registry->getSchema($family),
        ]);
    }

    /**
     * Returns an HTML fragment of per-control accordion cards for the
     * given family + baseline. The wizard JS fetches this when the
     * baseline dropdown changes and swaps the section-4 body content.
     * Heavy work (XML walk, CCI inverted-map build) lives in
     * ControlResolver; this is just a thin renderer.
     */
    #[Route('/plans/{family}/controls/{baseline}', name: 'plans_controls', requirements: ['family' => '[a-z]{2,3}', 'baseline' => '[a-z0-9\-]+'])]
    public function controls(
        string $family,
        string $baseline,
        PlanRegistry $registry,
        ControlResolver $resolver,
    ): Response {
        if (!$registry->has($family)) {
            throw $this->createNotFoundException("No plan generator for family: $family");
        }

        $controls = $resolver->resolve(strtoupper($family), $baseline);
        $schema   = $registry->getSchema($family) ?? [];
        $shared   = $registry->getShared();

        // Inline per-control guidance from the schema onto the resolver
        // output so the fragment template doesn't need to do dictionary
        // lookups itself.
        $guidance = $schema['per_control_guidance'] ?? [];
        foreach ($controls as &$c) {
            $c['guidance'] = $guidance[$c['number']] ?? [];
        }
        unset($c);

        return $this->render('plans/_control_cards.html.twig', [
            'family'           => strtolower($family),
            'controls'         => $controls,
            'control_questions'=> $shared['control_questions'] ?? [],
        ]);
    }

    #[Route('/plans/{family}/preview', name: 'plans_preview', methods: ['POST'], requirements: ['family' => '[a-z]{2,3}'])]
    public function preview(
        string $family,
        Request $request,
        PlanRegistry $registry,
        PlanBuilder $builder,
        HtmlRenderer $renderer,
    ): Response {
        if (!$registry->has($family)) {
            throw $this->createNotFoundException("No plan generator for family: $family");
        }

        $payload = $this->extractAnswers($request);
        $plan = $builder->build($family, $payload);

        return new Response($renderer->render($plan));
    }

    #[Route('/plans/{family}/download', name: 'plans_download', methods: ['POST'], requirements: ['family' => '[a-z]{2,3}'])]
    public function download(
        string $family,
        Request $request,
        PlanRegistry $registry,
        PlanBuilder $builder,
        DocxRenderer $renderer,
    ): Response {
        if (!$registry->has($family)) {
            throw $this->createNotFoundException("No plan generator for family: $family");
        }

        $payload = $this->extractAnswers($request);
        $plan = $builder->build($family, $payload);

        // Filename: "<system-acronym-or-family>-<family>-plan.docx"
        $stem = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($payload['system']['system_acronym'] ?? $payload['system']['system_name'] ?? $family)));
        $stem = trim($stem, '-') ?: $family;
        $filename = $stem . '-' . $family . '-plan.docx';

        // StreamedResponse + writer-to-php://output keeps memory flat AND
        // avoids the shared-hosting gotcha where a downstream gzip layer
        // recompresses an already-zip'd .docx without updating Content-Length.
        // Cache-Control: no-transform tells caches/proxies "do not modify
        // this body"; Content-Encoding: identity forbids gzip explicitly.
        $response = new StreamedResponse(function () use ($renderer, $plan) {
            $renderer->save($plan, 'php://output');
        });
        $response->headers->set('Content-Type',        'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control',       'no-store, no-transform');
        $response->headers->set('Content-Encoding',    'identity');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Accepts answers either as application/json POST body or as a
     * traditional form submission with a single `answers` field
     * carrying the JSON. The wizard JS uses application/json; the
     * fallback covers no-JS form posts.
     */
    private function extractAnswers(Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            return is_array($decoded) ? $decoded : [];
        }
        $raw = (string) $request->request->get('answers', '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
