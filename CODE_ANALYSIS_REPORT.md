# POS Inventory Web - Complete Code Analysis Report

## Project Overview
This is a Laravel 10-based Point of Sale (POS) and Inventory Management System with multi-tenant architecture. The system supports businesses, branches, warehouses, and comprehensive inventory tracking with sales, purchases, and financial management.

---

## 1. Project Architecture

### Directory Structure
```
pos-inventory-web/
├── app/                          # Main application code
│   ├── Actions/                 # Domain actions
│   ├── Console/                 # Artisan commands
│   ├── Constants/               # Application constants
│   ├── DTOs/                    # Data Transfer Objects
│   ├── Exceptions/              # Custom exceptions
│   ├── Helpers/                 # Helper classes
│   ├── Http/                    # HTTP layer
│   │   ├── Controllers/         # API and Web controllers
│   │   ├── Middleware/          # HTTP middleware
│   │   ├── Requests/            # Form Request validation
│   │   └── Kernel.php           # HTTP kernel
│   ├── Imports/                 # Import handlers
│   ├── Jobs/                    # Queue jobs
│   ├── Library/                 # External libraries
│   ├── Mail/                    # Email classes
│   ├── Models/                  # Eloquent models
│   ├── Notifications/          # Notifications
│   ├── Policies/                # Authorization policies
│   ├── Providers/               # Service providers
│   ├── Rules/                   # Custom validation rules
│   ├── Services/                # Business logic services
│   ├── Support/                 # Support classes
│   └── Traits/                  # Reusable traits
├── Modules/                     # Modular addons
│   ├── Business/                # Core business logic
│   ├── HrmAddon/               # HR management addon
│   ├── MultiBranchAddon/       # Multi-branch support
│   ├── ThermalPrinterAddon/    # Printer integration
│   └── WarehouseAddon/         # Warehouse management
├── database/                    # Database layer
│   ├── factories/              # Model factories
│   ├── migrations/             # Database migrations
│   └── seeders/                # Database seeders
├── public/                      # Public assets
│   └── uploads/                # User uploaded files
├── resources/                   # Frontend resources
├── routes/                      # Route definitions
│   ├── api.php                 # API routes
│   ├── web.php                 # Web routes
│   └── admin.php               # Admin panel routes
└── config/                      # Configuration files
```

---

## 2. Database Schema Analysis

### Core Tables

#### Business & User Management
- **businesses** - Business/Company records with subscription info
- **users** - User accounts with role-based access (admin, shop-owner, staff)
- **business_categories** - Business categorization
- **plans** - Subscription plans
- **plan_subscribes** - Subscription records

#### Product & Inventory
- **products** - Product master data
- **stocks** - Batch-level inventory tracking
- **categories** - Product categories with variations
- **brands** - Product brands
- **manufacturers** - Product manufacturers
- **units** - Measurement units
- **product_models** - Product model variants
- **warehouses** - Storage locations
- **branches** - Business branches
- **racks** - Storage racks
- **shelfs** - Storage shelves

#### Sales & Purchases
- **sales** - Sales transactions
- **sale_details** - Line items for sales
- **purchases** - Purchase transactions
- **purchase_details** - Line items for purchases
- **sale_returns** - Sales returns
- **purchase_returns** - Purchase returns
- **parties** - Customers and suppliers
- **customers** - Customer-specific data
- **relationships** - Customer relationships

#### Financial
- **expenses** - Expense records
- **expense_categories** - Expense categorization
- **incomes** - Income records
- **income_categories** - Income categorization
- **due_collects** - Due collection records
- **payment_types** - Payment method types
- **currencies** - Currency configurations

#### Tax & Compliance
- **vats** - VAT/GST configurations
- **gst_configuration_history** - GST configuration audit trail

#### Features & Settings
- **features** - Feature flags
- **options** - System options
- **languages** - Multi-language support
- **banners** - Marketing banners
- **notifications** - System notifications

#### Loyalty System
- **loyalty_packages** - Loyalty subscription packages
- **loyalty_subscriptions** - Customer loyalty subscriptions
- **loyalty_audits** - Loyalty transaction audits
- **customer_tiers** - Customer classification tiers

### Key Relationships

```
Business (1) ──< (N) Users
Business (1) ──< (N) Products
Business (1) ──< (N) Parties
Business (1) ──< (N) Branches
Business (1) ──< (N) Warehouses
Business (1) ──< (N) Categories
Business (1) ──< (N) Brands
Business (1) ──< (N) Units

Product (1) ──< (N) Stocks
Product (1) ──< (N) SaleDetails
Product (1) ──< (N) PurchaseDetails (through Stock)
Product (N) ──< (1) Category
Product (N) ──< (1) Brand
Product (N) ──< (1) Unit
Product (N) ──< (1) Manufacturer

Stock (1) ──< (N) PurchaseDetails
Stock (1) ──< (N) SaleDetails
Stock (N) ──< (1) Product
Stock (N) ──< (1) Branch
Stock (N) ──< (1) Warehouse

Sale (1) ──< (N) SaleDetails
Sale (N) ──< (1) Party (Customer)
Sale (N) ──< (1) User
Sale (N) ──< (1) Business

Purchase (1) ──< (N) PurchaseDetails
Purchase (N) ──< (1) Party (Supplier)
Purchase (N) ──< (1) User
Purchase (N) ──< (1) Business

Party (1) ──< (N) Purchases (as supplier)
Party (1) ──< (N) Sales (as customer)
Party (N) ──< (1) Business
```

