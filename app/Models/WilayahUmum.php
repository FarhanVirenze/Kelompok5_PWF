<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WilayahUmum extends Model
{
    use HasFactory;

    protected $table = 'wilayah_umum';
    protected $fillable = ['nama'];
}
