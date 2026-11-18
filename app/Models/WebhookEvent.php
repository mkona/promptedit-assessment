<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every Pixelforge event we have processed. The primary key is the event id, so a redelivered event can't be stored twice.
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
