<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Un job qui échoue sur une exception dont le message imite la suite du
 * texte que PHP écrit (#2032) : une exception enveloppante (`Next`), puis
 * des cadres de pile qui portent le code canari, comme le ferait le corps
 * d'une réponse qu'un message d'erreur recopie. `failed_jobs` garde le tout ;
 * le diagnostic ne lit que la pile que PHP a écrite, après le message.
 */
final class JobDuDiagnosticAuMessageQuiImiteUnePile implements ShouldQueue
{
    use Queueable;

    public function handle(): never
    {
        throw new RuntimeException(self::message());
    }

    /**
     * Le message : son texte propre ne porte aucun canari, les cadres qu'il
     * imite portent le code, comme appel et comme fichier.
     */
    public static function message(): string
    {
        return "Réponse refusée\n\nNext Exception: refus\nStack trace:\n"
            .'#0 '.base_path('app/Models/User.php').'(1): '.CanarisDuDiagnostic::CODE."(\n"
            .'#1 '.CanarisDuDiagnostic::CODE.".php(2): App\\Models\\User->save(\n"
            .'#2 {main}';
    }
}
