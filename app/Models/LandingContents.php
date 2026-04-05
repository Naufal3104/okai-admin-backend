<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingContents extends Model
{
    use HasFactory;
    protected $table = 'landing_content';

    protected $fillable = ['section', 'title', 'content', 'image_url'];
}
