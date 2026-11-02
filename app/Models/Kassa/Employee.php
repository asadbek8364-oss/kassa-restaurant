<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    public const ROLES = ['owner', 'seller'];

    protected $table = 'kassa_employees';

    protected $fillable = ['name', 'role', 'phone', 'pin', 'active'];

    protected $hidden = ['pin'];

    protected $casts = [
        'active' => 'boolean',
        'pin' => 'hashed',
    ];

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function tokens()
    {
        return $this->hasMany(Token::class, 'employee_id');
    }
}
