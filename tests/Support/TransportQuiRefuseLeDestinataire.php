<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Un transport dont le serveur refuse le destinataire, et le cite (#PR).
 *
 * L'erreur a la forme exacte de celle de Symfony
 * (`SmtpTransport::assertResponseCode()`), qui recopie la réponse du
 * serveur : « Expected response code "250/251/252" but got code "550",
 * with message "…" ». Le serveur y cite l'adresse entière, entre chevrons
 * (`adresse`), ou sa seule partie locale (`partie-locale`, « ada... User
 * unknown »). La transcription de l'échange, que Symfony joint à l'erreur
 * (`getDebug()`), porte « RCPT TO:<…> ».
 */
final class TransportQuiRefuseLeDestinataire extends AbstractTransport
{
    public function __construct(private readonly string $forme = 'adresse')
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'refus://';
    }

    protected function doSend(SentMessage $message): void
    {
        $destinataire = $message->getEnvelope()->getRecipients()[0]->getAddress();
        $reponse = $this->forme === 'partie-locale'
            ? '550 5.1.1 '.Str::beforeLast($destinataire, '@').'... User unknown'
            : "550 5.1.1 <{$destinataire}>: Recipient address rejected: User unknown in local recipient table";

        $erreur = new UnexpectedResponseException(sprintf('Expected response code "250/251/252" but got code "550", with message "%s".', $reponse), 550);
        $erreur->appendDebug("> RCPT TO:<{$destinataire}>\r\n< {$reponse}\r\n");

        throw $erreur;
    }
}
