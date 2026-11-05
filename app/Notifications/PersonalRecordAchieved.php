<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PersonalRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Annonce un record personnel que la synchronisation d'une série vient
 * d'établir.
 *
 * Supprimée sans partir quand le record a disparu avant son envoi
 * (`#[DeleteWhenMissingModels]`, #2109) : la série qui le tenait a été
 * supprimée, et sa suppression a retiré le record que plus rien ne tient, ou
 * le compte l'a été. Il n'y a plus de record à annoncer, et Laravel, qui
 * levait `ModelNotFoundException` en restaurant le record, envoyait sinon
 * l'avis dans `failed_jobs`. Le destinataire, lui, se restaure en collection :
 * un compte supprimé ne fait rien échouer, l'avis ne part à personne.
 *
 * Le record, lui, survit souvent à ce qui le rendait vrai (#2155). L'avis
 * attend en file derrière la synchronisation qui l'a établi ; quand la série
 * est supprimée ou corrigée entre-temps et qu'une autre série tient encore le
 * record, la reconstruction réécrit la même ligne à la valeur d'avant, et
 * l'avis, qui ne portait que la ligne, partait avec elle : « … avec 90 kg »
 * pour un record de 90 kg déjà annoncé. Il retient donc la série qui a établi
 * le record, la valeur qu'elle y a écrite et celle que le record portait avant
 * elle, et juge le record tel que la file le relit à son départ
 * (`shouldSend()`).
 */
#[DeleteWhenMissingModels]
final class PersonalRecordAchieved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Le titre de la notification, au centre de notifications comme en push.
     */
    private const string TITRE = 'Nouveau record ! 🏆';

    /**
     * La série qui tenait le record quand l'avis a été construit : celle que
     * `PersonalRecordService::update()` vient d'y écrire ; null pour un record
     * sans série.
     *
     * Un avis mis en file avant #2155 ne porte ni elle, ni `$valeurEcrite`, ni
     * `$valeurDAvant`, et la file le restaure sans passer par le constructeur :
     * toutes trois restent alors non initialisées. Elles ne se lisent donc que
     * par `isset()` (`shouldSend()`), qui les tient pour absentes sans lever ;
     * une lecture directe ferait échouer l'envoi d'un tel avis.
     */
    private ?int $serie;

    /**
     * La valeur que la série vient d'écrire sur le record, quand l'avis a été
     * construit. Une autre série qui tient le record plus haut l'a battue, et
     * sa propre synchronisation l'annonce.
     */
    private float $valeurEcrite;

    /**
     * @param  \App\Models\PersonalRecord  $personalRecord  Le record que la série vient d'établir, tel que la synchronisation l'a écrit.
     * @param  float|null  $valeurDAvant  La valeur que ce record portait avant que la série ne le batte ; null quand elle a établi le premier.
     */
    public function __construct(public PersonalRecord $personalRecord, private ?float $valeurDAvant = null)
    {
        $this->serie = $personalRecord->set_id;
        $this->valeurEcrite = (float) $personalRecord->value;
    }

    /**
     * Ne laisse partir l'avis que si le record, tel qu'il est à son départ,
     * reste à annoncer (#2155).
     *
     * Laravel pose la question au moment de l'envoi, une fois par canal : dans
     * le worker pour un avis en file (`NotificationSender::shouldSendNotification()`,
     * atteint par `SendQueuedNotifications::handle()`), et non quand il est mis
     * en file. Le record est alors celui que la file vient de relire en base
     * pour lancer le travail (`SerializesModels`), un par canal ; un record
     * disparu a déjà fait supprimer le travail (`#[DeleteWhenMissingModels]`).
     *
     * L'avis ne part pas quand une autre série tient le record plus haut que
     * la valeur que celle-ci y avait écrite : elle l'a battue, et sa propre
     * synchronisation l'annonce (deux séries validées avant le passage du
     * worker). Il ne part pas non plus quand le record ne dépasse plus la
     * valeur qu'il portait avant la série : la personne tenait déjà ce record,
     * au même exercice, au même type, à cette valeur ou plus haut (la série
     * supprimée ou corrigée sous l'ancien record). Sinon, il part, et le
     * message dit la valeur que le record porte à l'envoi : une série corrigée
     * qui garde le record (110 kg corrigés en 100, au-dessus des 90 d'avant)
     * l'annonce à 100 kg. De même quand une série validée après elle, sans la
     * battre, reprend le record parce qu'elle a été supprimée ou corrigée sous
     * elle : cette série n'a rien battu à sa synchronisation, et le record
     * qu'elle tient, au-dessus de celui d'avant, n'est annoncé que par cet avis.
     *
     * L'avis ne sait pas comment l'autre série a pris le record. Quand une
     * reconstruction le lui donne avant sa propre synchronisation, plus haut
     * que la valeur écrite ici, celle-ci ne trouve plus rien à battre : c'est
     * la reconstruction qui annonce ce record pour elle (#2185,
     * `PersonalRecordService::reconstruire()`), et cet avis se tait.
     *
     * Un avis sans série ni valeur d'avant (mis en file avant #2155, ou construit
     * sur un record sans série) n'est jugé que sur l'existence du record, comme
     * avant.
     */
    public function shouldSend(object $_notifiable, string $_channel): bool
    {
        $valeur = (float) $this->personalRecord->value;

        if (isset($this->serie, $this->valeurEcrite) && $this->personalRecord->set_id !== $this->serie && $valeur > $this->valeurEcrite) {
            return false;
        }

        return ! isset($this->valeurDAvant) || $valeur > $this->valeurDAvant;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $_notifiable): array
    {
        $channels = ['database'];

        /** @var \App\Models\User $_notifiable */
        if ($_notifiable->notificationsPoussesActivees('personal_record')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @param  mixed  $_notification
     */
    public function toWebPush(object $_notifiable, $_notification): WebPushMessage
    {
        return new WebPushMessage()
            ->title(self::TITRE)
            ->icon('/pwa-192x192.png')
            /** @phpstan-ignore-next-line */
            ->body((string) ($this->toArray($_notifiable)['message'] ?? ''))
            ->action('Voir mes stats', url('/stats'))
            ->data(['url' => '/stats']);
    }

    /**
     * Le message est composé ici, et le centre de notifications comme le push
     * l'affichent tel quel : la valeur suit donc le format que
     * `resources/js/Utils/nombre.js` donne aux poids dans le reste de
     * l'application (`poidsEnTexte()`), et non le `decimal:2` de la colonne,
     * qui écrivait « 102.50kg ».
     *
     * @return array<string, \Illuminate\Support\Carbon|int|string|bool|float|array<int, mixed>|null>
     */
    public function toArray(object $_notifiable): array
    {
        $typeLabel = match ($this->personalRecord->type) {
            \App\Enums\PersonalRecordType::MaxWeight => 'poids maximum',
            \App\Enums\PersonalRecordType::Max1RM => '1RM estimé',
            \App\Enums\PersonalRecordType::MaxVolumeSet => 'volume par série',
            default => 'record personnel',
        };

        $valeur = self::poidsEnTexte((float) $this->personalRecord->value);

        return [
            'type' => 'personal_record',
            'title' => self::TITRE,
            'message' => "Félicitations ! Tu as battu ton record de {$typeLabel} sur l'exercice {$this->personalRecord->exercise->name} avec {$valeur}\u{00A0}kg.",
            'exercise_id' => $this->personalRecord->exercise_id,
            'achieved_at' => $this->personalRecord->achieved_at,
        ];
    }

    /**
     * Un poids comme `nombre(kilos, 2)` de `resources/js/Utils/nombre.js` :
     * virgule décimale, au plus deux décimales, sans zéro inutile, et les
     * milliers séparés par l'apostrophe de `fr-CH` (« 1'250,5 »).
     *
     * Le format est écrit ici plutôt que demandé à l'ICU du serveur : selon
     * la version de ses données, `fr_CH` y sépare les milliers par une espace
     * fine, si bien que la notification écrivait « 1 250,5 kg » quand le reste
     * de l'application écrit « 1'250,5 kg » (#1981).
     */
    private static function poidsEnTexte(float $kilos): string
    {
        return rtrim(rtrim(number_format($kilos, 2, ',', "'"), '0'), ',');
    }
}