---

## 3. Eloquent Models Analysis

### Core Models

#### User Model
- **Location:** `app/Models/User.php`
- **Traits:** `HasApiTokens`, `HasFactory`, `Notifiable`, `HasRoles`
- **Key Relationships:**
  - `business()` - BelongsTo Business
  - `branch()` - BelongsTo Branch (withTrashed)
  - `active_branch()` - BelongsTo Branch (dynamic)
  - `affiliate()` - HasOne Affiliate
- **Special Methods:**
  - `hasPermission()` - Custom permission checking
  - `hasAnyPermission()` - Batch permission checking
  - `accessToMultiBranch()` - Multi-branch access check
- **Casts:** `visibility` (json), `password` (hashed)

#### Business Model
- **Location:** `app/Models/Business.php`
- **Key Relationships:**
  - `enrolled_plan()` - BelongsTo PlanSubscribe
  - `category()` - BelongsTo BusinessCategory
  - `user()` - HasMany User
  - `affiliator()` - BelongsTo Affiliate
- **Fields:** GST configuration, UPI settings, subscription data

#### Product Model
- **Location:** `app/Models/Product.php`
- **Traits:** `BelongsToBusiness`, `HasFactory`
- **Key Relationships:**
  - `business()` - BelongsTo Business
  - `unit()` - BelongsTo Unit
  - `brand()` - BelongsTo Brand
  - `manufacturer()` - BelongsTo Manufacturer
  - `category()` - BelongsTo Category
  - `warehouse()` - BelongsTo Warehouse
  - `stocks()` - HasMany Stock
  - `vat()` - BelongsTo Vat
  - `batch()` - HasOne Stock (latest with stock)
  - `purchaseDetails()` - HasManyThrough PurchaseDetails
- **Fields:** Pricing tiers, stock levels, variations, GST data

#### Stock Model
- **Location:** `app/Models/Stock.php`
- **Traits:** `BelongsToBusiness`, `HasFactory`
- **Global Scopes:** `BranchScope`
- **Key Relationships:**
  - `product()` - BelongsTo Product
  - `branch()` - BelongsTo Branch
  - `warehouse()` - BelongsTo Warehouse
  - `purchaseDetails()` - HasMany PurchaseDetails
  - `saleDetails()` - HasMany SaleDetails
- **Appends:** `available_quantity`, `sold_quantity`
- **Special Methods:**
  - `availableLots()` - Purchase lots with remaining quantity
  - `getAvailableQuantityAttribute()` - Calculated available stock
  - `getSoldQuantityAttribute()` - Calculated sold quantity
- **Boot:** Auto-sets branch_id on creation

#### Party Model
- **Location:** `app/Models/Party.php`
- **Traits:** `BelongsToBusiness`, `HasFactory`
- **Key Relationships:**
  - `purchases()` - HasMany Purchase
  - `purchases_dues()` - HasMany Purchase (with due)
  - `dueCollect()` - HasOne DueCollect
- **Special Methods:**
  - `setGstinAttribute()` - Auto-uppercases GSTIN
- **Fields:** Customer/Supplier data, GSTIN, credit limits, loyalty points

---

## 4. API Routes Analysis

### Route Structure (`routes/api.php`)

#### Public Routes (No Authentication)
```
GET  /api/v1/                          - Health check
GET  /api/v1/module-check              - Module availability
POST /api/v1/sign-in                   - User login (throttled: 30/min)
POST /api/v1/sign-in/devices           - Device management (throttled: 5/min)
POST /api/v1/sign-in/devices/release   - Release device (throttled: 5/min)
POST /api/v1/submit-otp                - OTP verification (throttled: 10/min)
POST /api/v1/sign-up                   - User registration (throttled: 3/min)
POST /api/v1/resend-otp                - Resend OTP (throttled: 5/min)
GET  /api/v1/otp-settings              - OTP configuration
POST /api/v1/send-reset-code           - Password reset code (throttled: 3/min)
POST /api/v1/verify-reset-code         - Verify reset code (throttled: 10/min)
POST /api/v1/password-reset            - Reset password (throttled: 3/min)
```

