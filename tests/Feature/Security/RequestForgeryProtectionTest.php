<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery, keeping the old
 * names as deprecated subclasses. Three separate places named the old class —
 * bootstrap/app.php, config/sanctum.php and the Filament panel — and not one of
 * the 1444 existing tests touched CSRF wiring, so pointing any of them at a class
 * that no longer exists would have surfaced only in production.
 *
 * Le middleware s'efface quand `runningUnitTests()` est vrai, donc un test HTTP
 * ordinaire ne le voit jamais refuser. Les premiers contrôles vérifient donc le
 * branchement ; les derniers remplacent le middleware par une sous-classe qui ne
 * s'efface pas, pour observer la vraie décision sur la vraie route.
 */
final class RequestForgeryProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_routes_are_protected_against_request_forgery(): void
    {
        $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];

        $protection = array_filter(
            $webGroup,
            fn (mixed $middleware): bool => is_string($middleware)
                && is_a($middleware, PreventRequestForgery::class, true)
        );

        $this->assertNotEmpty(
            $protection,
            'The web middleware group must apply request forgery protection.'
        );
    }

    public function test_only_dusk_routes_are_exempt(): void
    {
        $except = new ReflectionClass(PreventRequestForgery::class)
            ->getProperty('neverVerify')
            ->getValue();

        $this->assertEqualsCanonicalizing(
            ['_dusk/*'],
            $except,
            'Widening this list removes forgery protection from real routes. The api/* exemption left with the REST API (#1673): the seven remaining routes are session-authenticated, so they verify the token like any web route.'
        );
    }

    /**
     * Sanctum appends whatever class this config names onto the stateful
     * pipeline without checking it, so a stale reference degrades silently.
     */
    public function test_sanctum_points_at_a_live_forgery_middleware(): void
    {
        $configured = config('sanctum.middleware.validate_csrf_token');

        $this->assertIsString($configured);
        $this->assertTrue(class_exists($configured), "{$configured} does not exist.");
        $this->assertTrue(
            is_a($configured, PreventRequestForgery::class, true),
            "{$configured} is not request forgery protection."
        );
    }

    public function test_the_admin_panel_keeps_forgery_protection(): void
    {
        $middleware = Filament::getPanel('admin')->getMiddleware();

        $protection = array_filter(
            $middleware,
            fn (mixed $entry): bool => is_string($entry)
                && is_a($entry, PreventRequestForgery::class, true)
        );

        $this->assertNotEmpty(
            $protection,
            'The admin panel must keep request forgery protection.'
        );
    }

    /**
     * La voie que prend le service worker (#1847).
     *
     * Un worker n'a pas de document où lire le `<meta name="csrf-token">` : il
     * renvoie un abonnement renouvelé sans jeton. Il passe parce que
     * PreventRequestForgery accepte toute écriture qui porte
     * `Sec-Fetch-Site: same-origin`, en-tête que seul le navigateur pose et
     * qu'une page tierce ne peut pas imiter. Ce n'est pas une exemption : la
     * même vérification couvre toutes les routes web. Si Laravel la retirait,
     * le worker partirait en 419 sans bruit, et c'est ici qu'on l'apprendrait.
     */
    public function test_une_ecriture_de_meme_origine_passe_sans_jeton(): void
    {
        $this->protegerCommeEnProduction();
        $utilisateur = User::factory()->create();

        $this->actingAs($utilisateur)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->postJson(route('push-subscriptions.update', absolute: false), $this->abonnementRenouvele())
            ->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $utilisateur->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/renouvele',
        ]);
    }

    /**
     * Le pendant, sans lequel le test précédent ne prouverait rien : la
     * sous-classe refuse bien, donc c'est l'en-tête qui fait passer, pas un
     * contournement resté actif.
     */
    #[DataProvider('provenancesSansJeton')]
    public function test_une_ecriture_sans_jeton_venue_d_ailleurs_est_refusee(?string $provenance): void
    {
        $this->protegerCommeEnProduction();

        $requete = $this->actingAs(User::factory()->create());

        if ($provenance !== null) {
            $requete = $requete->withHeader('Sec-Fetch-Site', $provenance);
        }

        $requete->postJson(route('push-subscriptions.update', absolute: false), $this->abonnementRenouvele())
            ->assertStatus(419);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * `same-site` aussi : bootstrap/app.php n'active pas `allowSameSite`, et un
     * sous-domaine voisin, servi par le même serveur, n'a pas à écrire chez nous.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function provenancesSansJeton(): array
    {
        return [
            'une autre origine' => ['cross-site'],
            'un site voisin' => ['same-site'],
            'une adresse tapée' => ['none'],
            'un navigateur qui ne dit rien' => [null],
        ];
    }

    /**
     * Remplace le middleware du groupe web par une sous-classe qui ne s'efface
     * pas sous les tests, le seul moyen d'observer sa décision par HTTP.
     */
    private function protegerCommeEnProduction(): void
    {
        $this->app->bind(
            PreventRequestForgery::class,
            fn (Application $application): PreventRequestForgery => new class($application, $application->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            },
        );
    }

    /**
     * Ce que le worker envoie : `toJSON()` de l'abonnement, tel quel.
     *
     * @return array{endpoint: string, expirationTime: null, keys: array{p256dh: string, auth: string}}
     */
    private function abonnementRenouvele(): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/renouvele',
            'expirationTime' => null,
            'keys' => ['p256dh' => 'cle-p256dh', 'auth' => 'jeton-auth'],
        ];
    }
}
