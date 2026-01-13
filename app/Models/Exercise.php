<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $fillable = ['name', 'type', 'category'];

    public function workoutLines()
    {
        return $this->hasMany(WorkoutLine::class);
    }
}
