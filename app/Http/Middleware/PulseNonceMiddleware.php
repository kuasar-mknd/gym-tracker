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
            $nonce = app('csp-nonce');
            $content = $response->getContent();
            if (is_string($content)) {
                $content = str_replace('<script>', '<script nonce="' . $nonce . '">', $content);
                $content = str_replace('<style>', '<style nonce="' . $nonce . '">', $content);
                $response->setContent($content);
            }
        }

        return $response;
    }
}
