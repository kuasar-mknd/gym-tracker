<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\AdresseEnAsciiImprimable;
use App\Rules\AdresseQueLaMessagerieSaitEcrire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Nom et adresse du compte, depuis le profil.
 *
 * Changer l'adresse exige le mot de passe actuel, comme changer le mot de
 * passe (`UpdatePasswordRequest`) ou supprimer le compte (`DeleteUserRequest`) :
 * l'adresse reçoit les liens de réinitialisation, et une session ouverte par
 * quelqu'un d'autre (appareil partagé, perdu, cookie copié) ne doit pas pouvoir
 * se l'approprier. Le nom seul reste libre ; le champ `current_password` est
 * alors écarté, quoi qu'il porte.
 *
 * Les minuscules ne sont exigées, elles aussi, que d'une adresse qui change,
 * comme la forme de l'adresse (règle `email`, #2075). GitHub rend l'adresse
 * avec sa casse à l'inscription, et le panneau ne l'impose pas ; la connexion
 * sociale, avant #2075, créait le compte de toute adresse en ASCII
 * imprimable, points consécutifs ou tiret bas dans le domaine compris. Jugée
 * sur une adresse inchangée, l'une ou l'autre règle bloquerait le seul nom, et
 * corriger l'adresse, c'est la changer, donc donner le mot de passe, qu'un
 * compte relié à un fournisseur peut n'avoir jamais choisi.
 *
 * Les essais manqués avancent le compteur du changement de mot de passe
 * (`UpdatePasswordRequest::throttleKey()`), que seul un mot de passe accepté
 * remet à zéro : un enregistrement du seul nom n'y touche pas, sans quoi il
 * suffirait d'en glisser un entre deux essais. Les deux formulaires évaluent
 * le même mot de passe : un compteur propre à l'adresse donnerait à une
 * session ouverte par quelqu'un d'autre cinq essais de plus chaque minute,
 * une fois ceux du mot de passe épuisés.
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * La requête ne vérifie que la connexion ; l'autorisation vit dans le
     * contrôleur, et son refus est rendu en 404 par bootstrap/app.php.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Une nouvelle adresse s'écrit en ASCII imprimable, comme à l'inscription
     * (`AdresseEnAsciiImprimable`), et sous une forme que la messagerie écrit
     * telle quelle, sans quoi le lien de confirmation ne lui parviendrait pas
     * (`AdresseQueLaMessagerieSaitEcrire`, #2075) ; l'adresse actuelle du
     * compte reste admise telle quelle par l'une et l'autre, et la règle
     * `email` ne juge qu'une adresse qui change, pour qu'un compte plus ancien
     * enregistre son nom. Aucune règle ne regarde si la nouvelle adresse a
     * déjà un compte : la réponse le dirait, et le refus d'une adresse que la
     * messagerie n'écrit pas est le message de toute adresse mal formée. Le
     * changement attend le lien envoyé à cette adresse
     * (`TraiterUnChangementDAdresse`), qui prévient au contraire son titulaire
     * quand elle est prise. `bail` tient `current_password`
     * derrière `string` : la règle passe la valeur telle quelle à
     * `password_verify()`, et un tableau y levait une erreur 500 (#2071).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                Rule::when($this->changeLAdresse(), ['lowercase', 'email']),
                'max:255',
                new AdresseEnAsciiImprimable($this->compte()?->email),
                new AdresseQueLaMessagerieSaitEcrire($this->compte()?->email),
            ],
            'current_password' => $this->changeLAdresse()
                ? ['bail', 'required', 'string', 'current_password:web', 'max:255']
                : ['exclude'],
        ];
    }

    /**
     * Un compte relié à un fournisseur de connexion peut ne connaître aucun
     * mot de passe : le message lui donne le chemin plutôt qu'un refus sec.
     * « Mot de passe oublié ? » n'est ouvert qu'aux invités, d'où la
     * déconnexion d'abord ; le lien part à l'adresse actuelle du compte, quand
     * la messagerie l'écrit (`cheminVersUnMotDePasse()`).
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        $fournisseur = $this->compte()?->fournisseurDeConnexion();

        if ($fournisseur === null) {
            return [
                'current_password.required' => 'Ton mot de passe actuel est demandé pour changer d’adresse.',
                'current_password.current_password' => 'Ce mot de passe ne correspond pas à ton compte.',
            ];
        }

        $chemin = "Ton compte est relié à {$fournisseur}. Si tu n’as jamais choisi de mot de passe, ".$this->cheminVersUnMotDePasse();

        return [
            'current_password.required' => $chemin,
            'current_password.current_password' => 'Ce mot de passe ne correspond pas à ton compte. '.$chemin,
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->has('current_password')) {
                RateLimiter::hit($this->throttleKey());
            }
        });
    }

    /**
     * La clé de `UpdatePasswordRequest::throttleKey()` : les deux formulaires
     * partagent le même budget d'essais.
     */
    public function throttleKey(): string
    {
        return 'update-password-'.$this->user()?->id;
    }

    /**
     * L'adresse demandée diffère-t-elle de celle du compte ?
     *
     * Comparée telle quelle, comme `isDirty('email')` la comparera à
     * l'enregistrement : un changement de casse est un changement d'adresse.
     */
    public function changeLAdresse(): bool
    {
        $adresseDemandee = $this->input('email');

        return is_string($adresseDemandee) && $adresseDemandee !== $this->compte()?->email;
    }

    /**
     * Coupe la requête avant validation quand le compteur d'essais est plein,
     * seulement si elle change l'adresse : le nom reste modifiable.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        if (! $this->changeLAdresse() || ! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'current_password' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    #[\Override]
    protected function passedValidation(): void
    {
        if ($this->changeLAdresse()) {
            RateLimiter::clear($this->throttleKey());
        }
    }

    /**
     * Comment un compte relié à un fournisseur obtient un mot de passe.
     *
     * Le lien « mot de passe oublié » ne part pas à une adresse que la
     * messagerie n'écrit pas telle quelle, qu'un compte d'avant #2075 peut
     * porter (`User::routeNotificationForMail()`), et la demande refuse même
     * des points consécutifs ou un point final (règle `email`) : le message ne
     * le promet pas, et renvoie à un administrateur, qui change l'adresse au
     * panneau.
     */
    private function cheminVersUnMotDePasse(): string
    {
        if ($this->compte()?->laMessagerieSaitEcrireSonAdresse() === false) {
            return 'aucun lien ne peut t’en donner un : notre messagerie ne sait pas écrire à ton adresse actuelle. Demande à un administrateur de l’application de la changer.';
        }

        return 'choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien part à ton adresse actuelle.';
    }

    private function compte(): ?User
    {
        $compte = $this->user();

        return $compte instanceof User ? $compte : null;
    }
}
