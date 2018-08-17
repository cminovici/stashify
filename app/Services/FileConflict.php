<?php

namespace App\Services;

use App\Conflict;
use App\Events\FileConflictFound;
use App\File;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Curl\CouldNotConnectToHost;
use Elasticsearch\Common\Exceptions\MaxRetriesException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class FileConflict
{

    private $elasticsearch;
    private $queryDSL
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

    public function __construct(Client $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * @return array
     */
    public function getFileConflicts(): array
    {
        $result = [];
        $searchParams = [
            'index' => 'gitstash',
            'type' => 'commits',
            'body' => $this->queryDSL
        ];

        try {
            $result = $this->elasticsearch->search($searchParams);
        } catch (CouldNotConnectToHost $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof MaxRetriesException) {
                echo "Max retries!";
            }
        }

        return $result;
    }

    public function parseAndSaveResult(array $conflicts)
    {
        $newConflicts = new Collection();
        if ($conflicts && !empty($appBuckets = $conflicts['aggregations']['app_name']['buckets'])) {
            foreach ($appBuckets as $appBucket) {
                $app = $appBucket['key'];
                if (!empty($fileBuckets = $appBucket['commit_changes']['duplicateCount']['buckets'])) {
                    foreach ($fileBuckets as $fileBucket) {
                        $fileName = $fileBucket['key'];
                        if ($fileBucket['duplicateDocuments']['changed_by']['uniques']['value'] > 1
                            && !empty($committerBuckets = $fileBucket['duplicateDocuments']['changed_by']['email']['buckets'])
                        ) {
                            $file = File::firstOrCreate(['app' => $app, 'file' => $fileName]);
                            $fileConflicts = $file->conflicts;
                            foreach ($committerBuckets as $committerBucket) {
                                $committer = $committerBucket['key'];
                                if (!empty($branchBuckets = $committerBucket['branch_name']['branch']['buckets'])) {
                                    foreach ($branchBuckets as $branchBucket) {
                                        $branch = $branchBucket['key'];
                                        if (!empty($commits = $branchBucket['info']['hits']['hits'])) {
                                            foreach ($commits as $commit) {
                                                $link = $commit['_source']['changesets']['values'][0]['links']['self'][0]['href'];
                                                if (!$fileConflicts->contains('committer', $committer)) {
                                                    $newConflicts->push(['committer' => $committer, 'branch' => $branch, 'link' => $link]);
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if ($newConflicts->isNotEmpty()) {
                                $newConflicts = $newConflicts->unique(function ($item) {
                                    return $item['committer'];
                                })->each(function ($item) use ($file) {
                                    $this->save($file, $item);
                                });
                                // @todo move event after we finish processing
                                // @todo should verify against db data
                                event(new FileConflictFound($file));
                            }
                        }
                    }
                }
            }
        }

        dd($conflicts);
    }

    private function save(File $file, $item): ?Model
    {
        $conflict = new Conflict;
        $conflict->fill($item);
        return $file->conflicts()->save($conflict);
    }
}