<?php

namespace Colada;

class IteratorCollection
    implements Collection, \Countable, \IteratorAggregate
{
    /**
     * @var \Iterator
     */
    protected $collection;

    public function __construct(\Iterator $collection = null)
    {
        $this->collection = $collection;
    }

    protected static function createCollectionBuilder($sizeHint = 0)
    {
        return new CollectionBuilder($sizeHint);
    }

    public function isEmpty()
    {
        return ($this->count() > 0);
    }

    // TODO Binary search for sorted collections.
    public function contains($element)
    {
        foreach ($this->collection as $collectionElement) {
            if (ComparisonHelper::isEquals($element, $collectionElement)) {
                return true;
            }
        }

        return false;
    }

    public function count()
    {
        if ($this->collection instanceof \Countable) {
            return count($this->collection);
        } else {
            return iterator_count($this->collection);
        }
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @param int $offset
     * @param int $length
     *
     * @return Collection
     */
    public function slice($offset, $length)
    {
        if (!is_int($offset) || !is_int($length) ) {
            throw new \InvalidArgumentException();
        }

        if (($offset < 0) || ($length < 0)) {
            throw new \InvalidArgumentException();
        }

        return static::createCollectionBuilder($length)
            ->addAll(new \LimitIterator($this->collection, $offset, $length))
            ->build();
    }

    /**
     * @return \Iterator
     */
    public function getIterator()
    {
        // Ensure immutable.
        return new IteratorProxy($this->collection);
    }

    /**
     * A convenient version of what is perhaps the most common use-case for map: extracting a list of property values.
     *
     * @param $key
     *
     * @return Collection
     */
    public function pluck($key)
    {
        $builder = new CollectionBuilder(count($this));

        foreach ($this->collection as $element) {
            if (is_array($element) || (is_object($element) && ($element instanceof \ArrayAccess))) {
                if (isset($element[$key])) {
                    $builder->add($element[$key]);
                }
            }

            if (is_object($element) && ($element instanceof Map)) {
                $element->get($key)->map(function($element) use ($builder) { $builder->add($element); });
            }
        }

        return $builder->build();
    }

    public function eachBy(callable $processor)
    {
        foreach ($this as $element) {
            $processor($element);
        }
    }

    /**
     * Return first element from collection, that match $filter.
     *
     * @param callable $finder
     *
     * @return Option
     */
    public function findBy(callable $finder)
    {
        foreach ($this->collection as $element) {
            if ($finder($element)) {
                return new Some($element);
            }
        }

        return new None();
    }

    /**
     * Lazy.
     *
     * @param callable $filter
     *
     * @return Collection
     */
    public function filterBy(callable $filter)
    {
        return new static(
            new CollectionFilterIterator(
                $this->collection,
                $filter
            )
        );
    }

    /**
     * Lazy.
     *
     * @param callable $filter
     *
     * @return Collection
     */
    public function rejectBy(callable $filter)
    {
        return $this->filterBy(function($element) use($filter) { return !$filter($element); });
    }

    /**
     * Lazy.
     *
     * @param callable|mixed $mapper
     *
     * @return Collection
     */
    public function mapBy($mapper)
    {
        if (!is_callable($mapper)) {
            $element = $mapper;

            $mapper = function() use($element) { return $element; };
        }

        return new static(
            new CollectionMapIterator(
                $this->collection,
                $mapper
            )
        );
    }

    /**
     * Lazy.
     *
     * @see http://www.scala-lang.org/api/current/scala/collection/immutable/Set.html
     *
     * @param callable|Traversable $mapper
     *
     * @return Collection
     */
    public function flatMapBy($mapper)
    {
        if (!is_callable($mapper)) {
            if (is_object($mapper) && ($mapper instanceof \Traversable)) {
                $elements = $mapper;

                $mapper = function() use($elements) { return $elements; };
            } else {
                throw new \InvalidArgumentException();
            }
        }

        return new static(
            new CollectionFlatMapIterator(
                $this->collection,
                $mapper
            )
        );
    }

    /**
     * @param callable $folder
     * @param mixed    $accumulator
     *
     * @return mixed
     */
    public function foldBy(callable $folder, $accumulator)
    {
        // PHP don't support tail recursion optimization...
        foreach ($this->collection as $element) {
            $accumulator = $folder($accumulator, $element);
        }

        return $accumulator;
    }

    /**
     * Good introduction to reduce: http://www.codecommit.com/blog/scala/scala-collections-for-the-easily-bored-part-2.
     *
     * @throws \Exception On empty collection.
     *
     * @param callable $reducer
     *
     * @return mixed
     */
    public function reduceBy(callable $reducer)
    {
        if (count($this) == 0) {
            throw new \Exception('Unable reduce empty collection.');
        }

        if (count($this) == 1) {
            foreach ($this->collection as $element) {
                return $element;
            }
        }

        $this->collection->rewind();

        $reduced = $this->collection->current();
        $this->collection->next();

        while ($this->collection->valid()) {
            $element = $this->collection->current();

            $reduced = $reducer($reduced, $element);

            $this->collection->next();
        }

        return $reduced;
    }

    /**
     * @param callable $partitioner
     *
     * @return array
     */
    public function partitionBy(callable $partitioner)
    {
        $builder1 = static::createCollectionBuilder($this->collection);
        $builder2 = static::createCollectionBuilder($this->collection);
        foreach ($this->collection as $element) {
            if ($partitioner($element)) {
                $builder1->add($element);
            } else {
                $builder2->add($element);
            }
        }

        return array($builder1->build(), $builder2->build());
    }

    public function sortBy(callable $comparator)
    {
        $heap = new CustomHeap($comparator);
        foreach ($this->collection as $element) {
            $heap->insert($element);
        }

        return static::createCollectionBuilder($heap)->addAll($heap)->build();
    }

    /**
     * @param callable $keyFinder
     *
     * @return Map
     */
    public function groupBy(callable $keyFinder)
    {
        return $this->foldBy(
            function($multimapBuilder, $element) use($keyFinder) {
                return $multimapBuilder->add($keyFinder($element), $element);
            },
            new MultimapBuilder(static::createCollectionBuilder())
        )->build();
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return iterator_to_array($this->collection);
    }
}