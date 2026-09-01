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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('external_order_id')->nullable();
            $table->string('source_platform')->default('manual');
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('customer_name');
            $table->text('customer_phone');
            $table->string('customer_phone_hash');
            $table->text('customer_address');
            $table->string('customer_city')->nullable();
            $table->string('customer_ip_address')->nullable();

            $table->decimal('total_amount', 10, 2);
            $table->decimal('delivery_cost', 10, 2)->nullable();
            $table->decimal('returned_cost', 10, 2)->nullable();
            $table->decimal('refused_cost', 10, 2)->nullable();
            $table->string('confirmation_status');
            $table->string('delivery_status')->nullable();
            // True from the moment a shipment is created until the courier
            // reports a terminal status (DELIVERED or CANCELLED_AT_COURIER)
            // — maintained solely by OrderService::updateDeliveryStatus() /
            // createShipment(), never set directly. Lets the delivery-status
            // poll (orders:sync-delivery-statuses) find in-flight orders with
            // a plain indexed boolean instead of a delivery_status NOT IN
            // (...) exclusion filter, which doesn't range-scan well.
            $table->boolean('is_delivery_active')->default(false);
            $table->string('cancellation_reason_code')->nullable();
            $table->string('return_reason_code')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_duplicate_flagged')->default(false);
            $table->boolean('is_blacklist_flagged')->default(false);
            $table->boolean('is_test')->default(false);

            $table->foreignId('delivery_account_id')->nullable()->constrained('delivery_accounts')->nullOnDelete();
            $table->string('courier_tracking_number')->nullable()->unique();
            $table->string('courier_slug')->nullable();
            // Populated once the courier reports the parcel as out for
            // delivery (OrderDeliveryStatus::OUT_FOR_DELIVERY) — not every
            // courier's API surfaces this, so both stay null otherwise.
            $table->string('delivery_driver_name')->nullable();
            $table->string('delivery_driver_phone')->nullable();
            $table->timestamp('shipped_at')->nullable();

            // Snapshot of the parcel-specific fields sent to the courier at
            // shipment time (currently only meaningful for OzonExpress —
            // Sendit doesn't support these). Kept alongside the order so the
            // parcel tracking page can show what was actually submitted
            // without re-deriving it from delivery_account/courier state
            // that may have changed since.
            $table->string('parcel_note')->nullable();
            $table->string('parcel_nature')->nullable();
            $table->boolean('parcel_open')->nullable();
            $table->boolean('parcel_fragile')->nullable();
            $table->boolean('parcel_replace')->nullable();
            $table->json('parcel_products')->nullable();

            $table->timestamp('ready_for_pickup_at')->nullable();
            $table->timestamp('return_received_at')->nullable();
            // The ecom platform's own order-creation timestamp (from its API
            // response), distinct from created_at which is when we ingested
            // it locally — the two can differ by anywhere from seconds to
            // days depending on sync cadence. Null for manual orders created
            // before this column existed, or for platforms whose response
            // didn't carry a parseable timestamp.
            $table->timestamp('ordered_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'confirmation_status', 'ordered_at']);
            $table->index(['business_id', 'delivery_status', 'ordered_at']);
            $table->index(['business_id', 'store_id', 'ordered_at']);
            $table->index(['business_id', 'assigned_agent_id']);
            $table->unique(['store_id', 'external_order_id']);
            $table->index(['business_id', 'customer_phone_hash']);
            $table->index('is_delivery_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
