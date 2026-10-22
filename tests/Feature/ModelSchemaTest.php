<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModelSchemaTest extends TestCase
{
    public function test_every_model_maps_to_an_existing_table(): void
    {
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                $missing[] = "{$class} (class not found)";

                continue;
            }

            $model = new $class;

            if (! $model instanceof Model) {
                continue;
            }

            if (! Schema::hasTable($model->getTable())) {
                $missing[] = "{$class} => {$model->getTable()}";
            }
        }

        $this->assertSame([], $missing);
    }
}
