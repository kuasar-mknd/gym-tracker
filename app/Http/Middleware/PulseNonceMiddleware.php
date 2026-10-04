<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * N'est plus branché nulle part : ne pas le rebrancher.
 *
 * Il signait les balises de Pulse en réécrivant toute la réponse, motif que
 * `.ai/rules/middleware.md` interdit, et réécrivait aussi le texte `<script>`
 * que livewire.js contient : un nonce glissé dans une chaîne cassait sa
 * syntaxe, et la page de Pulse restait sans Livewire ni Alpine. Les balises de
 * Pulse sont désormais signées à la compilation de ses gabarits
 * (`SigneLesScriptsEnLigneDesPaquets`). La classe et son test attendent
 * l'accord du propriétaire du dépôt pour être supprimés.
 */
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
