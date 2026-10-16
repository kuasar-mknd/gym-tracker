<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Un courriel que Laravel met en file au lieu de l'envoyer (#PR) :
 * `Mailer::send()` rend alors ce que rend la file, l'identifiant du job, et
 * non un message envoyé. `CourrielsSansAdresseDansLeursErreursTest` vérifie
 * que `CourrielleurQuiTaitLesAdresses` le rend aussi.
 */
final class CourrielMisEnFile extends Mailable implements ShouldQueue
{
    public function build(): self
    {
        return $this->subject('Essai')->html('<p>Bonjour</p>');
    }
}
