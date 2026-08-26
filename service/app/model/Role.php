<?php
namespace App\model;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'display_name', 'description'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }
}
