<?php

namespace TrafficOps\ModelEvents\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Expression;

/** The journal uses string owner keys even when the owning table uses bigint/UUID. */
class OwnerEventsRelation extends MorphMany
{
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($query->getConnection()->getDriverName() !== 'pgsql') {
            return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        }
        $key = $query->getQuery()->getGrammar()->wrap($this->getQualifiedParentKeyName());

        return $query->select($columns)
            ->whereColumn(new Expression('CAST('.$key.' AS TEXT)'), '=', $this->getExistenceCompareKey())
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
