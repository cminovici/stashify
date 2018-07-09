<?php

namespace App\Services;

use App\Conflict;
use App\Events\FileConflictFound;
use App\File;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Curl\CouldNotConnectToHost;
use Elasticsearch\Common\Exceptions\MaxRetriesException;
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
        if ($conflicts && !empty($appBuckets = $conflicts['aggregations']['app_name']['buckets'])) {
            foreach ($appBuckets as $appBucket) {
                $app = $appBucket['key'];
                if (!empty($fileBuckets = $appBucket['commit_changes']['duplicateCount']['buckets'])) {
                    foreach ($fileBuckets as $fileBucket) {
                        $fileName = $fileBucket['key'];
                        if ($fileBucket['duplicateDocuments']['changed_by']['uniques']['value'] > 1
                            && !empty($commitersBuckets = $fileBucket['duplicateDocuments']['changed_by']['email']['buckets'])
                        ) {
                            $file = File::firstOrCreate(['app' => $app, 'file' => $fileName]);
                            $fileConflicts = $file->conflicts;
                            foreach ($commitersBuckets as $commitersBucket) {
                                $commiter = $commitersBucket['key'];
                                if (!empty($branchBuckets = $commitersBucket['branch_name']['branch']['buckets'])) {
                                    foreach ($branchBuckets as $branchBucket) {
                                        $branch = $branchBucket['key'];
                                        if (!empty($commits = $branchBucket['info']['hits']['hits'])) {
                                            foreach ($commits as $commit) {
                                                $link = $commit['_source']['changesets']['values'][0]['links']['self'][0]['href'];
                                                $filtered = $fileConflicts->filter(
                                                    function ($fileConflict) use ($commiter, $branch, $file) {
                                                        return ($fileConflict->commiter == $commiter
                                                            && $fileConflict->branch == $branch && $fileConflict->fileId == $file->id);
                                                    }
                                                );
                                                if (!$filtered->count()) {
                                                    if ($this->save($file, $commiter, $branch, $link)) {
//                                                        dd($file->conflicts());
                                                        event(new FileConflictFound($file));
                                                        dd();
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

        dd($conflicts);
    }

    private function save(File $file, $commiter, $branch, $link): ?Model
    {
        $conflict = new Conflict;
        $conflict->commiter = $commiter;
        $conflict->branch = $branch;
        $conflict->link = $link;
        return $file->conflicts()->save($conflict);
    }
}