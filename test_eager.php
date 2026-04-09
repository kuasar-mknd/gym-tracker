<?php

namespace App\Models;

require __DIR__.'/vendor/autoload.php';

// mock collection and model
class Collection
{
    public function isNotEmpty()
    {
        return true;
    }
}

class Model
{
    public $relations = [];

    public function relationLoaded($key)
    {
        return array_key_exists($key, $this->relations);
    }
}
