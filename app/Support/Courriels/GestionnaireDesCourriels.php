<?php

declare(strict_types=1);

namespace App\Support\Courriels;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as FabriqueDesFiles;
use Illuminate\Contracts\View\Factory as FabriqueDesVues;
use Illuminate\Mail\MailManager;

/**
 * Le gestionnaire des courriels de Laravel, dont chaque courrielleur est un
 * `CourrielleurQuiTaitLesAdresses` (#2076). `AppServiceProvider` le pose à la
 * place de celui de Laravel.
 *
 * `build()` reprend celle de Laravel, qui écrit la classe en dur, à la
 * classe près : même nom, mêmes vues, même transport, mêmes évènements, même
 * file. `CourrielsSansAdresseDansLeursErreursTest` compare chaque propriété
 * des deux courrielleurs : une version de Laravel qui en poserait une de plus
 * le ferait échouer.
 */
final class GestionnaireDesCourriels extends MailManager
{
    /**
     * Un courrielleur, comme Laravel le crée.
     *
     * @param  array<array-key, mixed>  $config
     */
    #[\Override]
    public function build($config): CourrielleurQuiTaitLesAdresses
    {
        $courrielleur = new CourrielleurQuiTaitLesAdresses(
            is_string($config['name'] ?? null) ? $config['name'] : 'ondemand',
            $this->app->make(FabriqueDesVues::class),
            $this->createSymfonyTransport($config),
            $this->app->make(Dispatcher::class),
        );

        if ($this->app->bound('queue')) {
            $courrielleur->setQueue($this->app->make(FabriqueDesFiles::class));
        }

        return $courrielleur;
    }
}