#### Protected Routes (Auth: Sanctum + Throttle)
```
# Business & Settings
GET  /api/v1/update-expire-date        - Update subscription expiry
GET  /api/v1/business-settings          - Business settings
GET  /api/v1/invoice-settings          - Invoice settings
POST /api/v1/invoice-settings/update   - Update invoice settings
POST /api/v1/business-delete           - Delete business (with password)
GET  /api/v1/business                  - Business CRUD
POST /api/v1/business
PUT  /api/v1/business/{id}
GET  /api/v1/business-categories       - Business categories

# Statistics & Dashboard
GET  /api/v1/summary                   - Business summary
GET  /api/v1/due-summary               - Due summary
GET  /api/v1/dashboard                 - Dashboard data
GET  /api/v1/staff-reports             - Staff performance reports
POST /api/v1/counter                  - Add counter amount
GET  /api/v1/counter                  - View counter amount
PUT  /api/v1/counter                  - Update counter amount

# Parties & Customers
GET  /api/v1/parties                   - Parties CRUD
POST /api/v1/parties
PUT  /api/v1/parties/{id}
DELETE /api/v1/parties/{id}
GET  /api/v1/customers                 - Customers CRUD
POST /api/v1/customers
PUT  /api/v1/customers/{id}
DELETE /api/v1/customers/{id}
GET  /api/v1/relationships             - Relationships CRUD
POST /api/v1/relationships
PUT  /api/v1/relationships/{id}
DELETE /api/v1/relationships/{id}
GET  /api/v1/parties/{id}/ledger      - Party ledger statement

# Users & Staff
GET  /api/v1/users                     - Users CRUD
POST /api/v1/users
PUT  /api/v1/users/{id}
DELETE /api/v1/users/{id}

# Product Management
GET  /api/v1/products                  - Products CRUD
POST /api/v1/products
PUT  /api/v1/products/{id}
DELETE /api/v1/products/{id}
GET  /api/v1/expired-products          - Expired products list
GET  /api/v1/products-by-location      - Products by location
GET  /api/v1/products/{product}/inventory-history - Product inventory ledger
GET  /api/v1/categories                - Categories CRUD
POST /api/v1/categories
PUT  /api/v1/categories/{id}
DELETE /api/v1/categories/{id}
GET  /api/v1/categories/{category}/bulk-add - Bulk add to cart
GET  /api/v1/brands                     - Brands CRUD
POST /api/v1/brands
PUT  /api/v1/brands/{id}
DELETE /api/v1/brands/{id}
GET  /api/v1/units                      - Units CRUD
POST /api/v1/units
PUT  /api/v1/units/{id}
DELETE /api/v1/units/{id}
GET  /api/v1/manufacturers             - Manufacturers CRUD
POST /api/v1/manufacturers
PUT  /api/v1/manufacturers/{id}
DELETE /api/v1/manufacturers/{id}
GET  /api/v1/product-models            - Product models CRUD
POST /api/v1/product-models
PUT  /api/v1/product-models/{id}
DELETE /api/v1/product-models/{id}
GET  /api/v1/product-settings          - Product settings
POST /api/v1/product-settings
GET  /api/v1/vats                       - VAT CRUD
POST /api/v1/vats
PUT  /api/v1/vats/{id}
DELETE /api/v1/vats/{id}
GET  /api/v1/payment-types             - Payment types CRUD
POST /api/v1/payment-types
PUT  /api/v1/payment-types/{id}
DELETE /api/v1/payment-types/{id}

# Stock Management
GET  /api/v1/stocks/summary            - Stock summary
GET  /api/v1/stocks/{stock}/origin     - Stock origin details
GET  /api/v1/stocks/{stock}/history    - Stock history
GET  /api/v1/stocks                    - Stocks CRUD
POST /api/v1/stocks
PUT  /api/v1/stocks/{id}
DELETE /api/v1/stocks/{id}
GET  /api/v1/low-stock-products         - Low stock alerts
GET  /api/v1/inventory-history          - Global inventory history
POST /api/v1/profit-loss-report         - Profit/loss report
GET  /api/v1/profit-loss-report/export  - Export profit/loss
GET  /api/v1/stock-audit                - Stock audit index
POST /api/v1/stock-audit-submit         - Submit stock audit
GET  /api/v1/stock-audit-report         - Stock audit report
GET  /api/v1/follow-up-report           - Follow-up report

# Expired Stock Disposal
GET  /api/v1/expired-stock/pending     - Pending disposals
POST /api/v1/stock-disposals           - Record disposal (idempotent)
GET  /api/v1/stock-disposals           - Disposal history
GET  /api/v1/stock-disposals/{id}      - Disposal details

# GST & Tax
GET  /api/v1/gst-report                - GST report
GET  /api/v1/branch-gst-report         - Branch GST configuration
GET  /api/v1/gst-configuration         - GST settings
PUT  /api/v1/gst-configuration         - Update GST settings
GET  /api/v1/gst-configuration/history - GST configuration history

# Loyalty System
GET  /api/v1/loyalty-packages          - Loyalty packages CRUD
POST /api/v1/loyalty-packages
PUT  /api/v1/loyalty-packages/{id}
DELETE /api/v1/loyalty-packages/{id}
GET  /api/v1/loyalty-subscriptions     - Loyalty subscriptions
POST /api/v1/loyalty-subscriptions     - Create subscription
GET  /api/v1/customers/{customer}/loyalty - Customer loyalty
POST /api/v1/loyalty-subscriptions/{subscription}/renew - Renew
POST /api/v1/loyalty-subscriptions/{subscription}/upgrade - Upgrade
POST /api/v1/loyalty-subscriptions/{subscription}/cancel - Cancel
GET  /api/v1/loyalty-subscriptions/{subscription}/refund-quote - Refund quote
POST /api/v1/loyalty-subscriptions/{subscription}/refund - Process refund
GET  /api/v1/loyalty-exclusions         - Loyalty exclusions
POST /api/v1/loyalty-exclusions
DELETE /api/v1/loyalty-exclusions/{exclusion}
GET  /api/v1/loyalty-reports/customers - Loyalty customer report
GET  /api/v1/loyalty-reports/expired   - Expired subscriptions
GET  /api/v1/loyalty-reports/renewals  - Renewal report
GET  /api/v1/loyalty-reports/upgrades  - Upgrade report
GET  /api/v1/loyalty-reports/amount-received - Amount received
GET  /api/v1/loyalty-reports/utilisation - Utilisation report
GET  /api/v1/customer-tiers            - Customer tiers CRUD
POST /api/v1/customer-tiers
PUT  /api/v1/customer-tiers/{id}
DELETE /api/v1/customer-tiers/{id}

# Purchases
POST /api/v1/purchase/calculate        - Purchase pricing preview
GET  /api/v1/purchase/batch-details/{product_id}/{batch_no?} - Batch details
GET  /api/v1/search-purchase-invoice   - Search purchase invoice
GET  /api/v1/purchase                  - Purchases CRUD (idempotent)
POST /api/v1/purchase
PUT  /api/v1/purchase/{id}
DELETE /api/v1/purchase/{id}
GET  /api/v1/purchases-return          - Purchase returns (idempotent)
POST /api/v1/purchases-return
GET  /api/v1/purchases-return/{id}

# Sales
POST /api/v1/sales/calculate           - Sale pricing preview
GET  /api/v1/search-invoice-id         - Search sale invoice
POST /api/v1/generate-upi-qr           - Generate UPI QR code
GET  /api/v1/product-report             - Product sales report
GET  /api/v1/sales                     - Sales CRUD (idempotent)
POST /api/v1/sales
PUT  /api/v1/sales/{id}
DELETE /api/v1/sales/{id}
GET  /api/v1/sales-return              - Sales returns (idempotent)
POST /api/v1/sales-return
GET  /api/v1/sales-return/{id}

# Orders
POST /api/v1/order-management/search   - Search orders
POST /api/v1/order-management/store    - Create order
GET  /api/v1/purchase-order             - Stock report

# Invoices
GET  /api/v1/invoices                  - Invoice list
GET  /api/v1/new-invoice               - New invoice data

# Dues & Collections
GET  /api/v1/dues                      - Due collections (idempotent)
POST /api/v1/dues
GET  /api/v1/customer-dues             - Customer dues (idempotent)
POST /api/v1/customer-dues
GET  /api/v1/outstanding-dues-report    - Outstanding dues report

# Expenses & Income
GET  /api/v1/expense-categories        - Expense categories CRUD
POST /api/v1/expense-categories
PUT  /api/v1/expense-categories/{id}
DELETE /api/v1/expense-categories/{id}
GET  /api/v1/expenses                  - Expenses CRUD
POST /api/v1/expenses
PUT  /api/v1/expenses/{id}
DELETE /api/v1/expenses/{id}
GET  /api/v1/income-categories         - Income categories CRUD
POST /api/v1/income-categories
PUT /api/v1/income-categories/{id}
DELETE /api/v1/income-categories/{id}
GET  /api/v1/incomes                   - Income CRUD
POST /api/v1/incomes

# Warehouse & Storage
GET  /api/v1/warehouses                - Warehouses CRUD
POST /api/v1/warehouses
PUT  /api/v1/warehouses/{id}
DELETE /api/v1/warehouses/{id}
GET  /api/v1/racks                      - Racks CRUD
POST /api/v1/racks
PUT  /api/v1/racks/{id}
DELETE /api/v1/racks/{id}
GET  /api/v1/shelfs                     - Shelves CRUD
POST /api/v1/shelfs
PUT  /api/v1/shelfs/{id}
DELETE /api/v1/shelfs/{id}

# Other
GET  /api/v1/doctors                   - Doctors CRUD
POST /api/v1/doctors
PUT  /api/v1/doctors/{id}
DELETE /api/v1/doctors/{id}
GET  /api/v1/banners                    - Banners list
GET  /api/v1/lang                      - Languages CRUD
POST /api/v1/lang
GET  /api/v1/profile                   - Profile CRUD
POST /api/v1/profile
POST /api/v1/change-password           - Change password
GET  /api/v1/plans                     - Subscription plans
GET  /api/v1/subscribes                 - User subscriptions
GET  /api/v1/currencies                - Currencies list
GET  /api/v1/currencies/{id}
POST /api/v1/bulk-uploads/sales        - Bulk upload sales
POST /api/v1/bulk-uploads/purchases    - Bulk upload purchases
GET  /api/v1/purchase-imports          - Purchase imports
POST /api/v1/purchase-imports
GET  /api/v1/purchase-imports/{id}
PATCH /api/v1/purchase-imports/{id}/lines/{lineId}
GET  /api/v1/purchase-imports/{id}/commit-payload
POST /api/v1/purchase-imports/{id}/committed
DELETE /api/v1/purchase-imports/{id}
POST /api/v1/bulk-uploads              - General bulk upload

# Authentication
GET  /api/v1/sign-out                  - Sign out
GET  /api/v1/auth/sessions             - Active sessions
DELETE /api/v1/auth/sessions/{id}      - Revoke session
GET  /api/v1/refresh-token             - Refresh token
GET  /api/v1/logout-all-users          - Logout all users (admin only)
```

