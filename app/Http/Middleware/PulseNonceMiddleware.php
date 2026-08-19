<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PulseNonceMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->bound('csp-nonce')) {
            /*
             * Le conteneur rend `mixed` : concatener directement produisait
             * deux erreurs masquees, et un nonce non textuel aurait ecrit un
             * attribut invalide dans la page plutot que d'echouer.
             */
            $nonce = app('csp-nonce');

            if (! is_string($nonce)) {
                return $response;
            }
            $content = $response->getContent();
            if (is_string($content)) {
                $content = str_replace('<script>', '<script nonce="'.$nonce.'">', $content);
                $content = str_replace('<style>', '<style nonce="'.$nonce.'">', $content);
                $response->setContent($content);
            }
        }

        return $response;
    }
}
