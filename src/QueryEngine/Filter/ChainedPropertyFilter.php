<?php


namespace WSSearch\QueryEngine\Filter;

use Elasticsearch\ClientBuilder;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use WSSearch\QueryEngine\Factory\QueryEngineFactory;
use WSSearch\SearchEngineException;
use WSSearch\SMW\PropertyFieldMapper;

/**
 * Class ChainedPropertyTermsFilter
 *
 * This class is used to allow searching of property chains. It takes an initial filter and a property, and
 * recursively constructs a new filter from the results of the initial filter until the end of the
 * property chain is reached.
 *
 * @package WSSearch\QueryEngine\Filter
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/6.8//query-dsl-terms-query.html
 */
class ChainedPropertyFilter implements Filter {
    /**
     * @var PropertyFieldMapper The property to filter on
     */
    private $property;

    /**
     * @var Filter The initial filter to use to get the terms for the to be constructed Terms filter
     */
    private $filter;

    /**
     * ChainedPropertyTermsFilter constructor.
     *
     * @param Filter $filter The initial filter to use to get the values for the to be constructed Terms filter
     * @param PropertyFieldMapper $property The property to filter on; if this property is also a property
     * chain, the class will return a ChainedPropertyTermsFilter containing the constructed TermsFilter
     */
    public function __construct( Filter $filter, PropertyFieldMapper $property ) {
        $this->filter = $filter;
        $this->property = $property;
    }

    /**
     * @inheritDoc
     *
     * @return BoolQuery
     * @throws \MWException
     * @throws SearchEngineException
     */
    public function toQuery(): BoolQuery {
        $query = $this->constructSubqueryFromFilter( $this->filter );
        $terms = $this->getTermsFromSubquery( $query );

        $filter = new PagesPropertyFilter( $this->property, $terms );

        if ( !$this->property->isChained() ) {
            return $filter->toQuery();
        }

        return ( new ChainedPropertyFilter(
            $filter,
            $this->property->getChainedPropertyFieldMapper()
        ) )->toQuery();
    }

    /**
     * Constructs a new ElasticSearch subquery from the given Filter.
     *
     * @param Filter $filter
     * @return array
     * @throws \MWException
     */
    private function constructSubqueryFromFilter( Filter $filter ): array {
        $query_engine = QueryEngineFactory::fromNull();
        $query_engine->addConstantScoreFilter( $filter );
        $query_engine->setLimit( 9999 );

        return $query_engine->toArray();
    }

    /**
     * Executes the given (sub)query and returns the terms extracted from it.
     *
     * @param array $query
     * @return array
     */
    private function getTermsFromSubquery( array $query ): array {
        $results = ClientBuilder::create()
            ->setHosts( QueryEngineFactory::fromNull()->getElasticHosts() )
            ->build()
            ->search( $query );
        $hits = $results["hits"]["hits"];

        return array_map( function ( array $hit ): int {
            /*
             * Below is an example of what $hit may look like:
             *
             * {
             *      "_index": "smw-data-csp_wikibase_nl-v2",
             *      "_type": "data",
             *      "_id": "2673",
             *      "_score": 1
             * }
             */

            return intval( $hit["_id"] );
        }, $hits );
    }
}