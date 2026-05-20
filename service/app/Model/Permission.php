<?php
namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name', 'display_name', 'group'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
