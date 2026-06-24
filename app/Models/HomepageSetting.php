<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomepageSetting extends Model
{
    use HasFactory;

    protected $table = 'homepage_settings';

    protected $fillable = [
        'hero_badge', 
        'hero_title_1', 
        'hero_title_2', 
        'hero_highlight', 
        'hero_description', 
        'hero_button_text', 
        'hero_button_link', 
        'hero_image_url', 
        'hero_product_title', 
        'hero_product_subtitle'
    ];
}