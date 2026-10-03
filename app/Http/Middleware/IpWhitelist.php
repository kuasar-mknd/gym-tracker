<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class IpWhitelist
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::admet($request)) {
            return $next($request);
        }

        if (app()->isProduction()) {
            abort(404);
        }

        abort(403, 'Your IP address ('.$request->ip().') is not authorized to access this area.');
    }

    /**
     * L'adresse de la requête est-elle admise par `ADMIN_ALLOWED_IPS` ?
     *
     * La même règle pour le panneau et pour ce qui s'ouvre à ses
     * administrateurs hors de lui (Horizon) : une seule écriture, sans quoi les
     * deux portes finiraient par diverger.
     *
     * En production, une liste vide ferme : le laisser ouvert au monde par
     * oubli d'une variable serait l'inverse d'une liste blanche. Hors
     * production, elle laisse passer le poste de développement. Adresses
     * exactes ou plages CIDR, IPv4 et IPv6 : un réseau local ou privé ne se
     * liste pas appareil par appareil.
     *
     * Statique et sans état : la configuration est lue à chaque appel, dans le
     * conteneur de la requête en cours, ce qui convient aussi au rappel
     * d'Horizon, posé une fois par worker Octane.
     */
    public static function admet(Request $request): bool
    {
        $adressesAdmises = (array) config('app.admin_allowed_ips', []);

        if ($adressesAdmises === []) {
            return ! app()->isProduction();
        }

        return IpUtils::checkIp((string) $request->ip(), array_values(array_filter($adressesAdmises, is_string(...))));
    }
}
