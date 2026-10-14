<?php

namespace App\Oea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OeaDocumentType extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
    ];

    public function leadDocuments(): HasMany
    {
        return $this->hasMany(OeaLeadDocument::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('id');
    }
}
