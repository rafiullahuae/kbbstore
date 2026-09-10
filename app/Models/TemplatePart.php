<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TemplatePart extends Model
{
    public $timestamps = false;
    protected $table = "template_parts";
    protected $guarded = [];
}
