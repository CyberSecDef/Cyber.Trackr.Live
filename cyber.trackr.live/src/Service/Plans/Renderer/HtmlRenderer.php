<?php

namespace App\Service\Plans\Renderer;

use Twig\Environment;

/**
 * Renders a Plan structure (from PlanBuilder) to HTML via the
 * templates/plans/preview.html.twig template. The renderer is a thin
 * wrapper around the Twig environment - all formatting decisions live
 * in the template so designers can iterate without touching PHP.
 */
class HtmlRenderer
{
    public function __construct(private Environment $twig) {}

    public function render(array $plan): string
    {
        return $this->twig->render('plans/preview.html.twig', [
            'plan' => $plan,
        ]);
    }
}
