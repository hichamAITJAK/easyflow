<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One content type inside a request: how many, and the per-creative
 * directions (index = creative number; an empty entry means free style).
 *
 * @property int $id
 * @property int $content_request_id
 * @property string $type
 * @property int $count
 * @property array<int, string> $directions
 */
#[Fillable(['content_request_id', 'type', 'count', 'directions'])]
class ContentRequestItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['directions' => 'array'];
    }

    /** @return BelongsTo<ContentRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ContentRequest::class, 'content_request_id');
    }
}
