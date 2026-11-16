<?php

namespace App\Domain\Support;

use Illuminate\Database\Eloquent\Model;

class Reply extends Model
{
    protected $table = 'support_replies';

    protected $fillable = ['ticket_id', 'user_id', 'staff', 'body'];

    protected function casts(): array
    {
        return ['staff' => 'boolean'];
    }
}
