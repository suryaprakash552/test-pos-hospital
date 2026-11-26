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
        // Sales table indexes
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'sales_business_created_index');
            $table->index(['user_id', 'created_at'], 'sales_user_created_index');
            $table->index(['customer_id', 'created_at'], 'sales_customer_created_index');
            $table->index('invoiceNumber', 'sales_invoice_index');
            $table->index('saleDate', 'sales_date_index');
        });

        // Sale details table indexes
        Schema::table('sale_details', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id'], 'sale_details_sale_product_index');
            $table->index('stock_id', 'sale_details_stock_index');
            $table->index('product_id', 'sale_details_product_index');
        });

        // Products table indexes
        Schema::table('products', function (Blueprint $table) {
            $table->index(['business_id', 'category_id'], 'products_business_category_index');
            $table->index(['business_id', 'brand_id'], 'products_business_brand_index');
            $table->index(['business_id', 'manufacturer_id'], 'products_business_manufacturer_index');
            $table->index('productCode', 'products_code_index');
            $table->index('warehouse_id', 'products_warehouse_index');
            $table->index(['business_id', 'productStock'], 'products_business_stock_index');
        });

        // Stocks table indexes
        Schema::table('stocks', function (Blueprint $table) {
            $table->index(['business_id', 'product_id'], 'stocks_business_product_index');
            $table->index(['business_id', 'warehouse_id'], 'stocks_business_warehouse_index');
            $table->index('batch_no', 'stocks_batch_index');
            $table->index('expire_date', 'stocks_expire_index');
            $table->index(['business_id', 'productStock'], 'stocks_business_stock_index');
        });

        // Purchase details table indexes
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->index(['purchase_id', 'product_id'], 'purchase_details_purchase_product_index');
            $table->index('stock_id', 'purchase_details_stock_index');
            $table->index('product_id', 'purchase_details_product_index');
        });

        // Purchases table indexes
        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'purchases_business_created_index');
            $table->index(['user_id', 'created_at'], 'purchases_user_created_index');
            $table->index('purchaseDate', 'purchases_date_index');
        });

        // Users table indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index(['business_id', 'role'], 'users_business_role_index');
            $table->index(['business_id', 'branch_id'], 'users_business_branch_index');
            $table->index('email', 'users_email_index');
            $table->index('last_login_at', 'users_last_login_index');
        });

        // Customers table indexes
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['business_id', 'phone'], 'customers_business_phone_index');
            $table->index(['business_id', 'relationship'], 'customers_business_relationship_index');
            $table->index('due', 'customers_due_index');
        });

        // Parties table indexes
        Schema::table('parties', function (Blueprint $table) {
            $table->index(['business_id', 'phone'], 'parties_business_phone_index');
            $table->index('due', 'parties_due_index');
        });

        // Due collects table indexes
        Schema::table('due_collects', function (Blueprint $table) {
            $table->index(['business_id', 'party_id'], 'due_collects_business_party_index');
            $table->index('created_at', 'due_collects_created_index');
        });

        // Customer due collects table indexes
        Schema::table('customer_due_collects', function (Blueprint $table) {
            $table->index(['business_id', 'customer_id'], 'customer_due_collects_business_customer_index');
            $table->index('created_at', 'customer_due_collects_created_index');
        });

        // Expenses table indexes
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'expenses_business_created_index');
            $table->index(['business_id', 'expense_category_id'], 'expenses_business_category_index');
        });

        // Incomes table indexes
        Schema::table('incomes', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'incomes_business_created_index');
            $table->index(['business_id', 'income_category_id'], 'incomes_business_category_index');
        });

        // Branches table indexes
        Schema::table('branches', function (Blueprint $table) {
            $table->index(['business_id', 'is_main'], 'branches_business_main_index');
        });

        // Categories table indexes
        Schema::table('categories', function (Blueprint $table) {
            $table->index(['business_id', 'categoryName'], 'categories_business_name_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse indexes in reverse order
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_business_name_index');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_business_main_index');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropIndex('incomes_business_category_index');
            $table->dropIndex('incomes_business_created_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_business_category_index');
            $table->dropIndex('expenses_business_created_index');
        });

        Schema::table('customer_due_collects', function (Blueprint $table) {
            $table->dropIndex('customer_due_collects_created_index');
            $table->dropIndex('customer_due_collects_business_customer_index');
        });

        Schema::table('due_collects', function (Blueprint $table) {
            $table->dropIndex('due_collects_created_index');
            $table->dropIndex('due_collects_business_party_index');
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex('parties_due_index');
            $table->dropIndex('parties_business_phone_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_due_index');
            $table->dropIndex('customers_business_relationship_index');
            $table->dropIndex('customers_business_phone_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_last_login_index');
            $table->dropIndex('users_email_index');
            $table->dropIndex('users_business_branch_index');
            $table->dropIndex('users_business_role_index');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropIndex('purchase_details_product_index');
            $table->dropIndex('purchase_details_stock_index');
            $table->dropIndex('purchase_details_purchase_product_index');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_date_index');
            $table->dropIndex('purchases_user_created_index');
            $table->dropIndex('purchases_business_created_index');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex('stocks_business_stock_index');
            $table->dropIndex('stocks_expire_index');
            $table->dropIndex('stocks_batch_index');
            $table->dropIndex('stocks_business_warehouse_index');
            $table->dropIndex('stocks_business_product_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_business_stock_index');
            $table->dropIndex('products_warehouse_index');
            $table->dropIndex('products_code_index');
            $table->dropIndex('products_business_manufacturer_index');
            $table->dropIndex('products_business_brand_index');
            $table->dropIndex('products_business_category_index');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropIndex('sale_details_product_index');
            $table->dropIndex('sale_details_stock_index');
            $table->dropIndex('sale_details_sale_product_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_date_index');
            $table->dropIndex('sales_invoice_index');
            $table->dropIndex('sales_customer_created_index');
            $table->dropIndex('sales_user_created_index');
            $table->dropIndex('sales_business_created_index');
        });
    }
};
