<?php

namespace App\Domain\Rbac\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['slug', 'description'];
}
