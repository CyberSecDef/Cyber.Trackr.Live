<?php

namespace App\Controller;

use App\Service\DockerImageLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DockerController extends AbstractController
{
    #[Route('/docker-image', name: 'docker_image')]
    public function index(DockerImageLocator $locator): Response
    {
        $image = $locator->latest();
        if ($image === null) {
            throw $this->createNotFoundException('No Docker image bundle is available for download.');
        }

        return $this->render('docker/image.html.twig', [
            'image'        => $image,
            'compose_yaml' => $locator->composeYaml(),
        ]);
    }

    #[Route('/docker-image/download', name: 'docker_image_download')]
    public function download(DockerImageLocator $locator): Response
    {
        $image = $locator->latest();
        if ($image === null) {
            throw $this->createNotFoundException('No Docker image bundle is available for download.');
        }

        // Hand the ~3 GB file to the web server (review P3). Streamed through
        // PHP it ran under mod_fcgid on prod, which killed slow transfers:
        // 1 of 18 downloads in two weeks finished. public/downloads/docker is
        // a symlink to dist/, so Apache serves it natively with Range support
        // (resumable). The rate limiter still counts this route.
        return new RedirectResponse('/downloads/docker/' . rawurlencode($image['filename']), Response::HTTP_FOUND);
    }
}
