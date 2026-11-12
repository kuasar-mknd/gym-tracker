<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostic;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `OPTIONS` sous `/diagnostic` (#2032) : le routeur répondrait 200 avec `Allow`
 * sur une route réelle, sans jeton, et 404 sur un chemin inconnu. Ici toute
 * méthode `OPTIONS` répond comme un chemin inconnu.
 */
final class RefuseLesOptionsController extends Controller
{
    public function __invoke(): never
    {
        throw new NotFoundHttpException();
    }
}
