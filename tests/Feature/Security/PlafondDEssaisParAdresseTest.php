<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\AppareilConnu;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FormulaireDeReinitialisation;
use Tests\TestCase;

/**
 * #2310 : les échecs d'une adresse, de toutes les adresses IP, sont bornés,
 * sauf pour l'appareil qui a déjà ouvert le compte (cookie d'appareil connu).
 */
class PlafondDEssaisParAdresseTest extends TestCase
{
    use RefreshDatabase;

    private function echouer(string $adresse, string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $adresse, 'password' => 'mauvais-mot-de-passe']);
    }

    private function remplirLePlafond(string $adresse): void
    {
        $clef = LoginRequest::clefDeLAdresse($adresse);

        for ($i = 0; $i < LoginRequest::ECHECS_PAR_ADRESSE; $i++) {
            RateLimiter::hit($clef, LoginRequest::SECONDES_DE_LA_FENETRE_PAR_ADRESSE);
        }
    }

    private function echecsDe(string $adresse): int
    {
        $essais = RateLimiter::attempts(LoginRequest::clefDeLAdresse($adresse));
        $this->assertIsInt($essais);

        return $essais;
    }

    private function appareilApresUneConnexion(User $compte): string
    {
        $cookie = $this->post('/login', ['email' => $compte->email, 'password' => 'password'])
            ->getCookie(AppareilConnu::NOM, false);
        $this->assertNotNull($cookie);

        $this->deconnecter();

        return (string) $cookie->getValue();
    }

    private function deconnecter(): void
    {
        auth()->guard('web')->logout();
        $this->flushSession();
    }

    /**
     * @return TestResponse<Response>
     */
    private function connecterDepuis(string $adresse, ?string $appareil, string $motDePasse = 'password', string $ip = '10.0.0.9'): TestResponse
    {
        $this->assertGuest();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $requete = $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        if ($appareil !== null) {
            $requete = $requete->withUnencryptedCookie(AppareilConnu::NOM, $appareil);
        }

        return $requete->post('/login', ['email' => $adresse, 'password' => $motDePasse]);
    }

    private function reinitialiserParCourriel(User $compte, string $motDePasse): ?string
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $compte->email]);
        $cookie = null;

        Notification::assertSentTo($compte, ResetPassword::class, function (ResetPassword $notification) use ($compte, $motDePasse, &$cookie): true {
            $courriel = $notification->toMail($compte);
            $this->assertInstanceOf(MailMessage::class, $courriel);

            $cookie = $this->post('/reset-password', FormulaireDeReinitialisation::depuisLeLien((string) $courriel->actionUrl, $motDePasse))
                ->getCookie(AppareilConnu::NOM, false);

            return true;
        });

        return $cookie?->getValue();
    }

    public function test_la_connexion_pose_le_cookie_d_appareil_connu(): void
    {
        $compte = User::factory()->create();

        $cookie = $this->post('/login', ['email' => $compte->email, 'password' => 'password'])
            ->getCookie(AppareilConnu::NOM, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addMinutes(AppareilConnu::MINUTES_DE_VIE)->getTimestamp(), $cookie->getExpiresTime(), 5);
    }

    public function test_un_echec_ne_pose_pas_le_cookie(): void
    {
        $compte = User::factory()->create();

        $reponse = $this->post('/login', ['email' => $compte->email, 'password' => 'x']);

        $this->assertNull($reponse->getCookie(AppareilConnu::NOM, false));
    }

    public function test_un_appareil_connu_se_connecte_malgre_un_plafond_rempli_par_d_autres_adresses(): void
    {
        $compte = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($compte);
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $appareil);

        $this->assertAuthenticated();
    }

    public function test_sans_le_cookie_le_meme_scenario_est_refuse_sur_email(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, null)->assertSessionHasErrors(['email' => trans('auth.plafond')]);

        $this->assertGuest();
    }

    public function test_un_appareil_connu_le_reste_apres_sa_deconnexion(): void
    {
        $compte = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($compte);
        $this->remplirLePlafond($compte->email);

        $reponse = $this->actingAs($compte)->post('/logout');
        $reemis = $reponse->getCookie(AppareilConnu::NOM, false);
        $this->assertNotNull($reemis);
        $this->flushSession();

        $this->connecterDepuis($compte->email, (string) $reemis->getValue());

        $this->assertAuthenticated();
        $this->assertSame($appareil, (string) $reemis->getValue());
    }

    public function test_un_appareil_qui_se_souvient_et_n_est_jamais_passe_par_la_connexion_est_marque_a_la_deconnexion(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $cookie = $this->actingAs($compte)->post('/logout')->getCookie(AppareilConnu::NOM, false);
        $this->assertNotNull($cookie);
        $this->flushSession();

        $this->connecterDepuis($compte->email, (string) $cookie->getValue());

        $this->assertAuthenticated();
    }

    public function test_apres_une_reinitialisation_par_courriel_le_nouvel_appareil_se_connecte_sous_attaque(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $appareil = $this->reinitialiserParCourriel($compte, 'nouveau-mot-de-passe-9');

        $this->assertNotNull($appareil);
        $this->assertSame(0, $this->echecsDe($compte->email));
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $appareil, 'nouveau-mot-de-passe-9');

        $this->assertAuthenticated();
    }

    public function test_une_variante_d_orthographe_de_l_adresse_passe_depuis_l_appareil_connu(): void
    {
        $compte = User::factory()->create(['email' => 'jean@example.org']);
        $appareil = $this->appareilApresUneConnexion($compte);
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis('Jean@Example.ORG', $appareil);

        $this->assertAuthenticated();
    }

    public function test_une_adresse_inconnue_recoit_le_meme_refus_avec_ou_sans_cookie(): void
    {
        $autre = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($autre);
        $this->remplirLePlafond('personne@example.org');
        $this->remplirLePlafond($autre->email);

        $inconnue = $this->connecterDepuis('personne@example.org', $appareil);
        $inconnue->assertSessionHasErrors(['email' => trans('auth.plafond')]);
        $this->flushSession();

        $connue = $this->connecterDepuis($autre->email, null);
        $connue->assertSessionHasErrors(['email' => trans('auth.plafond')]);

        $this->assertSame($connue->getStatusCode(), $inconnue->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cookiesQuiNeValentRien(): array
    {
        return [
            'illisible' => ['n-importe-quoi'],
            'sans empreinte' => ['1'],
            'empreinte fausse' => ['1|faux'],
            'empreinte vide' => ['1|'],
            'compte inexistant' => ['999999|faux'],
            'identifiant non numérique' => ['abc|faux'],
        ];
    }

    #[DataProvider('cookiesQuiNeValentRien')]
    public function test_un_cookie_falsifie_vaut_un_cookie_absent_sans_exception(string $valeur): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $valeur)->assertSessionHasErrors(['email' => trans('auth.plafond')]);

        $this->assertGuest();
    }

    public function test_une_empreinte_fausse_sur_le_bon_identifiant_ne_vaut_rien(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $compte->id.'|faux')->assertSessionHasErrors(['email' => trans('auth.plafond')]);
    }

    public function test_le_cookie_est_reserve_a_https_quand_la_session_l_est(): void
    {
        config(['session.secure' => true]);
        $compte = User::factory()->create();

        $cookie = $this->post('/login', ['email' => $compte->email, 'password' => 'password'])
            ->getCookie(AppareilConnu::NOM, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_le_cookie_d_un_autre_compte_ne_vaut_pas(): void
    {
        $autre = User::factory()->create();
        $appareilDeLAutre = $this->appareilApresUneConnexion($autre);
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $appareilDeLAutre)->assertSessionHasErrors(['email' => trans('auth.plafond')]);
    }

    public function test_le_cookie_ne_vaut_que_pour_le_compte_qu_il_nomme(): void
    {
        $compte = User::factory()->create();
        $autre = User::factory()->create();
        $empreinte = explode('|', $this->appareilApresUneConnexion($compte), 2)[1];

        $requete = Request::create('/login', 'POST', cookies: [AppareilConnu::NOM => $autre->id.'|'.$empreinte]);

        $this->assertFalse(AppareilConnu::vautPour($requete, $compte));
        $this->assertSame($autre->id, AppareilConnu::compteDe($requete)?->id);
    }

    public function test_un_echec_avec_un_cookie_valide_ne_compte_pas(): void
    {
        $compte = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($compte);

        $this->connecterDepuis($compte->email, $appareil, 'mauvais');

        $this->assertSame(0, $this->echecsDe($compte->email));
    }

    public function test_un_succes_avec_un_cookie_valide_vide_le_compteur(): void
    {
        $compte = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($compte);
        $this->echouer($compte->email, '10.0.0.1');
        $this->echouer($compte->email, '10.0.0.2');

        $this->connecterDepuis($compte->email, $appareil);

        $this->assertSame(0, $this->echecsDe($compte->email));
    }

    public function test_un_succes_sans_cookie_ne_vide_pas_le_compteur(): void
    {
        $compte = User::factory()->create();
        $this->echouer($compte->email, '10.0.0.1');

        $this->connecterDepuis($compte->email, null);

        $this->assertSame(1, $this->echecsDe($compte->email));
    }

    public function test_un_appareil_connu_reste_soumis_au_limiteur_du_couple_adresse_et_ip(): void
    {
        $compte = User::factory()->create();
        $appareil = $this->appareilApresUneConnexion($compte);

        for ($i = 0; $i < 5; $i++) {
            $this->connecterDepuis($compte->email, $appareil, 'mauvais', '10.0.0.5');
        }

        $this->connecterDepuis($compte->email, $appareil, 'password', '10.0.0.5')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_un_changement_volontaire_de_mot_de_passe_revoque_les_autres_appareils_et_marque_celui_ci(): void
    {
        $compte = User::factory()->create();
        $ancien = $this->appareilApresUneConnexion($compte);

        $nouveau = $this->actingAs($compte)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'nouveau-mot-de-passe-9',
            'password_confirmation' => 'nouveau-mot-de-passe-9',
        ])->getCookie(AppareilConnu::NOM, false);
        $this->assertNotNull($nouveau);
        $this->deconnecter();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $ancien, 'nouveau-mot-de-passe-9')
            ->assertSessionHasErrors(['email' => trans('auth.plafond')]);

        $this->connecterDepuis($compte->email, (string) $nouveau->getValue(), 'nouveau-mot-de-passe-9');

        $this->assertAuthenticated();
    }

    public function test_une_reinitialisation_par_courriel_revoque_les_anciens_appareils(): void
    {
        $compte = User::factory()->create();
        $ancien = $this->appareilApresUneConnexion($compte);

        $this->reinitialiserParCourriel($compte, 'nouveau-mot-de-passe-9');
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, $ancien, 'nouveau-mot-de-passe-9')
            ->assertSessionHasErrors(['email' => trans('auth.plafond')]);
    }

    public function test_chaque_echec_compte_quelle_que_soit_l_adresse_ip(): void
    {
        $compte = User::factory()->create();

        $this->echouer($compte->email, '10.0.0.1');
        $this->echouer($compte->email, '10.0.0.2');

        $this->assertSame(2, $this->echecsDe($compte->email));
    }

    public function test_une_adresse_inconnue_compte_comme_une_adresse_connue(): void
    {
        $this->echouer('personne@example.org', '10.0.0.1');

        $this->assertSame(1, $this->echecsDe('personne@example.org'));
    }

    public function test_une_connexion_reussie_ne_compte_pas(): void
    {
        $compte = User::factory()->create();

        $this->post('/login', ['email' => $compte->email, 'password' => 'password']);

        $this->assertAuthenticated();
        $this->assertSame(0, $this->echecsDe($compte->email));
    }

    public function test_le_plafond_refuse_sur_le_champ_email_meme_avec_le_bon_mot_de_passe_et_une_autre_adresse_ip(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->connecterDepuis($compte->email, null)->assertSessionHasErrors(['email' => trans('auth.plafond')]);

        $this->assertGuest();
    }

    public function test_un_essai_refuse_par_le_plafond_ne_compte_pas(): void
    {
        $this->remplirLePlafond('personne@example.org');

        $this->post('/login', ['email' => 'personne@example.org', 'password' => 'x']);

        $this->assertSame(LoginRequest::ECHECS_PAR_ADRESSE, $this->echecsDe('personne@example.org'));
    }

    public function test_le_plafond_ne_touche_pas_une_autre_adresse(): void
    {
        $this->remplirLePlafond('personne@example.org');
        $autre = User::factory()->create();

        $this->post('/login', ['email' => $autre->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_les_saisies_que_la_collation_confond_partagent_le_compteur(): void
    {
        $this->echouer('Jean@Example.org', '10.0.0.1');
        $this->echouer('jean@example.org', '10.0.0.2');
        $this->echouer('jéan@example.org', '10.0.0.3');

        $this->assertSame(3, $this->echecsDe('jean@example.org'));
    }

    public function test_les_deux_formes_d_un_domaine_internationalise_partagent_le_compteur(): void
    {
        $this->echouer('jean@bücher.example.org', '10.0.0.1');
        $this->echouer('jean@xn--bcher-kva.example.org', '10.0.0.2');

        $this->assertSame(2, $this->echecsDe('jean@xn--bcher-kva.example.org'));
    }

    public function test_un_essai_refuse_par_le_limiteur_du_couple_ne_compte_pas_pour_l_adresse(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->echouer('personne@example.org', '10.0.0.1');
        }

        $this->assertSame(5, $this->echecsDe('personne@example.org'));
    }

    public function test_le_limiteur_du_couple_adresse_et_ip_reste_en_vigueur(): void
    {
        $compte = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->echouer($compte->email, '10.0.0.1');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post('/login', ['email' => $compte->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_la_connexion_reprend_apres_la_fenetre(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->travel(LoginRequest::SECONDES_DE_LA_FENETRE_PAR_ADRESSE + 1)->seconds();

        $this->post('/login', ['email' => $compte->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_reinitialiser_le_mot_de_passe_leve_le_plafond(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->reinitialiserParCourriel($compte, 'password');

        $this->assertSame(0, $this->echecsDe($compte->email));
    }
}
