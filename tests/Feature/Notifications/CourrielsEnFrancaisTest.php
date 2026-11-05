<?php

declare(strict_types=1);

use App\Enums\MotifDeLaTentative;
use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use App\Notifications\LienDeChangementDAdresse;
use App\Notifications\LienDOuvertureDuCompte;
use App\Notifications\TentativeSurTonAdresse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Vouvoiement;

/*
 * Le gabarit publié des courriels termine par « All rights reserved. », que
 * `lang/fr.json` ne traduisait pas : les courriels de vérification de
 * l'adresse et de réinitialisation du mot de passe étaient en français, sauf
 * leur dernière ligne (#1978). Les deux rendus, HTML et texte, sont lus ici
 * tels que le destinataire les reçoit. L'objet aussi : le framework a changé
 * ses clefs (« Verify your email address », « Reset your password »), et la
 * première ligne lue dans la boîte de réception restait anglaise.
 *
 * Les courriels tutoient, comme le reste de l'application (#1980). Ceux de
 * l'application le faisaient, mais chaque courriel à bouton finissait par le
 * lien de secours du gabarit, traduit au vouvoiement (« Si vous rencontrez
 * des problèmes pour cliquer… »), et les deux courriels de Laravel encore
 * envoyés vouvoyaient de l'objet au pied (« Réinitialisez votre mot de
 * passe », « Veuillez cliquer… »), en saluant d'un « Bonjour ! » quand les
 * autres écrivaient « Bonjour, » (#2118). Chaque courriel que l'application
 * envoie est rendu ici, dans chacune de ses variantes ; la liste des avis
 * vient du code, et un avis ajouté sans être rendu ici fait tomber la suite.
 *
 * Les courriels de l'exploitant n'en sont pas : l'alerte de santé et les avis
 * des sauvegardes partent aux adresses de la configuration
 * (`HEALTH_TO_ADDRESS`, `BACKUP_NOTIFICATION_EMAIL`), jamais à la personne
 * qui s'entraîne, et ne vouvoient pas davantage.
 */

/**
 * Chaque courriel que l'application envoie, dans chacune de ses variantes,
 * prêt à rendre, avec la classe de l'avis qui le porte.
 *
 * @return array<string, array{avis: class-string, courriel: Closure(): MailMessage}>
 */
function courrielsEnFrancaisAEnvoyer(): array
{
    $courriels = [
        'vérification de l’adresse' => [
            'avis' => VerifyEmail::class,
            'courriel' => static fn (): MailMessage => courrielsEnFrancaisLeMessage(new VerifyEmail()->toMail(User::factory()->unverified()->create())),
        ],
        'réinitialisation du mot de passe' => [
            'avis' => ResetPassword::class,
            'courriel' => static fn (): MailMessage => courrielsEnFrancaisLeMessage(new ResetPassword('jeton-de-test')->toMail(User::factory()->create())),
        ],
        'ouverture du compte' => [
            'avis' => LienDOuvertureDuCompte::class,
            'courriel' => static fn (): MailMessage => new LienDOuvertureDuCompte()->toMail(User::factory()->enAttente()->create()),
        ],
        'confirmation d’une nouvelle adresse' => [
            'avis' => LienDeChangementDAdresse::class,
            'courriel' => static fn (): MailMessage => new LienDeChangementDAdresse('https://example.org/profil/adresse')->toMail(Notification::route('mail', 'nouvelle@example.org')),
        ],
        'adresse du compte changée' => [
            'avis' => AdresseDuCompteChangee::class,
            'courriel' => static fn (): MailMessage => new AdresseDuCompteChangee('nouvelle@example.org')->toMail(Notification::route('mail', 'camille@example.org')),
        ],
        'adresse du compte changée, masquée comme celle qui est prévenue' => [
            'avis' => AdresseDuCompteChangee::class,
            'courriel' => static fn (): MailMessage => new AdresseDuCompteChangee('nouvelle@example.org')->toMail(Notification::route('mail', 'nadia@exemple.org')),
        ],
    ];

    foreach (MotifDeLaTentative::cases() as $motif) {
        foreach (['ouvert' => false, 'en attente' => true] as $etat => $enAttente) {
            $courriels["tentative sur l’adresse ({$motif->value}, compte {$etat})"] = [
                'avis' => TentativeSurTonAdresse::class,
                'courriel' => static fn (): MailMessage => new TentativeSurTonAdresse($motif)->toMail(
                    $enAttente ? User::factory()->enAttente()->create() : User::factory()->create(),
                ),
            ];
        }
    }

    return $courriels;
}

/**
 * Le message que rend `toMail()`, que Laravel 13.38 déclare
 * `MailMessage|Mailable` (un rappel `toMailUsing()` peut rendre un Mailable) :
 * les deux courriels de Laravel que l'application envoie sont des
 * `MailMessage`.
 */
function courrielsEnFrancaisLeMessage(MailMessage|Mailable $courriel): MailMessage
{
    expect($courriel)->toBeInstanceOf(MailMessage::class);
    assert($courriel instanceof MailMessage);

    return $courriel;
}

/**
 * Le courriel tel que le destinataire le lit : en HTML et en texte, entités
 * décodées.
 *
 * @return array{html: string, texte: string}
 */
function courrielsEnFrancaisRendus(MailMessage $message): array
{
    return [
        'html' => html_entity_decode((string) $message->render(), ENT_QUOTES | ENT_HTML5),
        'texte' => html_entity_decode(
            (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data()),
            ENT_QUOTES | ENT_HTML5,
        ),
    ];
}