### Middleware Stack
- **auth:sanctum** - Laravel Sanctum authentication
- **throttle:pos-api** - Custom rate limiting
- **throttle:X,Y** - Specific endpoint rate limiting
- **idempotent** - Idempotency guard for financial operations
- **admin** - Admin-only routes

---

## 5. Controllers Analysis

### API Controllers Structure

#### Authentication Controllers
- **AuthController** (`app/Http/Controllers/Api/Auth/AuthController.php`)
  - `login()` - User authentication with device tracking
  - `signUp()` - User registration
  - `submitOtp()` - OTP verification
  - `resendOtp()` - Resend OTP
  - `signOut()` - User logout
  - `sessions()` - List active sessions
  - `revokeSession()` - Revoke specific session
  - `refreshToken()` - Refresh access token
  - `devices()` - Device management
  - `releaseDevice()` - Release device slot
  - `logoutAllUsers()` - Admin logout all
  - `moduleCheck()` - Module availability check

- **AcnooForgotPasswordController** (`app/Http/Controllers/Api/Auth/AcnooForgotPasswordController.php`)
  - `sendResetCode()` - Send password reset code
  - `verifyResetCode()` - Verify reset code
  - `resetPassword()` - Reset password

#### Business Controllers
- **BusinessController** (`app/Http/Controllers/Api/BusinessController.php`)
  - `index()` - List businesses
  - `store()` - Create business
  - `update()` - Update business
  - `updateExpireDate()` - Update subscription expiry
  - `deleteBusiness()` - Delete business (with password confirmation)

