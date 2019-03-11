<?php

namespace Minds\Core\Feeds\Top;

use Minds\Core\Data\ElasticSearch\Client as ElasticsearchClient;
use Minds\Core\Di\Di;
use Minds\Core\Data\ElasticSearch\Prepared;
use Minds\Core\Search\SortingAlgorithms;
use Minds\Helpers\Text;

class Repository
{
    /** @var ElasticsearchClient */
    protected $client;

    /** @var array $pendingBulkInserts * */
    private $pendingBulkInserts = [];

    public function __construct($client = null)
    {
        $this->client = $client ?: Di::_()->get('Database\ElasticSearch');
    }

    /**
     * @param array $opts
     * @return \Generator|ScoredGuid[]
     * @throws \Exception
     */
    public function getList(array $opts = [])
    {
        $opts = array_merge([
            'offset' => 0,
            'limit' => 12,
            'container_guid' => null,
            'custom_type' => null,
            'hashtags' => [],
            'filter_hashtags' => true,
            'type' => null,
            'period' => null,
            'algorithm' => null,
            'rating' => 1,
        ], $opts);

        if (!$opts['type']) {
            throw new \Exception('Type must be provided');
        }

        if (!$opts['algorithm']) {
            throw new \Exception('Algorithm must be provided');
        }

        if (!in_array($opts['period'], ['12h', '24h', '7d', '30d', '1y'])) {
            throw new \Exception('Unsupported period');
        }

        $body = [
            '_source' => [
                'guid',
            ],
            'query' => [
                'function_score' => [
                    'query' => [
                        'bool' => [
                            'must_not' => [
                                'term' => [
                                    'mature' => true,
                                ],
                            ],
                        ],
                    ],
                    "score_mode" => "sum",
                    'functions' => [
                        [
                            'filter' => [
                                'match_all' => (object) []
                            ],
                            'weight' => 1
                        ]
                    ],
                ]
            ],
            'sort' => [],
        ];

        if ($opts['container_guid']) {
            $containerGuids = Text::buildArray($opts['container_guid']);

            if (!isset($body['query']['function_score']['query']['bool']['must'])) {
                $body['query']['function_score']['query']['bool']['must'] = [];
            }

            $body['query']['function_score']['query']['bool']['must'][] = [
                'terms' => [
                    'container_guid' => $containerGuids,
                ],
            ];
        }

        if ($opts['custom_type']) {
            $customTypes = Text::buildArray($opts['custom_type']);

            if (!isset($body['query']['function_score']['query']['bool']['must'])) {
                $body['query']['function_score']['query']['bool']['must'] = [];
            }

            $body['query']['function_score']['query']['bool']['must'][] = [
                'terms' => [
                    'custom_type' => $customTypes,
                ],
            ];
        }

        if ($opts['rating']) {
            $body['query']['function_score']['query']['bool']['must'][] = [
                'range' => [
                    'rating' => [
                        'lte' => (int) $opts['rating'],
                    ],
                ],
            ];
        }

        //

        if ($opts['hashtags']) {
            if ($opts['filter_hashtags']) {
                if (!isset($body['query']['function_score']['query']['bool']['must'])) {
                    $body['query']['function_score']['query']['bool']['must'] = [];
                }

                $body['query']['function_score']['query']['bool']['must'][] = [
                    'terms' => [
                        'tags' => $opts['hashtags'],
                    ],
                ];
            } else {
                $body['query']['function_score']['functions'][] = [
                    'filter' => [
                        'multi_match' => [
                            'query' => implode(' ', $opts['hashtags']),
                            'fields' => ['tags']
                        ]
                    ],
                    'weight' => 100
                ];
                $body['query']['function_score']['functions'][] = [
                    'filter' => [
                        'multi_match' => [
                            'query' => implode(' ', $opts['hashtags']),
                            'fields' => ['message']
                        ]
                    ],
                    'weight' => 10
                ];
            }
        }

        //

        switch ($opts['algorithm']) {
            case "top":
                $algorithm = new SortingAlgorithms\Top();
                break;
            case "controversial":
                $algorithm = new SortingAlgorithms\Controversial();
                break;
            case "hot":
                $algorithm = new SortingAlgorithms\Hot();
                break;
            case "latest":
            default:
                $algorithm = new SortingAlgorithms\Chronological();
                break;
        }

        $algorithm->setPeriod($opts['period']);

        //

        $esQuery = $algorithm->getQuery();
        if ($esQuery) {
            $body['query']['function_score']['query'] = array_merge_recursive($body['query']['function_score']['query'], $esQuery);
        }

        //

        $esScript = $algorithm->getScript();
        if ($esScript) {
            $body['query']['function_score']['functions'][] = [
                'script_score' => [
                    'script' => [
                        'source' => $esScript
                    ]
                ]
            ];
        }

        //
        $esSort = $algorithm->getSort();
        if ($esSort) {
            $body['sort'][] = $esSort;
        }

        //

        $query = [
            'index' => 'minds_badger',
            'type' => $opts['type'],
            'body' => $body,
            'size' => $opts['limit'],
            'from' => $opts['offset'],
        ];

        $prepared = new Prepared\Search();
        $prepared->query($query);

        $response = $this->client->request($prepared);

        foreach ($response['hits']['hits'] as $doc) {
            yield (new ScoredGuid())
                ->setGuid($doc['_source']['guid'])
                ->setScore($doc['_score']);
        }
    }

    public function add(MetricsSync $metric)
    {
        $body = [];

        $key = $metric->getMetric() . ':' . $metric->getPeriod();
        $body[$key] = $metric->getCount();

        $body[$key . ':synced'] = $metric->getSynced();

        $this->pendingBulkInserts[] = [
            'update' => [
                '_id' => (string) $metric->getGuid(),
                '_index' => 'minds_badger',
                '_type' => $metric->getType(),
            ],
        ];

        $this->pendingBulkInserts[] = [
            'doc' => $body,
            'doc_as_upsert' => true,
        ];

        if (count($this->pendingBulkInserts) > 2000) { //1000 inserts
            $this->bulk();
        }
        
        return true;
    }

    /**
     * Run a bulk insert job (quicker).
     */
    public function bulk()
    {
        if (count($this->pendingBulkInserts) > 0) {
            $res = $this->client->bulk(['body' => $this->pendingBulkInserts]);
            $this->pendingBulkInserts = [];
        }
    }

}