<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $wiki_id
 * @property int $team_id
 * @property string $title
 * @property string $body
 * @property list<float> $embedding
 */
class Document extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
        ];
    }
}
