<?php

namespace Spatie\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use Spatie\Searchable\Exceptions\InvalidModelSearchAspect;
use Spatie\Searchable\Exceptions\InvalidSearchableModel;

class ModelSearchAspect extends SearchAspect
{
    use ForwardsCalls;

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $model;

    /** @var array */
    protected $attributes = [];

    /** @var array */
    protected $callsToForward = [];

    public static function forModel(string $model, ...$attributes): self
    {
        return new self($model, $attributes);
    }

    /**
     * @param string $model
     * @param array|\Closure $attributes
     *
     * @throws \Spatie\Searchable\Exceptions\InvalidSearchableModel
     */
    public function __construct(string $model, $attributes = [])
    {
        if (! is_subclass_of($model, Model::class)) {
            throw InvalidSearchableModel::notAModel($model);
        }

        if (! is_subclass_of($model, Searchable::class)) {
            throw InvalidSearchableModel::modelDoesNotImplementSearchable($model);
        }

        $this->model = $model;

        if (is_array($attributes)) {
            $this->attributes = SearchableAttribute::createMany($attributes);

            return;
        }

        if (is_string($attributes)) {
            $this->attributes = SearchableAttribute::create($attributes);

            return;
        }

        if (is_callable($attributes)) {
            $callable = $attributes;

            $callable($this);

            return;
        }
    }

    public function addSearchableAttribute(string $attribute, bool $partial = true): self
    {
        $this->attributes[] = SearchableAttribute::create($attribute, $partial);

        return $this;
    }

    public function addExactSearchableAttribute(string $attribute): self
    {
        $this->attributes[] = SearchableAttribute::createExact($attribute);

        return $this;
    }

    public function getType(): string
    {
        $model = new $this->model();

        if (property_exists($model, 'searchableType')) {
            return $model->searchableType;
        }

        return $model->getTable();
    }

    public function getResults(string $term): Collection
    {
        if (empty($this->attributes)) {
            throw InvalidModelSearchAspect::noSearchableAttributes($this->model);
        }

        $query = ($this->model)::query();

        foreach ($this->callsToForward as $callToForward) {
            $this->forwardCallTo($query, $callToForward['method'], $callToForward['parameters']);
        }

        $this->addSearchConditions($query, $term);

        if ($this->limit) {
            $query->limit($this->limit);
        }

        return $query->get();
    }

     protected function addSearchConditions(Builder $query, string $term)
    {
        $attributes = $this->attributes;
        $searchTerms = explode(' ', $term);

        $searchTerms = collect($searchTerms)->map(function ($q){
            return trim($q, " \t\n\r\0\x0B'\"-_)(*&^%$#@!?/><.,:;}]{[|\\+=~`");
        })->filter();


        $searchTerms->map(function ($searchTerm) use ($attributes, $query) {
            $query->where(function (Builder $query) use ($attributes, $searchTerm) {
                foreach (Arr::wrap($attributes) as $attribute) {
                    $sql = "LOWER(`{$attribute->getAttribute()}`) LIKE ?";
                    $searchTerm = mb_strtolower($searchTerm, 'UTF8');

                    $attribute->isPartial()
                        ? $query->orWhereRaw($sql, ["%{$searchTerm}%"])
                        : $query->orWhere($attribute->getAttribute(), $searchTerm);
                }
            });
        });
    }

    public function __call($method, $parameters)
    {
        $this->callsToForward[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        return $this;
    }
}
