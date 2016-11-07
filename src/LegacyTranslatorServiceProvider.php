<?php

namespace NicolasBeauvais\LegacyTranslator;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\ViewServiceProvider;
use NicolasBeauvais\LegacyTranslator\Commands\SearchCommand;

class LegacyTranslatorServiceProvider extends ViewServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/laravel-legacy-translator.php' => config_path('laravel-legacy-translator.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([SearchCommand::class]);
        }
    }

    public function register()
    {
        parent::register();
    }

    /**
     * Register the Blade engine implementation.
     *
     * @param  \Illuminate\View\Engines\EngineResolver  $resolver
     * @return void
     */
    public function registerBladeEngine($resolver)
    {
        if ($this->app->runningInConsole() && in_array('llt:search', request()->server('argv'))) {
            $this->customBladeEngine($resolver);
        } else {
            parent::registerBladeEngine($resolver);
        }
    }

    private function customBladeEngine($resolver)
    {
        $app = $this->app;

        // The Compiler engine requires an instance of the CompilerInterface, which in
        // this case will be the Blade compiler, so we'll first create the compiler
        // instance to pass into the engine so it can compile the views properly.
        $app->singleton('blade.compiler', function ($app) {
            $cache = $app['config']['view.compiled'];

            return new LegacyTranslatorBladeCompiler($app['files'], $cache);
        });

        $resolver->register('blade', function () use ($app) {
            return new CompilerEngine($app['blade.compiler']);
        });
    }
}
