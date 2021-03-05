<?php

namespace WSSearch\QueryEngine;

use MediaWiki\MediaWikiServices;
use ONGR\ElasticsearchDSL\Highlight\Highlight;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\Compound\ConstantScoreQuery;
use ONGR\ElasticsearchDSL\Search;
use WSSearch\QueryEngine\Aggregation\Aggregation;
use WSSearch\QueryEngine\Aggregation\PropertyAggregation;
use WSSearch\QueryEngine\Filter\Filter;
use WSSearch\QueryEngine\Filter\PropertyFilter;
use WSSearch\SearchEngineConfig;
use WSSearch\SMW\SMWQueryProcessor;

class QueryEngine {
    /**
     * @var Search
     */
    private $elasticsearch_search;

    /**
     * The "index" to use for the ElasticSearch query.
     *
     * @var string
     */
    private $elasticsearch_index;

    /**
     * The main boolean query filter.
     *
     * @var BoolQuery
     */
    private $filters;

    /**
     * The base ElasticSearch query.
     *
     * @var array|null
     */
    private $base_query = null;

    /**
     * QueryEngine constructor.
     *
     * @param string $index The ElasticSearch index to create the queries for
     */
    public function __construct( string $index ) {
        $this->elasticsearch_index = $index;
        $this->elasticsearch_search = new Search();

        $config = MediaWikiServices::getInstance()->getMainConfig();

        $highlight = new Highlight();
        $highlight->setTags( ["<b>"], ["</b>"] );
        $highlight->addField( "text_raw", [
            "fragment_size" => $config->get( "WSSearchHighlightFragmentSize" ),
            "number_of_fragments" => $config->get( "WSSearchHighlightNumberOfFragments" )
        ] );

        $this->filters = new BoolQuery();
        $constant_score_query = new ConstantScoreQuery( $this->filters );

        $this->elasticsearch_search->setSize( $config->get( "WSSearchDefaultResultLimit" ) );
        $this->elasticsearch_search->addHighlight( $highlight );
        $this->elasticsearch_search->addQuery( $constant_score_query );
    }

    /**
     * Constructs a new QueryEngine from the given SearchEngineConfig.
     *
     * @param SearchEngineConfig $config
     * @return QueryEngine
     */
    public static function newFromConfig( SearchEngineConfig $config ) {
        $mw_config = MediaWikiServices::getInstance()->getMainConfig();
        $index = $mw_config->get( "WSSearchElasticStoreIndex" ) ?: "smw-data-" . strtolower( wfWikiID() );

        $query_engine = new QueryEngine( $index );

        foreach ( $config->getFacetProperties() as $facet_property ) {
            $translation_pair = explode( "=", $facet_property );
            $property_name = $translation_pair[0];

            $query_engine->addAggregation( new PropertyAggregation( $property_name ) );
        }

        $search_parameters = $config->getSearchParameters();
        if ( isset( $search_parameters["base query"] ) ) {
            $query_engine->setBaseQuery( $search_parameters["base query"] );
        }

        return $query_engine;
    }

    /**
     * Adds aggregations to the query.
     *
     * @param Aggregation[] $aggregations
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.6/search-aggregations.html
     */
    public function addAggregations( array $aggregations ) {
        foreach ( $aggregations as $aggregation ) {
            $this->addAggregation( $aggregation );
        }
    }

    /**
     * Adds an aggregation to the query.
     *
     * @param Aggregation $aggregation
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.6/search-aggregations.html
     */
    public function addAggregation( Aggregation $aggregation ) {
        $this->elasticsearch_search->addAggregation( $aggregation->toQuery() );
    }

    /**
     * Adds filters to apply to the query.
     *
     * @param Filter[] $filters
     * @param string $occur The occurrence type for the added filters (should be a BoolQuery constant)
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.6/query-dsl-bool-query.html
     */
    public function addFilters( array $filters, string $occur = BoolQuery::MUST ) {
        foreach ( $filters as $filter ) {
            $this->addFilter( $filter, $occur );
        }
    }

    /**
     * Adds a filter to apply to the query.
     *
     * @param Filter $filter
     * @param string $occur The occurrence type for the added filter (should be a BoolQuery constant)
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.6/query-dsl-bool-query.html
     */
    public function addFilter( Filter $filter, string $occur = BoolQuery::MUST ) {
        $this->filters->add( $filter->toQuery(), $occur );
    }

    /**
     * Sets the "index" to use for the ElasticSearch query.
     *
     * @param string $index
     */
    public function setIndex( string $index ) {
        $this->elasticsearch_index = $index;
    }

    /**
     * Sets the offset for the search (i.e. the first n results to discard).
     *
     * @param int $offset
     */
    public function setOffset( int $offset ) {
        $this->elasticsearch_search->setFrom( $offset );
    }

    /**
     * Sets the (maximum) number of results to return.
     *
     * @param int $limit
     */
    public function setLimit( int $limit ) {
        $this->elasticsearch_search->setSize( $limit );
    }

    /**
     * Sets the base Semantic MediaWiki query.
     *
     * @param $base_query
     */
    private function setBaseQuery( string $base_query ) {
        try {
            $query_processor = new SMWQueryProcessor( $base_query );
            $elastic_search_query = $query_processor->toElasticSearchQuery();
        } catch( \MWException $exception ) {
            // The query is invalid
            return;
        }

        $this->base_query = $elastic_search_query[0];
    }

    /**
     * Returns the "Search" object. Can be used to alter the query directly.
     *
     * @return Search
     */
    public function _(): Search {
        return $this->elasticsearch_search;
    }

    /**
     * Converts this class into a full ElasticSearch query.
     *
     * @return array A complete ElasticSearch query
     * @throws \MWException
     */
    public function toArray(): array {
        $query = [
            "index" => $this->elasticsearch_index,
            "body" => $this->elasticsearch_search->toArray()
        ];

        if ( isset( $this->base_query ) ) {
            $query_combinator = new QueryCombinator( $query );
            return $query_combinator->add( $this->base_query )->getQuery();
        }

        return $query;
    }
}