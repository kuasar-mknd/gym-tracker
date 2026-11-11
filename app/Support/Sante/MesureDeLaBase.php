<?php

declare(strict_types=1);

namespace App\Support\Sante;

use App\Enums\VerdictDeLaBase;
use UnexpectedValueException;

/**
 * Ce que deux relevés de la base, pris à quelques minutes d'écart, disent du
 * coût d'une écriture (#1668) : le protocole de mesure du 03/09, que
 * `base:mesurer` rejoue depuis l'application.
 *
 * Les compteurs de `SHOW GLOBAL STATUS` montent depuis le démarrage du
 * serveur : seul l'écart entre les deux relevés parle de la fenêtre. Un
 * redémarrage les remet à zéro, et l'écart ne voudrait plus rien dire : la
 * mesure refuse alors de conclure, jamais elle ne rend un écart négatif.
 *
 * Les deux seuils du convoi sont posés sur les deux mesures du 03/09 : avant
 * le réglage, 145 attentes de verrou en 205 s, 1 349 ms chacune en moyenne ;
 * après, aucune. Au moins `ATTENTES_D_UN_CONVOI` attentes, une par série
 * saisie environ, de `MS_PAR_ATTENTE_D_UN_CONVOI` ms en moyenne, plus que
 * toute une modification de série une fois la base réglée (76 ms de médiane
 * le 05/09) : c'est une file de transactions qui attendent chacune la
 * validation de la précédente. Moins d'attentes, ou des attentes plus
 * courtes, c'est la contention ordinaire de deux appareils sur la même
 * séance. Le seuil se compare au total, pas à la moyenne arrondie : dix
 * attentes et 1 999 ms restent dessous.
 */
