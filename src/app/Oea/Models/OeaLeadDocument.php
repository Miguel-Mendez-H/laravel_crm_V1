<?php

namespace App\Oea\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Lead;

class OeaLeadDocument extends Model
{
    protected $guarded = ['id'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(OeaDocumentType::class, 'oea_document_type_id');
    }

    // Si el archivo se borra desde la pestaña Archivos del CRM (soft delete), el documento cuenta como faltante
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
