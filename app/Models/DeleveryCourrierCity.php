<?php

namespace App\Models;

use Database\Factories\DeleveryCourrierCityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $courrier_id
 * @property string|null $external_courrier_id
 * @property string $name
 * @property string|null $arabic_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['courrier_id', 'external_courrier_id', 'name', 'arabic_name'])]
class DeleveryCourrierCity extends Model
{
    /** @use HasFactory<DeleveryCourrierCityFactory> */
    use HasFactory;

    protected $table = 'delevery_courrier_cities';

    /** @return BelongsTo<DeliveryCourrier, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourrier::class, 'courrier_id');
    }
}
