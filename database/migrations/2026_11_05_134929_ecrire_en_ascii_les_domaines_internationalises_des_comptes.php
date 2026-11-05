<?php

declare(strict_types=1);

use App\Support\AdresseCanonique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Écrit en ASCII le domaine internationalisé des adresses de compte
 * enregistrées en Unicode (#2168).
 *
 * Avant #2001, l'inscription, le profil et le panneau enregistraient un
 * domaine internationalisé tel qu'il était tapé, en Unicode. La connexion,
 * « mot de passe oublié », la réinitialisation et la connexion sociale
 * écrivent désormais toute saisie sous sa forme canonique, en ASCII
 * (`AdresseCanonique`), avant de chercher le compte : sans cette migration,
 * un compte enregistré en Unicode ne se trouverait plus sous aucune forme.
 *
 * Chaque adresse dont le domaine porte un caractère hors ASCII passe par la
 * même fonction. Elle s'écrit sous sa forme canonique quand aucun autre
 * compte ne porte cette forme et qu'aucune autre adresse en Unicode ne la
 * partage, selon la base et non selon une égalité en PHP : la collation de
 * `users.email` tient « NOE@XN--BCHER-KVA.EXAMPLE.ORG » pour la forme de
 * « noe@bücher.example.org », et distingue « jean@ⰰ.example.org » de
 * « jean@Ⰰ.example.org », que la conversion écrit l'un et l'autre
 * « jean@xn--tej.example.org ». Deux adresses qui partagent une forme sont
 * donc toutes deux laissées : un plan calculé d'avance convertirait la
 * première et tomberait sur l'index unique à la seconde, et choisir l'une
 * reviendrait à choisir un compte à la place d'un autre. Le partage se mesure
 * par l'index lui-même, dans une transaction annulée, avant toute écriture.
 *
 * L'écriture passe par le constructeur de requêtes, jamais par le modèle : la
 * boîte ne change pas, si bien que ni l'avis de changement d'adresse, ni le
 * retrait de la vérification, ni l'oubli de la preuve de liaison
 * (`SurveilleSonAdresse`, `OublieLaPreuveDeSaLiaison`) n'ont lieu d'être. Un
 * lien de réinitialisation en cours, rangé sous l'ancienne forme, ne vaut
 * plus : le compte en redemande un.
 *
 * Rien n'est fusionné ni supprimé. Le journal liste par identifiant, sans
 * adresse, chaque compte laissé en Unicode, les comptes de sa forme et leur
 * état (en attente ou ouvert), ainsi que les comptes dont la forme canonique
 * laisse le domaine tel quel (« ß », lettre pleine chasse, conversion
 * refusée) ; le propriétaire décide de chacun. D'ici là, un compte laissé ne
 * s'ouvre plus par mot de passe ni ne reçoit le lien : chaque forme saisie
 * trouve le compte qui porte la forme ASCII.
 *
 * Rejouable : une seconde passe ne trouve plus que les adresses laissées, et
 * n'écrit rien, hors de la transaction qu'elle annule pour mesurer un
 * partage. `down()` ne rend pas la forme Unicode, que rien ne garde.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $comptes = DB::table('users')
            ->whereRaw("char_length(substring_index(email, '@', -1)) <> length(substring_index(email, '@', -1))")
            ->orderBy('id')
            ->get(['id', 'email', 'en_attente_depuis']);

        $adresses = [];
        $etats = [];
        $formes = [];
        $laisses = [];

        foreach ($comptes as $compte) {
            $id = filter_var($compte->id ?? null, FILTER_VALIDATE_INT);
            $adresse = $compte->email ?? null;

            if ($id === false || ! is_string($adresse)) {
                continue;
            }

            $adresses[$id] = $adresse;
            $etats[$id] = self::etat($compte->en_attente_depuis ?? null);
            $forme = AdresseCanonique::de($adresse);

            if ($forme === $adresse) {
                $laisses[] = $id;
            } else {
                $formes[$id] = $forme;
            }
        }

        $portees = [];

        foreach ($formes as $id => $forme) {
            $comptesDeLaForme = $this->comptesDeLaForme($id, $forme);

            if ($comptesDeLaForme !== []) {
                $portees[$id] = $comptesDeLaForme;
            }
        }

        $partagees = $this->formesPartagees(array_diff_key($formes, $portees));
        $dansUnPartage = [];

        foreach ($partagees as $groupe) {
            foreach ($groupe as $id) {
                $dansUnPartage[$id] = true;
            }
        }

        $converties = [];

        foreach (array_diff_key($formes, $portees, $dansUnPartage) as $id => $forme) {
            try {
                $ecrites = DB::table('users')->where('id', $id)->where('email', $adresses[$id])->update(['email' => $forme]);
            } catch (UniqueConstraintViolationException) {
                $portees[$id] = $this->comptesDeLaForme($id, $forme);

                continue;
            }

            if ($ecrites === 1) {
                $converties[] = $id;
            }
        }

        $this->journaliser($converties, $portees, $partagees, $laisses, $etats);
    }

    /**
     * Volontairement vide : la forme Unicode d'une adresse convertie n'est
     * gardée nulle part, et la boîte est la même.
     */
    public function down(): void
    {
    }

    /**
     * L'état d'un compte pour le journal : en attente du lien d'ouverture, ou ouvert.
     */
    private static function etat(mixed $enAttenteDepuis): string
    {
        return $enAttenteDepuis === null ? 'ouvert' : 'en attente';
    }

    /**
     * Les autres comptes qui portent cette forme aux yeux de la base, par
     * identifiant, avec leur état.
     *
     * @return list<array{compte: int, etat: string}>
     */
    private function comptesDeLaForme(int $id, string $forme): array
    {
        $comptes = [];

        foreach (DB::table('users')->where('email', $forme)->where('id', '<>', $id)->orderBy('id')->get(['id', 'en_attente_depuis']) as $compte) {
            $autre = filter_var($compte->id ?? null, FILTER_VALIDATE_INT);

            if ($autre !== false) {
                $comptes[] = ['compte' => $autre, 'etat' => self::etat($compte->en_attente_depuis ?? null)];
            }
        }

        return $comptes;
    }

    /**
     * Les adresses dont la forme est aussi celle d'une autre adresse de la
     * liste, par groupes d'identifiants.
     *
     * L'index unique en juge, dans une transaction que la méthode annule
     * toujours : chaque adresse y prend sa forme, l'une après l'autre, et une
     * adresse dont la forme est déjà celle d'une adresse convertie avant elle
     * rejoint son groupe au lieu de s'écrire. La collation décide ainsi, et
     * non une égalité en PHP, que la casse d'une partie locale ou une lettre
     * qu'elle ne connaît pas tromperaient.
     *
     * @param  array<int, string>  $formes  La forme canonique de chaque adresse, par identifiant.
     * @return array<int, list<int>>
     */
    private function formesPartagees(array $formes): array
    {
        $groupes = [];

        DB::beginTransaction();

        try {
            foreach ($formes as $id => $forme) {
                $premier = filter_var(DB::table('users')->where('email', $forme)->where('id', '<>', $id)->min('id'), FILTER_VALIDATE_INT);

                if ($premier !== false) {
                    $groupes[$premier][] = $id;

                    continue;
                }

                try {
                    DB::table('users')->where('id', $id)->update(['email' => $forme]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                $groupes[$id] = [$id];
            }
        } finally {
            DB::rollBack();
        }

        return array_filter($groupes, static fn (array $groupe): bool => count($groupe) > 1);
    }

    /**
     * Liste dans le journal, par identifiant et sans adresse, ce que la
     * migration a écrit et ce qu'elle laisse au propriétaire.
     *
     * @param  list<int>  $converties
     * @param  array<int, list<array{compte: int, etat: string}>>  $portees
     * @param  array<int, list<int>>  $partagees
     * @param  list<int>  $laisses
     * @param  array<int, string>  $etats
     */
    private function journaliser(array $converties, array $portees, array $partagees, array $laisses, array $etats): void
    {
        if ($converties !== []) {
            Log::info('Adresses de compte écrites en ASCII (#2168)', ['comptes' => $converties]);
        }

        ksort($portees);

        foreach ($portees as $id => $comptesDeLaForme) {
            Log::warning('Adresse de compte laissée en Unicode : sa forme ASCII est celle d’un autre compte (#2168)', [
                'compte' => $id,
                'etat' => $etats[$id] ?? 'ouvert',
                'comptes_de_la_forme' => $comptesDeLaForme,
            ]);
        }

        foreach ($partagees as $groupe) {
            Log::warning('Adresses de compte laissées en Unicode : elles ont la même forme ASCII (#2168)', [
                'comptes' => array_map(static fn (int $id): array => ['compte' => $id, 'etat' => $etats[$id] ?? 'ouvert'], $groupe),
            ]);
        }

        if ($laisses !== []) {
            Log::warning('Adresses de compte laissées telles quelles : la forme canonique ne convertit pas leur domaine (#2168)', ['comptes' => $laisses]);
        }
    }
};