- **BusinessSettingController** (`app/Http/Controllers/Api/BusinessSettingController.php`)
  - `index()` - Get business settings

- **BusinessInvoiceSettingController** (`app/Http/Controllers/Api/BusinessInvoiceSettingController.php`)
  - `index()` - Invoice settings
  - `updateInvoice()` - Update invoice settings

#### Product Controllers
- **AcnooProductController** (`app/Http/Controllers/Api/AcnooProductController.php`)
  - `index()` - List products
  - `store()` - Create product
  - `update()` - Update product
  - `destroy()` - Delete product
  - `expiredProducts()` - List expired products
  - `getProductsByLocation()` - Products by warehouse/location

- **AcnooBrandController** - Brand management
- **AcnooCategoryController** - Category management
- **AcnooManufacturerController** - Manufacturer management
- **UnitController** - Unit management
- **ProducModelController** - Product model management
- **ProductSettingsController** - Product settings

#### Stock Controllers
- **StockController** (`app/Http/Controllers/Api/StockController.php`)
  - `index()` - List stocks
  - `store()` - Add stock
  - `update()` - Update stock
  - `destroy()` - Delete stock
  - `summary()` - Stock summary
  - `lowStockProducts()` - Low stock alerts
  - `inventoryHistory()` - Global inventory history
  - `productHistory()` - Product-specific inventory history
  - `stockAuditIndex()` - Stock audit list
  - `stockAuditSubmit()` - Submit stock audit
  - `stockAuditReport()` - Stock audit report
  - `followUpReport()` - Follow-up report
  - `profitLossReport()` - Profit/loss report
  - `profitLossExport()` - Export profit/loss

- **StockDisposalController** - Expired stock disposal
- **WarehouseController** - Warehouse management
- **RackController** - Rack management
- **ShelfController** - Shelf management

#### Sales Controllers
- **AcnooSaleController** (`app/Http/Controllers/Api/AcnooSaleController.php`)
  - `index()` - List sales
  - `store()` - Create sale
  - `update()` - Update sale
  - `destroy()` - Delete sale
  - `calculate()` - Pricing preview
  - `searchInvoiceById()` - Search invoice
  - `generateUpiQr()` - Generate UPI QR code
  - `productReport()` - Product sales report

- **SaleReturnController** - Sales returns
- **AcnooInvoiceController** - Invoice management

#### Purchase Controllers
- **PurchaseController** (`app/Http/Controllers/Api/PurchaseController.php`)
  - `index()` - List purchases
  - `store()` - Create purchase
  - `update()` - Update purchase
  - `destroy()` - Delete purchase
  - `calculate()` - Pricing preview
  - `searchPurchaseInvoice()` - Search invoice
  - `getBatchDetails()` - Batch details

- **PurchaseReturnController** - Purchase returns
- **PurchaseImportController** - Purchase import workflow

#### Party Controllers
- **PartyController** - Party (customer/supplier) management
- **CustomerController** - Customer management
- **AcnooRelationshipController** - Customer relationships
- **AcnooDueController** - Due collection management
- **CustomerDueController** - Customer due management
- **PartyLedgerController** - Party ledger statements

#### Financial Controllers
- **AcnooExpenseController** - Expense management
- **ExpenseCategoryController** - Expense categories
- **AcnooIncomeController** - Income management
- **AcnooIncomeCategoryController** - Income categories
- **PaymentTypeController** - Payment types
- **OutstandingDuesReportController** - Outstanding dues report

