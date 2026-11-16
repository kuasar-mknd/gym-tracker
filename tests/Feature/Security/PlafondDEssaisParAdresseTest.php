<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FormulaireDeReinitialisation;
use Tests\TestCase;

/**
 * #2310 : les échecs d'une adresse, de toutes les adresses IP, sont bornés.
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

    public function test_le_plafond_refuse_en_429_meme_avec_le_bon_mot_de_passe_et_une_autre_adresse_ip(): void
    {
        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post('/login', ['email' => $compte->email, 'password' => 'password']);

        $reponse->assertStatus(429);
        $this->assertTrue($reponse->headers->has('Retry-After'));
        $this->assertGuest();
    }

    public function test_une_adresse_inconnue_est_refusee_de_la_meme_facon(): void
    {
        $this->remplirLePlafond('personne@example.org');

        $this->post('/login', ['email' => 'personne@example.org', 'password' => 'x'])->assertStatus(429);
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
        Notification::fake();

        $compte = User::factory()->create();
        $this->remplirLePlafond($compte->email);

        $this->post('/forgot-password', ['email' => $compte->email]);

        Notification::assertSentTo($compte, ResetPassword::class, function (ResetPassword $notification) use ($compte): true {
            $courriel = $notification->toMail($compte);
            $this->assertInstanceOf(MailMessage::class, $courriel);

            $this->post('/reset-password', FormulaireDeReinitialisation::depuisLeLien((string) $courriel->actionUrl, 'password'))
                ->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, $this->echecsDe($compte->email));
    }
}