/**
 * Les avis que l'application envoie par courriel, tirés du code : chaque avis
 * de `app/Notifications` qui se rend en courriel, et ceux que le compte fait
 * partir par ses méthodes `send…Notification()`, dont deux viennent de
 * Laravel (`ResetPassword`, `VerifyEmail`), pour un compte vérifié, non
 * vérifié et en attente.
 *
 * @return list<string>
 */
function courrielsEnFrancaisAvisEnvoyesParCourriel(): array
{
    $avis = [];
    $fichiers = glob(app_path('Notifications/*.php'));

    foreach (is_array($fichiers) ? $fichiers : [] as $chemin) {
        $classe = 'App\\Notifications\\'.basename($chemin, '.php');

        if (method_exists($classe, 'toMail')) {
            $avis[] = $classe;
        }
    }

    $faux = Notification::fake();
    $envoyeurs = array_filter(
        get_class_methods(User::class),
        static fn (string $methode): bool => preg_match('/^send\w*Notification$/', $methode) === 1,
    );

    expect($envoyeurs)->not->toBeEmpty();

    foreach ([User::factory()->create(), User::factory()->unverified()->create(), User::factory()->enAttente()->create()] as $compte) {
        foreach ($envoyeurs as $envoyeur) {
            $arguments = array_fill(0, new ReflectionMethod($compte, $envoyeur)->getNumberOfRequiredParameters(), 'jeton-de-test');
            $compte->{$envoyeur}(...$arguments);
        }
    }

    /** @var array<string, array<string, array<string, mixed>>> $envoyes */
    $envoyes = $faux->sentNotifications();

    foreach ($envoyes as $parCompte) {
        foreach ($parCompte as $parAvis) {
            array_push($avis, ...array_keys($parAvis));
        }
    }

    $avis = array_values(array_unique($avis));
    sort($avis);

    return $avis;
}

it('rend chaque courriel que l’application envoie', function (): void {
    $rendus = array_values(array_unique(array_column(courrielsEnFrancaisAEnvoyer(), 'avis')));
    sort($rendus);

    expect(courrielsEnFrancaisAvisEnvoyesParCourriel())
        ->toContain(ResetPassword::class, VerifyEmail::class, LienDOuvertureDuCompte::class)
        ->toBe($rendus, 'ajoute à courrielsEnFrancaisAEnvoyer() chaque courriel que l’application envoie');
});

it('rend chaque courriel en français, pied compris', function (string $courriel): void {
    $message = courrielsEnFrancaisAEnvoyer()[$courriel]['courriel']();

    foreach (courrielsEnFrancaisRendus($message) as $contenu) {
        expect($contenu)->toContain('Tous droits réservés.', 'Bonjour,', 'Cordialement,');

        foreach (['All rights', 'Regards', 'Hello', 'Bonjour !'] as $autre) {
            expect($contenu)->not->toContain($autre);
        }
    }
})->with(array_keys(courrielsEnFrancaisAEnvoyer()));

it('tutoie dans chaque courriel, objet, texte et HTML compris', function (string $courriel): void {
    $message = courrielsEnFrancaisAEnvoyer()[$courriel]['courriel']();

    foreach (['objet' => (string) $message->subject, ...courrielsEnFrancaisRendus($message)] as $partie => $contenu) {
        expect(Vouvoiement::mots($contenu))->toBe([], "{$courriel}, {$partie}");
    }
})->with(array_keys(courrielsEnFrancaisAEnvoyer()));

it('dit le lien de secours du bouton en tutoyant', function (): void {
    $message = courrielsEnFrancaisAEnvoyer()['ouverture du compte']['courriel']();

    foreach (courrielsEnFrancaisRendus($message) as $contenu) {
        expect($contenu)->toContain('Si le bouton « Ouvrir mon compte » ne marche pas, copie ce lien dans ton navigateur :');
    }
});

it('donne à chaque courriel un objet français', function (string $courriel, string $objet): void {
    $message = courrielsEnFrancaisAEnvoyer()[$courriel]['courriel']();

    expect($message->subject)->toBe($objet);

    foreach (['Verify', 'Reset', 'email'] as $anglais) {
        expect($message->subject)->not->toContain($anglais);
    }
})->with([
    'vérification de l’adresse' => ['vérification de l’adresse', 'Vérifie ton adresse e-mail'],
    'réinitialisation du mot de passe' => ['réinitialisation du mot de passe', 'Choisis un nouveau mot de passe'],
]);

it('reconnaît le vouvoiement qu’il refuse, et laisse passer le tutoiement', function (): void {
    expect(Vouvoiement::mots('Réinitialisez votre mot de passe'))->toBe(['Réinitialisez', 'votre'])
        ->and(Vouvoiement::mots('Veuillez cliquer sur le bouton ci-dessous pour vérifier votre adresse e-mail.'))->toBe(['Veuillez', 'votre'])
        ->and(Vouvoiement::mots('Si vous rencontrez des problèmes, copiez et collez l’URL dans VOTRE navigateur.'))->toBe(['vous', 'rencontrez', 'copiez', 'collez', 'VOTRE'])
        ->and(Vouvoiement::mots('Êtes-vous sûr ? Vos séances, la vôtre.'))->toBe(['Êtes', 'vous', 'Vos', 'vôtre'])
        ->and(Vouvoiement::mots('Si tu n’as rien demandé, tu n’as rien à faire. Copie ce lien dans ton navigateur, chez toi, assez vite.'))->toBe([])
        ->and(Vouvoiement::mots('https://example.org/vosges?nez=1&vousoir'))->toBe([]);
});
