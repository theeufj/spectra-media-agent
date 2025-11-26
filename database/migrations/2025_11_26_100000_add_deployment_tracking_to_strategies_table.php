<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            // Deployment status tracking
            $table->timestamp('deployed_at')->nullable()->after('execution_errors')
                ->comment('When the strategy was deployed to ad platforms');
            
            $table->string('deployment_status', 50)->nullable()->after('deployed_at')
                ->comment('pending, deploying, deployed, failed');
            
            $table->text('deployment_error')->nullable()->after('deployment_status')
                ->comment('Error message if deployment failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn([
                'deployed_at',
                'deployment_status',
                'deployment_error',
            ]);
        });
    }
};
