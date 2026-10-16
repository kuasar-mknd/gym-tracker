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
 * (#PR). `GestionnaireDesCourriels` le crée pour chaque courrielleur.
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
     * citée par l'erreur.
     *
     * @param  \Illuminate\Contracts\Mail\Mailable|string|array<array-key, mixed>  $view
     * @param  array<array-key, mixed>  $data
     * @param  \Closure|string|null  $callback
     */
    #[\Override]
    public function send($view, array $data = [], $callback = null): ?SentMessage
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
     * Les adresses des destinataires, telles que le courriel les porte et
     * telles que le transport les écrit (domaine internationalisé en ASCII).
     *
     * @return list<string>
     */
    private static function destinatairesDe(Email $message): array
    {
        $adresses = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];

        return array_values(array_unique([
            ...array_map(static fn (Address $adresse): string => $adresse->getAddress(), $adresses),
            ...array_map(static fn (Address $adresse): string => $adresse->getEncodedAddress(), $adresses),
        ]));
    }
}
