<?php

namespace App\Http\Controllers;

use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Curl\CouldNotConnectToHost;
use Elasticsearch\Common\Exceptions\MaxRetriesException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    private $elasticsearch;

    public function __construct(Client $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    public function index()
    {
        $json
            = '{
                  "size": 20,
                  "query": {
                    "bool": {
                      "filter": {
                        "and": [
                          {
                            "terms": {
                              "refChanges.type": [
                                "update",
                                "add"
                              ]
                            }
                          }
                        ]
                      },
                      "must": [
                        {
                          "script": {
                            "script": "_source.changesets.values.toCommit.parents.size() < 2"
                          }
                        }
                      ],
                      "must_not": [
                        {
                          "term": {
                            "refChanges.refId.raw": "refs/heads/master"
                          }
                        }
                      ]
                    }
                  },
                  "aggs": {
                    "app_name": {
                      "terms": {
                        "field": "repository.slug.raw"
                      },
                      "aggs": {
                        "commit_changes": {
                          "nested": {
                            "path": "changesets.values.changes.values"
                          },
                          "aggs": {
                            "duplicateCount": {
                              "terms": {
                                "field": "changesets.values.changes.values.path.toString.raw",
                                "min_doc_count": 2
                              },
                              "aggs": {
                                "duplicateDocuments": {
                                  "reverse_nested": {},
                                  "aggs": {
                                    "changed_by": {
                                      "nested": {
                                        "path": "changesets.values"
                                      },
                                      "aggs": {
                                        "uniques": {
                                          "cardinality": {
                                            "field": "changesets.values.toCommit.committer.emailAddress.raw"
                                          }
                                        },
                                        "email": {
                                          "terms": {
                                            "field": "changesets.values.toCommit.committer.emailAddress.raw"
                                          },
                                          "aggs": {
                                            "branch_name": {
                                              "reverse_nested": {},
                                              "aggs": {
                                                "branch": {
                                                  "terms": {
                                                    "field": "refChanges.refId.raw"
                                                  },
                                                  "aggs": {
                                                    "info": {
                                                      "top_hits": {
                                                        "size": 5,
                                                        "_source": {
                                                          "include": "changesets.values.links"
                                                        }
                                                      }
                                                    }
                                                  }
                                                }
                                              }
                                            }
                                          }
                                        }
                                      }
                                    }
                                  }
                                }
                              }
                            }
                          }
                        }
                      }
                    }
                  }
                }';

        $searchParams = [
            'index' => 'gitstash',
            'type'  => 'commits',
            'body'  => $json
        ];

        try {
            $result = $this->elasticsearch->search($searchParams);
            dd($result);
        } catch (CouldNotConnectToHost $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof MaxRetriesException) {
                echo "Max retries!";
            }
        }
    }
}
