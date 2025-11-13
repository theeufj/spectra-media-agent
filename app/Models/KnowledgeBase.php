<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors; // Corrected trait name
use Pgvector\Laravel\Vector; // Import the Vector class

class KnowledgeBase extends Model
{
    // use HasFactory; // We don't need factories for this model yet.
    use HasNeighbors; // Corrected trait name

    /**
     * The attributes that are mass assignable.
     * In Go, you might control this with struct tags (`json:"field"`) or by manually mapping fields.
     * In Laravel, the `$fillable` array is a security feature that prevents mass-assignment vulnerabilities.
     * Only the fields listed here can be set when using methods like `create()` or `updateOrCreate()`.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'url',
        'content',
        'css_content',
        'embedding',
        'file_path',
        'source_type',
        'original_filename',
    ];

    /**
     * The attributes that should be cast.
     * This tells Laravel how to handle specific data types.
     * We must cast the 'embedding' column to the package's Vector class.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'embedding' => Vector::class,
    ];
}