#### GST & Tax Controllers
- **GstConfigurationController** - GST settings
- **GstReportController** - GST reports
- **BranchGstConfigurationReportController** - Branch GST configuration

#### Loyalty Controllers
- **LoyaltyPackageController** - Loyalty packages
- **LoyaltySubscriptionController** - Loyalty subscriptions
- **LoyaltyReportController** - Loyalty reports
- **LoyaltyExclusionController** - Loyalty exclusions
- **CustomerTierController** - Customer tiers

#### User Management Controllers
- **AcnooUserController** - User management
- **AcnooProfileController** - User profile
- **DoctorController** - Doctor management

#### System Controllers
- **StatisticsController** - Dashboard statistics
- **AcnooLanguageController** - Language management
- **AcnooCurrencyController** - Currency management
- **AcnooVatController** - VAT management
- **AcnooBannerController** - Banner management
- **BusinessCategoryController** - Business categories
- **AcnooSubscriptionsController** - Subscription plans
- **AcnooSubscribesController** - User subscriptions
- **OrderManagementController** - Order management
- **BulkUploadController** - Bulk uploads
- **CategoryBulkAddController** - Category bulk add to cart

---

## 6. Form Request Validation

### Validation Classes Location
`app/Http/Requests/Api/`

### Key Validation Classes

#### Product Validation
- **StoreProductRequest** - Product creation validation
- **UpdateProductRequest** - Product update validation

#### Party Validation
- **StorePartyRequest** - Party creation validation
- **UpdatePartyRequest** - Party update validation
- **StoreCustomerRequest** - Customer creation validation
- **UpdateCustomerRequest** - Customer update validation

#### Sales Validation
- **StoreSaleRequest** - Sale creation validation
- **SearchSaleInvoiceRequest** - Invoice search validation
- **CollectDueAmountRequest** - Due collection validation

#### Purchase Validation
- **PurchaseMutationRequest** - Purchase mutation validation

#### Master Data Validation
- **StoreBrandRequest** - Brand creation validation
- **UpdateBrandRequest** - Brand update validation
- **StoreCategoryRequest** - Category creation validation
- **UpdateCategoryRequest** - Category update validation
- **StoreUnitRequest** - Unit creation validation
- **UpdateUnitRequest** - Unit update validation
- **StoreManufacturerRequest** - Manufacturer creation validation
- **UpdateManufacturerRequest** - Manufacturer update validation
- **StoreVatRequest** - VAT creation validation
- **UpdateVatRequest** - VAT update validation
- **StoreWarehouseRequest** - Warehouse creation validation
- **UpdateWarehouseRequest** - Warehouse update validation
- **StoreRackRequest** - Rack creation validation
- **UpdateRackRequest** - Rack update validation
- **StoreShelfRequest** - Shelf creation validation
- **UpdateShelfRequest** - Shelf update validation

#### Financial Validation
- **StoreExpenseRequest** - Expense creation validation
- **UpdateExpenseRequest** - Expense update validation
- **StoreIncomeRequest** - Income creation validation

#### Settings Validation
- **StoreProductSettingsRequest** - Product settings validation
- **MasterNameRequest** - Generic name validation
- **StoreRelationshipRequest** - Relationship creation validation
- **UpdateRelationshipRequest** - Relationship update validation

### Base Validation
- **BaseApiRequest** (`app/Http/Requests/Api/BaseApiRequest.php`)
  - Input sanitization
  - Field formatting (name, email, phone, SKU)
  - XSS prevention
  - SQL injection prevention
  - Standardized JSON error responses (HTTP 422)

---

## 7. Security Components

### Middleware

#### Authentication & Authorization
- **Authenticate** - Laravel authentication
- **AdminMiddleware** - Admin-only access
- **UserMiddleware** - User-only access
- **AffiliatorMiddleware** - Affiliator access
- **CheckPermission** - Permission-based access control
- **CheckActiveBranch** - Active branch validation

#### Security
- **SanitizeInputMiddleware** - Input sanitization
- **TrimStrings** - String trimming
- **TrustProxies** - Proxy trust configuration
- **TrustHosts** - Host validation
- **ValidateSignature** - Request signature validation
- **VerifyCsrfToken** - CSRF protection

#### Rate Limiting
- **Throttle** - Rate limiting middleware

#### System
- **CheckDomain** - Domain validation
- **DemoMode** - Demo mode restrictions
- **ExpiredMiddleware** - Subscription expiry check
- **TokenExpired** - Token expiry check
- **PreventRequestsDuringMaintenance** - Maintenance mode
- **RedirectIfAuthenticated** - Auth redirect

#### API
- **ApiResponseHeaders** - API response headers
- **RemoveVersionHeader** - Version header removal
- **EnsureIdempotentRequest** - Idempotency key validation
- **BlockLegacyInventoryWrite** - Legacy inventory write blocking

#### Localization
- **SetLocale** - Locale setting

### Custom Validation Rules

#### Security Rules
- **NoXssRule** (`app/Rules/NoXssRule.php`)
  - Detects script tags, iframe tags, object tags
  - Blocks javascript:, vbscript: protocols
  - Detects on* event handlers
  - Blocks suspicious data: URLs
  - Detects CSS expressions
  - Blocks eval(), document.write, etc.