final readonly class MesureDeLaBase
{
    /**
     * Les réglages relevés, ceux que le diagnostic du 03/09 a lus.
     *
     * @var list<string>
     */
    public const array VARIABLES = [
        'log_bin',
        'sync_binlog',
        'innodb_flush_log_at_trx_commit',
        'innodb_redo_log_capacity',
        'innodb_io_capacity',
        'innodb_io_capacity_max',
        'innodb_flush_method',
        'innodb_doublewrite',
    ];

    /**
     * Les compteurs relevés. `Innodb_row_lock_time_max` n'est pas un cumul,
     * mais le maximum depuis le démarrage : il n'a pas d'écart.
     *
     * @var list<string>
     */
    public const array COMPTEURS = [
        'Innodb_row_lock_waits',
        'Innodb_row_lock_time',
        'Innodb_row_lock_time_max',
        'Innodb_log_waits',
        'Innodb_buffer_pool_wait_free',
        'Innodb_os_log_fsyncs',
        'Innodb_data_fsyncs',
        'Uptime',
    ];

    public const int ATTENTES_D_UN_CONVOI = 10;

    public const int MS_PAR_ATTENTE_D_UN_CONVOI = 200;

    public const string NON_RENDU = 'non rendu';

    /**
     * Les bornes de la fenêtre, en secondes : assez pour voir des validations
     * et que la seconde qu'Uptime peut perdre compte peu ; pas plus d'une
     * heure de console bloquée. Le 03/09, la fenêtre durait 205 s.
     */
    public const int PENDANT_AU_MOINS = 10;

    public const int PENDANT_AU_PLUS = 3_600;

    private const string MAXIMUM = 'Innodb_row_lock_time_max';

    /**
     * @param  array<string, string>  $reglages  ceux du second relevé, `NON_RENDU` pour un nom absent
     * @param  array<string, int|null>  $ecarts  par compteur cumulé, null pour un nom absent
     * @param  array{int|null, int|null}  $maximums  le maximum d'une attente, au premier et au second relevé
     * @param  list<string>  $nonRendus
     */
    private function __construct(
        public int $fenetre,
        public array $reglages,
        public array $ecarts,
        private array $maximums,
        public array $nonRendus,
    ) {
    }

    /**
     * Les secondes de `--pendant`, ou null si ce n'est pas un entier écrit en
     * chiffres seuls, entre les deux bornes. La console donne une chaîne,
     * `Artisan::call()` peut donner un entier.
     */
    public static function secondes(mixed $option): ?int
    {
        $texte = is_int($option) ? (string) $option : $option;

        if (! is_string($texte) || preg_match('/\A\d{1,5}\z/', $texte) !== 1) {
            return null;
        }

        $secondes = (int) $texte;

        return $secondes === max(self::PENDANT_AU_MOINS, min(self::PENDANT_AU_PLUS, $secondes)) ? $secondes : null;
    }

    /**
     * Un relevé : les réglages, puis les compteurs, tels que la base les rend.
     *
     * @return array{variables: array<string, string>, compteurs: array<string, string>}
     */
    public static function relever(LecteurDesReglagesDeLaBase $lecteur): array
    {
        return [
            'variables' => $lecteur->lireLesVariables(self::VARIABLES),
            'compteurs' => $lecteur->lireLesCompteurs(self::COMPTEURS),
        ];
    }

    /**
     * @param  array{variables: array<string, string>, compteurs: array<string, string>}  $avant
     * @param  array{variables: array<string, string>, compteurs: array<string, string>}  $apres
     * @param  int  $pendant  les secondes attendues entre les deux relevés
     *
     * @throws UnexpectedValueException quand la fenêtre ne se mesure pas : Uptime absent, redémarrage, compteur qui recule
     */
    public static function entre(array $avant, array $apres, int $pendant): self
    {
        $compteursAvant = self::entiers($avant['compteurs']);
        $compteursApres = self::entiers($apres['compteurs']);
        $fenetre = self::fenetre($compteursAvant['Uptime'] ?? null, $compteursApres['Uptime'] ?? null, $pendant);
        $ecarts = [];

        foreach (array_diff(self::COMPTEURS, [self::MAXIMUM]) as $nom) {
            $ecarts[$nom] = self::ecart($nom, $compteursAvant[$nom] ?? null, $compteursApres[$nom] ?? null);
        }

        return new self(
            fenetre: $fenetre,
            reglages: array_merge(array_fill_keys(self::VARIABLES, self::NON_RENDU), array_intersect_key($apres['variables'], array_flip(self::VARIABLES))),
            ecarts: $ecarts,
            maximums: [$compteursAvant[self::MAXIMUM] ?? null, $compteursApres[self::MAXIMUM] ?? null],
            nonRendus: [
                ...self::absents(self::VARIABLES, $avant['variables'], $apres['variables']),
                ...self::absents(self::COMPTEURS, $compteursAvant, $compteursApres),
            ],
        );
    }

    public function attentes(): ?int
    {
        return $this->ecarts['Innodb_row_lock_waits'] ?? null;
    }

    public function millisecondesDAttente(): ?int
    {
        return $this->ecarts['Innodb_row_lock_time'] ?? null;
    }

    /**
     * La moyenne arrondie à la milliseconde, zéro sans attente.
     */
    public function millisecondesParAttente(): ?int
    {
        $attentes = $this->attentes();
        $millisecondes = $this->millisecondesDAttente();

        if ($attentes === null || $millisecondes === null) {
            return null;
        }

        return $attentes === 0 ? 0 : (int) round($millisecondes / $attentes);
    }

    /**
     * Le maximum d'une attente de verrou, au second relevé. Il court depuis
     * le démarrage du serveur : il n'a pas d'écart, et seul son changement dit
     * qu'il a été atteint dans la fenêtre.
     */
    public function maximum(): ?int
    {
        return $this->maximums[0] === null ? null : $this->maximums[1];
    }

    public function maximumDansLaFenetre(): bool
    {
        [$avant, $apres] = $this->maximums;

        return $avant !== null && $apres !== null && $apres > $avant;
    }

    /**
     * Les fsync d'un compteur par seconde de fenêtre, au centième.
     */
    public function fsyncParSeconde(string $compteur): ?float
    {
        $ecart = $this->ecarts[$compteur] ?? null;

        return $ecart === null ? null : round($ecart / $this->fenetre, 2);
    }

    public function verdict(): VerdictDeLaBase
    {
        if ($this->estUnConvoi()) {
            return VerdictDeLaBase::ConvoiDeVerrous;
        }

        return $this->coutsDeValidation() === [] ? VerdictDeLaBase::RienASignaler : VerdictDeLaBase::CoutDeValidation;
    }

    /**
     * Tout ce qui mène au verdict, le convoi d'abord : le 03/09, c'était les
     * deux à la fois.
     *
     * @return list<string>
     */
    public function raisons(): array
    {
        $raisons = [...$this->convoi(), ...$this->coutsDeValidation()];

        // Une fenêtre sans aucune validation ne dit rien du coût d'une
        // écriture : « rien à signaler » n'y vaudrait pas un examen.
        if ($raisons === [] && ($this->ecarts['Innodb_os_log_fsyncs'] ?? null) === 0) {
            $raisons[] = 'Aucune synchronisation du journal pendant la fenêtre (Innodb_os_log_fsyncs +0) : '
                .'rien n\'a été écrit, donc rien n\'a été mesuré ; relancer en saisissant des séries pendant l\'attente.';
        }

        return $raisons;
    }

    /**
     * La mesure telle que `--json` la rend.
     *
     * @return array{fenetre_s: int, reglages: array<string, string>, ecarts: array<string, int|null>, attentes_de_verrou: array{nombre: int|null, ms: int|null, ms_par_attente: int|null, maximum_ms: int|null, maximum_atteint_pendant_la_fenetre: bool}, fsync_par_seconde: array{journal: float|null, donnees: float|null}, verdict: string, raisons: list<string>, non_rendus: list<string>}
     */
    public function enTableau(): array
    {
        return [
            'fenetre_s' => $this->fenetre,
            'reglages' => $this->reglages,
            'ecarts' => $this->ecarts,
            'attentes_de_verrou' => [
                'nombre' => $this->attentes(),
                'ms' => $this->millisecondesDAttente(),
                'ms_par_attente' => $this->millisecondesParAttente(),
                'maximum_ms' => $this->maximum(),
                'maximum_atteint_pendant_la_fenetre' => $this->maximumDansLaFenetre(),
            ],
            'fsync_par_seconde' => [
                'journal' => $this->fsyncParSeconde('Innodb_os_log_fsyncs'),
                'donnees' => $this->fsyncParSeconde('Innodb_data_fsyncs'),
            ],
            'verdict' => $this->verdict()->value,
            'raisons' => $this->raisons(),
            'non_rendus' => $this->nonRendus,
        ];
    }

    /**
     * Le JSON de `--json`, sur une ligne.
     */
    public function enJson(): string
    {
        return json_encode($this->enTableau(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Ce que la commande écrit : le JSON sur une ligne, ou les lignes lisibles.
     */
    public function rendu(bool $enJson): string
    {
        return $enJson ? $this->enJson() : implode("\n", $this->enLignes());
    }

    /**
     * La mesure en lignes lisibles, le verdict et ses raisons à la fin.
     *
     * @return list<string>
     */
    public function enLignes(): array
    {
        $nonRendus = $this->nonRendus === [] ? [] : ['Non rendus : '.implode(', ', $this->nonRendus).'.'];

        return [
            sprintf('Fenêtre : %s s.', self::entier($this->fenetre)),
            'Réglages : '.implode(' · ', array_map(fn (string $nom, string $valeur): string => $nom.' '.$valeur, array_keys($this->reglages), $this->reglages)).'.',
            sprintf(
                'Attentes de verrou : %s, %s en tout, %s par attente ; maximum %s%s.',
                self::nombre($this->attentes()),
                self::millisecondes($this->millisecondesDAttente()),
                self::millisecondes($this->millisecondesParAttente()),
                self::millisecondes($this->maximum()),
                $this->quandLeMaximum(),
            ),
            sprintf(
                'Attentes du journal (Innodb_log_waits) : %s ; pages libres attendues (Innodb_buffer_pool_wait_free) : %s.',
                self::nombre($this->ecarts['Innodb_log_waits'] ?? null),
                self::nombre($this->ecarts['Innodb_buffer_pool_wait_free'] ?? null),
            ),
            sprintf('fsync : %s, %s.', $this->fsync('Innodb_os_log_fsyncs', 'au journal'), $this->fsync('Innodb_data_fsyncs', 'aux données')),
            ...$nonRendus,
            sprintf('Verdict : %s.', $this->verdict()->value),
            ...array_map(fn (string $raison): string => '- '.$raison, $this->raisons()),
        ];
    }

    /**
     * Un entier écrit à la française : « 195 637 ».
     */
    public static function entier(int $nombre): string
    {
        return number_format($nombre, 0, ',', ' ');
    }

    private function estUnConvoi(): bool
    {
        $attentes = (int) $this->attentes();

        return $attentes >= self::ATTENTES_D_UN_CONVOI
            && (int) $this->millisecondesDAttente() >= $attentes * self::MS_PAR_ATTENTE_D_UN_CONVOI;
    }

    /**
     * @return list<string>
     */
    private function convoi(): array
    {
        if (! $this->estUnConvoi()) {
            return [];
        }

        $raison = sprintf(
            '%s attentes de verrou en %s s, %s ms par attente : au moins %d attentes de %d ms en moyenne font un convoi, '
            .'des transactions qui attendent chacune la validation de la précédente.',
            self::entier((int) $this->attentes()),
            self::entier($this->fenetre),
            self::entier((int) $this->millisecondesParAttente()),
            self::ATTENTES_D_UN_CONVOI,
            self::MS_PAR_ATTENTE_D_UN_CONVOI,
        );

        return [$raison];
    }

    /**
     * Ce qui fait payer chaque validation au stockage : une attente du
     * stockage dans la fenêtre, ou des réglages qui synchronisent chaque
     * validation, quand des validations ont eu lieu.
     *
     * @return list<string>
     */
    private function coutsDeValidation(): array
    {
        $couts = [];
        $attentesDuJournal = (int) ($this->ecarts['Innodb_log_waits'] ?? 0);
        $pagesAttendues = (int) ($this->ecarts['Innodb_buffer_pool_wait_free'] ?? 0);

        if ($attentesDuJournal > 0) {
            $couts[] = sprintf('Innodb_log_waits +%s : le tampon du journal a attendu que le stockage écrive.', self::entier($attentesDuJournal));
        }

        if ($pagesAttendues > 0) {
            $couts[] = sprintf(
                'Innodb_buffer_pool_wait_free +%s : des requêtes ont attendu qu\'une page soit écrite sur le stockage pour en libérer une.',
                self::entier($pagesAttendues),
            );
        }

        return [...$couts, ...$this->synchronisations()];
    }

    /**
     * Les réglages qui font attendre chaque validation sur le stockage, ceux
     * que la pile a retirés le 03/09, dits seulement si des validations ont
     * eu lieu dans la fenêtre.
     *
     * @return list<string>
     */
    private function synchronisations(): array
    {
        $causes = array_keys(array_filter([
            'innodb_flush_log_at_trx_commit = 1' => $this->reglages['innodb_flush_log_at_trx_commit'] === '1',
            'log_bin ON et sync_binlog = 1' => strtoupper($this->reglages['log_bin']) === 'ON' && $this->reglages['sync_binlog'] === '1',
        ]));

        if ($causes === [] || ($this->ecarts['Innodb_os_log_fsyncs'] ?? 0) === 0) {
            return [];
        }

        $raison = sprintf(
            'Chaque validation se synchronise %d fois sur le stockage (%s) : %s fsync par seconde au journal pendant la fenêtre.',
            count($causes),
            implode(' ; ', $causes),
            number_format((float) $this->fsyncParSeconde('Innodb_os_log_fsyncs'), 2, ',', ' '),
        );

        return [$raison];
    }

    private function quandLeMaximum(): string
    {
        if ((int) $this->maximum() === 0) {
            return '';
        }

        return $this->maximumDansLaFenetre() ? ', atteint pendant la fenêtre' : ', atteint avant la fenêtre';
    }

    private function fsync(string $compteur, string $lieu): string
    {
        $ecart = $this->ecarts[$compteur] ?? null;

        if ($ecart === null) {
            return self::NON_RENDU.' '.$lieu;
        }

        return sprintf('%s %s (%s par seconde)', self::entier($ecart), $lieu, number_format((float) $this->fsyncParSeconde($compteur), 2, ',', ' '));
    }

    private static function nombre(?int $nombre): string
    {
        return $nombre === null ? self::NON_RENDU : self::entier($nombre);
    }

    private static function millisecondes(?int $millisecondes): string
    {
        return $millisecondes === null ? self::NON_RENDU : self::entier($millisecondes).' ms';
    }

    /**
     * Les noms qu'un des deux relevés n'a pas rendus.
     *
     * @param  list<string>  $noms
     * @param  array<string, int|string>  $avant
     * @param  array<string, int|string>  $apres
     * @return list<string>
     */
    private static function absents(array $noms, array $avant, array $apres): array
    {
        return array_values(array_filter($noms, fn (string $nom): bool => ! isset($avant[$nom], $apres[$nom])));
    }

    /**
     * La durée de la fenêtre, lue sur l'horloge du serveur. L'attente dure au
     * moins `$pendant` secondes, et Uptime, compté en secondes entières, en
     * perd une au plus : s'il monte de moins, le serveur a redémarré entre
     * les deux relevés, même s'il tournait depuis moins longtemps que
     * l'attente au premier.
     */
    private static function fenetre(?int $uptimeAvant, ?int $uptimeApres, int $pendant): int
    {
        if ($uptimeAvant === null || $uptimeApres === null) {
            throw new UnexpectedValueException('La base n\'a pas rendu Uptime : la fenêtre ne se date pas, et un redémarrage ne se verrait pas.');
        }

        if ($uptimeApres < $uptimeAvant + $pendant - 1) {
            throw new UnexpectedValueException(sprintf(
                'La base a redémarré pendant la fenêtre (Uptime %s s, puis %s s, pour %s s d\'attente) : '
                .'ses compteurs sont repartis de zéro et l\'écart ne veut rien dire ; relancer la mesure.',
                self::entier($uptimeAvant),
                self::entier($uptimeApres),
                self::entier($pendant),
            ));
        }

        return $uptimeApres - $uptimeAvant;
    }

    private static function ecart(string $nom, ?int $avant, ?int $apres): ?int
    {
        if ($avant === null || $apres === null) {
            return null;
        }

        if ($apres < $avant) {
            throw new UnexpectedValueException(sprintf(
                '%s a reculé pendant la fenêtre (%s puis %s) : l\'écart ne veut rien dire, relancer la mesure.',
                $nom,
                self::entier($avant),
                self::entier($apres),
            ));
        }

        return $apres - $avant;
    }

    /**
     * Les compteurs rendus en chiffres ; un autre texte compte comme non rendu.
     *
     * @param  array<string, string>  $compteurs
     * @return array<string, int>
     */
    private static function entiers(array $compteurs): array
    {
        return array_map(intval(...), array_filter($compteurs, ctype_digit(...)));
    }
}
