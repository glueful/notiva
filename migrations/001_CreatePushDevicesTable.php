<?php

namespace Glueful\Extensions\Notiva\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create Push Devices Table
 *
 * Stores device tokens/subscriptions for push notifications (FCM/APNs/Web Push)
 * with per-device metadata and lifecycle tracking.
 */
class CreatePushDevicesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('push_devices', function ($table) {
            // Identity
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);

            // Ownership
            $table->string('user_uuid', 12)->nullable();
            $table->string('notifiable_type', 100)->nullable();
            $table->string('notifiable_id', 255)->nullable();

            // Provider + platform
            $table->enum('provider', ['fcm', 'apns', 'webpush'], 'fcm');
            $table->enum('platform', ['android', 'ios', 'web'], null)->nullable();

            // Tokens / subscriptions
            $table->string('device_token', 1024)->nullable(); // FCM/APNs token
            $table->json('subscription_json')->nullable();   // Web Push subscription

            // Device/app metadata
            $table->string('device_id', 255)->nullable();
            $table->string('app_id', 100)->nullable();
            $table->string('bundle_id', 100)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();

            // Lifecycle
            $table->enum('status', ['active', 'invalid', 'revoked'], 'active');
            $table->timestamp('registered_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();

            // Timestamps
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Indexes & constraints
            $table->unique('uuid');
            $table->unique(['provider', 'device_token'], 'unique_provider_token');
            $table->index('user_uuid');
            $table->index('status');
            $table->index(['provider', 'platform']);
            $table->index('last_seen_at');

            // Foreign keys
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('push_devices');
    }

    public function getDescription(): string
    {
        return 'Creates push_devices table with UUID, provider/platform, tokens, and lifecycle tracking.';
    }
}