- **NoSqlInjectionRule** (`app/Rules/NoSqlInjectionRule.php`)
  - Detects SQL keywords (UNION, SELECT, INSERT, etc.)
  - Blocks SQL comments
  - Detects SQL functions
  - Blocks time-based attacks
  - Detects error-based attacks
  - Blocks hex-encoded content

### Helpers

#### HasUploader Trait
- **Location:** `app/Helpers/HasUploader.php`
- **Methods:**
  - `upload()` - File upload with optimization
  - `uploadWithFileName()` - Upload with custom filename
  - `multipleUpload()` - Multiple file upload
- **Features:**
  - Image optimization (WEBP/JPEG)
  - Size compression (max 50KB)
  - Base64 support
  - Automatic path generation
  - Storage: `public/uploads/{year}/{month}/`

#### DataSanitizer
- **Location:** `app/Helpers/DataSanitizer.php`
- **Purpose:** Data sanitization utilities

#### Helper
- **Location:** `app/Helpers/Helper.php`
- **Purpose:** General helper functions

---

## 8. Data Flow Analysis

### User Registration Flow
```
1. POST /api/v1/sign-up
   ↓
2. AuthController@signUp()
   ↓
3. SignUpRequest validation
   ↓
4. Create User record
   ↓
5. Generate OTP
   ↓
6. Send OTP to phone/email
   ↓
7. Return user data
```

### Login Flow
```
1. POST /api/v1/sign-in
   ↓
2. AuthController@login()
   ↓
3. LoginRequest validation
   ↓
4. Check credentials
   ↓
5. Device tracking (max 4 devices)
   ↓
6. Generate Sanctum token
   ↓
7. Return token + user data
```

### Product Creation Flow
```
1. POST /api/v1/products
   ↓
2. AcnooProductController@store()
   ↓
3. StoreProductRequest validation
   ↓
4. Upload product image (HasUploader)
   ↓
5. Create Product record
   ↓
6. Create Stock record (if stock provided)
   ↓
7. Return product data
```

### Purchase Flow
```
1. POST /api/v1/purchase/calculate (optional - pricing preview)
   ↓
2. POST /api/v1/purchase
   ↓
3. PurchaseController@store()
   ↓
4. PurchaseMutationRequest validation
   ↓
5. Idempotency check
   ↓
6. DB transaction start
   ↓
7. Create Purchase record
   ↓
8. Create PurchaseDetails records
   ↓
9. Create/Update Stock records
   ↓
10. Update Party due balance
   ↓
11. DB commit
   ↓
12. Return purchase data
```

### Sale Flow
```
1. POST /api/v1/sales/calculate (optional - pricing preview)
   ↓
2. POST /api/v1/sales
   ↓
3. AcnooSaleController@store()
   ↓
4. StoreSaleRequest validation
   ↓
5. Idempotency check
   ↓
6. DB transaction start
   ↓
7. Create Sale record
   ↓
8. Create SaleDetails records
   ↓
9. Update Stock quantities (FIFO)
   ↓
10. Update PurchaseDetails conversionQuantity
   ↓
11. Update Party due balance
   ↓
12. Calculate profit/loss
   ↓
13. DB commit
   ↓
14. Return sale data
```

### Stock Audit Flow
```
1. GET /api/v1/stock-audit
   ↓
2. StockController@stockAuditIndex()
   ↓
3. List products with stock
   ↓
4. Return audit list
   ↓
5. POST /api/v1/stock-audit-submit
   ↓
6. StockController@stockAuditSubmit()
   ↓
7. StockAuditSubmitRequest validation
   ↓
8. DB transaction start
   ↓
9. Create StockAudit records
   ↓
10. Update Stock quantities
   ↓
11. DB commit
   ↓
12. Return audit result
```

---

## 9. File Upload System

### Upload Path Structure
```
Physical: /home/surya/Documents/pos-inventory-web/public/uploads/{yy}/{mm}/{filename}
URL: https://admin.naayahs.com/uploads/{yy}/{mm}/{filename}
```

### Upload Process
```
1. File received in request
   ↓
2. HasUploader@upload()
   ↓
3. Image optimization (resize if > 1200px)
   ↓
4. Compression (quality 75 → 60 → 40 → 20 until < 50KB)
   ↓
5. Convert to WEBP (if supported) or JPEG
   ↓
6. Generate filename: {timestamp}-{random}.{ext}
   ↓
7. Store in: uploads/{year_2digit}/{month}/
   ↓
8. Return file path
   ↓
9. Store path in database
```

### Supported Formats
- **Input:** JPEG, JPG, PNG, WEBP
- **Output:** WEBP (preferred) or JPEG
- **Max Size:** 5MB (input), 50KB (output)

---

## 10. Modular Architecture

### Modules Directory Structure
```
Modules/
├── Business/              # Core business logic
├── HrmAddon/             # HR Management addon
├── MultiBranchAddon/     # Multi-branch support
├── ThermalPrinterAddon/  # Printer integration
└── WarehouseAddon/       # Warehouse management
```

