<?php namespace Jimbolino\Laravel\ModelBuilder;

use Illuminate\Routing\Controller;

/**
 * ModelGenerator4.
 *
 * Laravel 4 version for the ModelGenerator
 *
 * @author Jimbolino
 * @since 02-2015
 *
 */
class ModelGenerator4 extends Controller {

    public function start() {
        // This is the model that all your others will extend
        $baseModel = 'Eloquent'; // default laravel 4.2

        // This is the path where we will store your new models
        $path = base_path('app/storage/models'); // laravel 4

        // The namespace of the models
        $namespace = ''; // by default laravel 4 doesn't use namespaces

        $generator = new ModelGenerator($baseModel, $path, $namespace);
        $generator->start();

    }

}
