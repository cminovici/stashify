<?php

namespace App\Providers;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->bindElasticClient();
    }

    private function bindElasticClient()
    {
        $this->app->bind(Client::class, function () {
            return ClientBuilder::create()
                ->setHosts(config('services.elasticsearch.hosts'))
                ->build();
        });
    }
}
