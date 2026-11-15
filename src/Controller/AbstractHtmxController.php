<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractHtmxController extends AbstractController
{
    /**
     * @param array<string, mixed> $parameters
     */
    protected function redirectOrHtmx(Request $request, string $route, array $parameters = []): Response
    {
        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl($route, $parameters),
            ]);
        }

        return $this->redirectToRoute($route, $parameters);
    }
}
