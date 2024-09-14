<?php

declare(strict_types=1);

/*
 * This file is part of the Schranz Search package.
 *
 * (c) Alexander Schranz <alexander@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Schranz\Search\SEAL\Adapter\Algolia;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Schranz\Search\SEAL\Adapter\SearcherInterface;
use Schranz\Search\SEAL\Marshaller\Marshaller;
use Schranz\Search\SEAL\Schema\Index;
use Schranz\Search\SEAL\Search\Condition;
use Schranz\Search\SEAL\Search\Result;
use Schranz\Search\SEAL\Search\Search;

final class AlgoliaSearcher implements SearcherInterface
{
    private readonly Marshaller $marshaller;

    public function __construct(
        private readonly SearchClient $client,
    ) {
        $this->marshaller = new Marshaller(
            geoPointFieldConfig: [
                'name' => '_geoloc',
                'latitude' => 'lat',
                'longitude' => 'lng',
            ],
        );
    }

    public function search(Search $search): Result
    {
        // optimized single document query
        if (
            1 === \count($search->indexes)
            && 1 === \count($search->filters)
            && $search->filters[0] instanceof Condition\IdentifierCondition
            && 0 === $search->offset
            && 1 === $search->limit
        ) {
            $index = $search->indexes[\array_key_first($search->indexes)];
            $identifierField = $index->getIdentifierField();

            try {
                /** @var array<string, mixed> $data */
                $data = $this->client->getObject(
                    $index->name,
                    $search->filters[0]->identifier,
                );
            } catch (NotFoundException) {
                return new Result(
                    $this->hitsToDocuments($search->indexes, []),
                    0,
                );
            }

            return new Result(
                $this->hitsToDocuments($search->indexes, [$data]),
                1,
            );
        }

        if (1 !== \count($search->indexes)) {
            throw new \RuntimeException('Algolia Adapter does not yet support search multiple indexes: https://github.com/schranz-search/schranz-search/issues/41');
        }

        if (\count($search->sortBys) > 1) {
            throw new \RuntimeException('Algolia Adapter does not yet support search multiple indexes: https://github.com/schranz-search/schranz-search/issues/41');
        }

        $index = $search->indexes[\array_key_first($search->indexes)];
        $indexName = $index->name;

        $sortByField = \array_key_first($search->sortBys);
        if ($sortByField) {
            $indexName .= '__' . \str_replace('.', '_', $sortByField) . '_' . $search->sortBys[$sortByField];
        }

        $query = '';
        $filters = $geoFilters = [];
        foreach ($search->filters as $filter) {
            match (true) {
                $filter instanceof Condition\IdentifierCondition => $filters[] = $index->getIdentifierField()->name . ':' . $this->escapeFilterValue($filter->identifier),
                $filter instanceof Condition\SearchCondition => $query = $filter->query,
                $filter instanceof Condition\EqualCondition => $filters[] = $filter->field . ':' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\NotEqualCondition => $filters[] = 'NOT ' . $filter->field . ':' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\GreaterThanCondition => $filters[] = $filter->field . ' > ' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\GreaterThanEqualCondition => $filters[] = $filter->field . ' >= ' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\LessThanCondition => $filters[] = $filter->field . ' < ' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\LessThanEqualCondition => $filters[] = $filter->field . ' <= ' . $this->escapeFilterValue($filter->value),
                $filter instanceof Condition\GeoDistanceCondition => $geoFilters = [
                    'aroundLatLng' => \sprintf(
                        '%s, %s',
                        $this->escapeFilterValue($filter->latitude),
                        $this->escapeFilterValue($filter->longitude),
                    ),
                    'aroundRadius' => $filter->distance,
                ],
                default => throw new \LogicException($filter::class . ' filter not implemented.'),
            };
        }

        $searchParams = [];
        if ([] !== $filters) {
            $searchParams = ['filters' => \implode(' AND ', $filters)];
        }

        if ([] !== $geoFilters) {
            $searchParams += $geoFilters;
        }

        if (0 !== $search->offset) {
            $searchParams['offset'] = $search->offset;
        }

        if ($search->limit) {
            $searchParams['length'] = $search->limit;
            $searchParams['offset'] ??= 0; // length would be ignored without offset see: https://www.algolia.com/doc/api-reference/api-parameters/length/
        }

        if ('' !== $query) {
            $searchParams['query'] = $query;
        }

        $data = $this->client->searchSingleIndex($indexName, $searchParams);
        \assert(\is_array($data) && isset($data['hits']) && \is_array($data['hits']), 'The "hits" array is expected to be returned by algolia client.');
        \assert(isset($data['nbHits']) && \is_int($data['nbHits']), 'The "nbHits" value is expected to be returned by algolia client.');

        return new Result(
            $this->hitsToDocuments($search->indexes, $data['hits']),
            $data['nbHits'] ?? null, // @phpstan-ignore-line
        );
    }

    /**
     * @param Index[] $indexes
     * @param iterable<array<string, mixed>> $hits
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function hitsToDocuments(array $indexes, iterable $hits): \Generator
    {
        $index = $indexes[\array_key_first($indexes)];

        foreach ($hits as $hit) {
            // remove Algolia Metadata
            unset($hit['objectID']);
            unset($hit['_highlightResult']);

            yield $this->marshaller->unmarshall($index->fields, $hit);
        }
    }

    private function escapeFilterValue(string|int|float|bool $value): string
    {
        return match (true) {
            \is_string($value) => '"' . \addslashes($value) . '"',
            \is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
