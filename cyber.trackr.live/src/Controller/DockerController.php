<?php

namespace App\Controller;

use App\Service\DockerImageLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

        $response = new BinaryFileResponse($image['path']);
        $response->headers->set('Content-Type', 'application/gzip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $image['filename']
        );

        return $response;
    }
}
