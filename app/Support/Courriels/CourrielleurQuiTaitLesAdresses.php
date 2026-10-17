<?php

declare(strict_types=1);

namespace App\Support\Courriels;

use App\Support\TexteSansAdresse;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage as SentMessageDeSymfony;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Le courrielleur de Laravel, dont les erreurs ne citent pas d'adresse
 * (#2076). `GestionnaireDesCourriels` le crée pour chaque courrielleur.
 *
 * Deux erreurs d'un envoi en citaient une. Celle du transport recopie la
 * réponse du serveur de courriel, qui nomme souvent le destinataire qu'il
 * refuse : celui d'un lien « mot de passe oublié », ou l'adresse tapée à
 * l'inscription, peut-être celle d'un tiers. Celle de Symfony Mime cite
 * l'adresse qu'il refuse d'écrire. Le job qui envoie est chiffré dans la
 * file, mais son échec ne l'est pas : le message partait dans le journal, la
 * table des exceptions du panneau, `failed_jobs` et les jobs échoués de
 * Horizon.
 *
 * L'erreur est donc relevée neuve, au même endroit, avec le même code (celui
 * de la réponse du serveur) et la même classe (une `TransportException` pour
 * un transport qui en lève une autre), et son message passé par
 * `TexteSansAdresse`, les destinataires du courriel en plus : la réponse du
 * serveur reste lisible, sans adresse. Sans exception précédente, qui
 * garderait l'original, ni transcription de l'échange (`getDebug()`), qui
 * porte « RCPT TO:<…> ».
 */
final class CourrielleurQuiTaitLesAdresses extends Mailer
{
    /**
     * Envoie comme Laravel ; une adresse que Symfony Mime refuse n'est pas
     * citée par l'erreur. Rend ce que rend Laravel, sans type natif : pour
     * un courriel à mettre en file, ce que rend la file (l'identifiant du
     * job), que son type déclaré tait.
     *
     * @param  \Illuminate\Contracts\Mail\Mailable|string|array<array-key, mixed>  $view
     * @param  array<array-key, mixed>  $data
     * @param  \Closure|string|null  $callback
     * @return SentMessage|null
     */
    #[\Override]
    public function send($view, array $data = [], $callback = null)
    {
        try {
            return parent::send($view, $data, $callback);
        } catch (RfcComplianceException $erreur) {
            throw new RfcComplianceException(TexteSansAdresse::de($erreur->getMessage()), $erreur->getCode());
        }
    }

    /**
     * Remet le courriel au transport ; son erreur ne cite ni adresse, ni
     * destinataire.
     */
    #[\Override]
    protected function sendSymfonyMessage(Email $message): ?SentMessageDeSymfony
    {
        try {
            return parent::sendSymfonyMessage($message);
        } catch (TransportExceptionInterface $erreur) {
            $texte = TexteSansAdresse::de($erreur->getMessage(), self::destinatairesDe($message));

            throw $erreur instanceof UnexpectedResponseException
                ? new UnexpectedResponseException($texte, $erreur->getCode())
                : new TransportException($texte, $erreur->getCode());
        }
    }

    /**
     * Les adresses des destinataires. Le transport n'en encode que le domaine
     * (internationalisé, en ASCII), que le masque reconnaît sous les deux
     * formes : leur partie locale suffit.
     *
     * @return list<string>
     */
    private static function destinatairesDe(Email $message): array
    {
        return array_values(array_unique(array_map(
            static fn (Address $adresse): string => $adresse->getAddress(),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        )));
    }
}
