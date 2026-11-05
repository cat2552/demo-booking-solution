<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    public const string OPENS_AT = '09:00';
    public const string CLOSES_AT = '20:00';

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
