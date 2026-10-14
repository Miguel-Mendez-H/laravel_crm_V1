<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de documentos que SAC debe cargar por cliente
        Schema::create('oea_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Documento vigente de cada tipo para cada prospecto (apunta al archivo del CRM)
        Schema::create('oea_lead_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('oea_document_type_id')->constrained('oea_document_types')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('crm_files');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lead_id', 'oea_document_type_id']);
        });

        // Concepto de cada área por ronda de revisión (registro de auditoría, no se edita)
        Schema::create('oea_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('area', 20);
            $table->foreignId('task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();
            $table->foreignId('user_assigned_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pendiente');
            $table->text('comment')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['lead_id', 'round', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oea_reviews');
        Schema::dropIfExists('oea_lead_documents');
        Schema::dropIfExists('oea_document_types');
    }
};
