<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faire remonter le proprietaire dans la requete qui lie deja le modele.
 *
 * Une policy qui ecrit `$set->workoutLine->workout->user_id` demande deux
 * requetes de plus que la liaison, et seulement quand la ressource existe. Un
 * identifiant inconnu sort a la premiere requete : la ressource d'autrui coute
 * mesurablement plus cher que la ressource absente, alors que #1418 s'est
 * applique a rendre les deux reponses indiscernables. Le statut, le corps et
 * les en-tetes concordaient ; la duree, non — voir #1433.
 *
 * Le canal se ferme en amont plutot qu'en aval : on n'egalise pas en faisant
 * travailler le chemin absent pour rien, on supprime le travail supplementaire
 * du chemin present. La liaison de modele ramene les colonnes du proprietaire
 * en sous-requetes correlees, dans la meme requete ; la policy n'a plus qu'a
 * lire un attribut. Les deux chemins retombent a une requete, et le chemin
 * nominal — celui du proprietaire legitime — y gagne autant que l'intrus.
 *
 * Les jointures ne sont pas ecrites a la main mais deduites des relations
 * `belongsTo` que la policy traversait : une jointure recopiee pourrait diverger
 * de la relation sans que rien ne le signale, alors qu'ici il n'y a qu'une
 * source. C'est aussi ce qui permet au repli — une instance qui n'est pas venue
 * de la liaison de route n'a pas les colonnes — de rester exactement l'ancien
 * comportement.
 */
trait ResolvesOwnerAtRouteBinding
{
    /**
     * Le chemin de relations `belongsTo` menant au proprietaire, ex. `workoutLine.workout`.
     */
    abstract protected function ownershipPath(): string;

    /**
     * Les colonnes du proprietaire dont les policies ont besoin, par alias.
     *
     * @return array<string, string>
     */
    protected function ownershipColumns(): array
    {
        return ['owner_user_id' => 'user_id'];
    }

    /**
     * L'identifiant du proprietaire, ou `null` s'il n'y en a plus.
     *
     * Nul est un etat atteignable et non une anomalie : la chaine peut se
     * rompre entre la resolution du modele et la lecture de la policy — la
     * suppression d'un maillon en `ON DELETE CASCADE` laisse une requete lente
     * avec une instance dont la relation ne renvoie plus rien. Un proprietaire
     * introuvable n'est proprietaire de personne, donc l'acces est refuse.
     */
    public function ownerUserId(): ?int
    {
        $value = $this->ownershipValue('owner_user_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * La valeur d'une colonne du proprietaire, lue au meilleur cout disponible.
     *
     * `array_key_exists` plutot qu'un test de nullite : la sous-requete rend
     * `null` quand la chaine est rompue, ce qui est une reponse et non une
     * absence de reponse. Les confondre relancerait la traversee par relations
     * — donc les requetes que ce trait existe pour supprimer — precisement dans
     * le cas ou elle ne rendrait rien non plus.
     */
    protected function ownershipValue(string $alias): mixed
    {
        $attributes = $this->getAttributes();

        if (array_key_exists($alias, $attributes)) {
            return $attributes[$alias];
        }

        $column = $this->ownershipColumns()[$alias] ?? null;

        if ($column === null) {
            return null;
        }

        return $this->ownershipValueByRelation($column);
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    #[\Override]
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $subqueries = $this->ownershipSubqueries();

        $model = $this->resolveRouteBindingQuery($this, $value, $field)
            ->select($this->getTable().'.*')
            ->addSelect($subqueries)
            ->first();

        if (! $model instanceof Model) {
            return null;
        }

        // Les alias sont une commodite d'autorisation, pas des champs du
        // modele : ils ne doivent pas ressortir dans une serialisation.
        return $model->makeHidden(array_keys($subqueries));
    }

    /**
     * Une sous-requete correlee par colonne demandee.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>>
     */
    private function ownershipSubqueries(): array
    {
        $subqueries = [];

        foreach ($this->ownershipColumns() as $alias => $column) {
            $subqueries[$alias] = $this->ownershipSubquery($column);
        }

        return $subqueries;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function ownershipSubquery(string $column): \Illuminate\Database\Eloquent\Builder
    {
        $relations = $this->ownershipRelations();
        $owner = end($relations);

        if ($owner === false) {
            throw new \LogicException(static::class.' declares an empty ownership path.');
        }

        $query = $owner->getRelated()->newQuery()
            ->select($owner->getRelated()->getTable().'.'.$column);

        // Les maillons intermediaires sont recousus a l'envers, du proprietaire
        // vers ce modele, pour que la correlation porte sur le premier maillon.
        for ($index = count($relations) - 1; $index >= 1; $index--) {
            $child = $relations[$index - 1]->getRelated();

            $query->join(
                $child->getTable(),
                $child->getTable().'.'.$relations[$index]->getForeignKeyName(),
                '=',
                $relations[$index]->getRelated()->getTable().'.'.$relations[$index]->getOwnerKeyName(),
            );
        }

        $first = $relations[0];

        return $query
            ->whereColumn(
                $first->getRelated()->getTable().'.'.$first->getOwnerKeyName(),
                $this->getTable().'.'.$first->getForeignKeyName(),
            )
            ->limit(1);
    }

    /**
     * Le chemin declare, resolu en relations `belongsTo`.
     *
     * @return list<BelongsTo<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>>
     */
    private function ownershipRelations(): array
    {
        $relations = [];
        $model = $this;

        foreach (explode('.', $this->ownershipPath()) as $name) {
            // Passe par le builder plutot que par un appel de methode dynamique :
            // il resout la relation par son nom, sans contraintes, et signale
            // lui-meme un nom qui ne correspond a rien.
            $relation = $model->newQuery()->getRelation($name);

            if (! $relation instanceof BelongsTo) {
                throw new \LogicException(sprintf(
                    '%s::%s() must be a belongsTo relation to carry ownership.',
                    $model::class,
                    $name,
                ));
            }

            $relations[] = $relation;
            $model = $relation->getRelated();
        }

        return $relations;
    }

    /**
     * Le repli : la traversee par relations, telle qu'elle se faisait avant.
     *
     * Elle sert aux instances qui ne viennent pas de la liaison de route — une
     * fabrique, un `find()`, un modele deja en memoire — pour lesquelles il n'y
     * a aucun canal a fermer puisqu'aucune requete HTTP ne les a nommees.
     */
    private function ownershipValueByRelation(string $column): mixed
    {
        $model = $this;

        foreach (explode('.', $this->ownershipPath()) as $name) {
            $related = $model->getAttribute($name);

            if (! $related instanceof Model) {
                return null;
            }

            $model = $related;
        }

        return $model->getAttribute($column);
    }
}
