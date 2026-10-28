<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'job_title', 'hired_on', 'base_salary', 'status'];

    protected function casts(): array
    {
        return ['hired_on' => 'date'];
    }
}
