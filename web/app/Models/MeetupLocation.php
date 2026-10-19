<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetupLocation extends Model
{
    protected $fillable = ['name', 'description', 'operating_hours', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