### Module System
- Each module is self-contained
- Can be enabled/disabled via `moduleCheck()`
- Module-specific routes, controllers, models
- Shared database with module-specific tables

---

## 11. Key Relationships Summary

### Business-Centric Data
All major entities are scoped to a business:
- Users belong to businesses
- Products belong to businesses
- Parties belong to businesses
- Sales belong to businesses
- Purchases belong to businesses
- Stocks belong to businesses

### Multi-Branch Support
- Users can be assigned to branches
- Stocks are branch-scoped
- Sales can be filtered by branch
- Branch scope applied via global scope

### Inventory Flow
```
Purchase → PurchaseDetails → Stock → SaleDetails → Sale
```

### Financial Flow
```
Party → Purchase/Sale → Due Collection → Party Balance
```

---

## 12. Security Features

### Authentication
- Laravel Sanctum for API authentication
- Device tracking (max 4 devices per user)
- Session management
- Token refresh mechanism

### Rate Limiting
- Custom rate limiting per endpoint
- Throttling for sensitive operations (login, OTP, password reset)
- Global API throttle: `throttle:pos-api`

### Input Validation
- Form Request classes for all endpoints
- Custom security rules (NoXssRule, NoSqlInjectionRule)
- Input sanitization middleware
- Field formatting and normalization

### Idempotency
- Financial operations guarded by idempotency middleware
- Prevents duplicate transactions on retry
- Uses Idempotency-Key header

### Authorization
- Role-based access control (admin, shop-owner, staff)
- Permission system with visibility JSON
- Custom permission checking methods
- Module-based feature access

---

## 13. Configuration Files

### Key Config Files
- **config/filesystems.php** - Filesystem configuration (public disk)
- **config/logging.php** - Logging channels (security.log)
- **config/auth.php** - Authentication configuration
- **app/Http/Kernel.php** - Middleware stack
- **app/Exceptions/Handler.php** - Exception handling

---

## 14. Database Indexes & Constraints

### Foreign Keys
- All major tables have foreign key constraints
- Cascade on delete for related data
- Nullable foreign keys where appropriate

### Unique Constraints
- users.email
- users.phone
- parties.phone
- products.productCode (per business)
- warehouses.email

### Indexes
- business_id on all business-scoped tables
- Composite indexes for common queries
- Timestamp indexes for date-based queries

---

## 15. API Response Format

### Success Response
```json
{
  "message": "Success message",
  "data": { ... }
}
```

### Validation Error Response
```json
{
  "message": "Validation failed",
  "errors": {
    "field": ["Error message"]
  }
}
```

### Standard HTTP Status Codes
- 200 - Success
- 201 - Created
- 400 - Bad Request
- 401 - Unauthorized
- 403 - Forbidden
- 404 - Not Found
- 406 - Not Acceptable
- 422 - Validation Error
- 500 - Server Error

---

## 16. Special Features

### GST Compliance
- GST configuration per business
- GSTIN validation and normalization
- GST effective date tracking
- GST configuration history (audit trail)
- Branch-specific GST settings
- GST reports

### Loyalty System
- Loyalty packages (subscription-based)
- Customer loyalty subscriptions
- Loyalty point tracking
- Loyalty exclusions
- Six loyalty reports
- Customer tiers (printed on invoice)

### Multi-Branch
- Branch management
- Branch-scoped inventory
- Branch-specific GST settings
- Branch balance tracking
- User branch assignment

### Warehouse Management
- Warehouse locations
- Rack and shelf organization
- Location-based product tracking
- Warehouse-specific stock

### Stock Management
- Batch-level tracking
- FIFO stock deduction
- Expiry date tracking
- Stock audit functionality
- Expired stock disposal
- Low stock alerts
- Inventory change logs

### Financial Management
- Due collection tracking
- Outstanding balance reports
- Party ledger statements
- Expense and income tracking
- Multiple payment types
- Currency support

---

## 17. File Relationships Map

### Core Flow
```
routes/api.php
    ↓
Controllers (app/Http/Controllers/Api/)
    ↓
Form Requests (app/Http/Requests/Api/)
    ↓
Models (app/Models/)
    ↓
Database Tables (migrations)
```

### Security Flow
```
Middleware (app/Http/Middleware/)
    ↓
Validation Rules (app/Rules/)
    ↓
BaseApiRequest (app/Http/Requests/Api/)
    ↓
Controllers
```

### Upload Flow
```
Controllers
    ↓
HasUploader Trait (app/Helpers/)
    ↓
Storage (config/filesystems.php)
    ↓
public/uploads/
```

---

## 18. Conclusion

This POS Inventory System is a comprehensive Laravel 10 application with:

- **Multi-tenant architecture** with business scoping
- **Modular design** with addon support
- **Comprehensive inventory management** with batch tracking
- **Financial tracking** with sales, purchases, and dues
- **GST compliance** with reporting and audit trails
- **Loyalty system** with subscriptions and reporting
- **Multi-branch support** with branch-scoped data
- **Robust security** with authentication, authorization, and validation
- **API-first design** with RESTful endpoints
- **Image optimization** with automatic compression
- **Idempotency guards** for financial operations

The codebase follows Laravel best practices with proper separation of concerns, Eloquent relationships, and comprehensive validation.
