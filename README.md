# 🚀 Equinox: Beyond Money
### A Professional Reciprocity Finance Platform | DBMS Project

> **Equinox** is a modern, production-grade community payment and trust ecosystem built on MySQL, demonstrating advanced database concepts, payment processing, and real-time transaction management.

---

## ✨ Key Features

### 🏦 Wallet Management
- **Dynamic Wallet Activation** - Verified payment-based onboarding with 500 Eq credits
- **Virtual Card Issuance** - Up to 3 cards per wallet with freeze controls and audit trails
- **Real-time Balance Tracking** - Instant updates across all transactions
- **Wallet Identity QR** - Dynamic QR codes for seamless peer-to-peer transfers

### 💳 Payment Methods
- **Razorpay Checkout** - Professional gateway integration for wallet activation and card issuance
- **UPI Payments** ⭐ **NEW** - Direct UPI ID payments for alternative transactions
- **P2P Transfers** - Direct wallet-to-wallet payments with GPS audit trails
- **Payment History** - Complete audit log with timestamps and device fingerprints

### 🤝 Trust & Reputation System
- **Smart Trust Scoring** - Algorithm-based scoring (0-100) based on:
  - Transaction volume and frequency
  - Completed service contracts
  - User reviews and ratings
  - Account age and activity
- **Trust Tiers** - Neutral → Stable → Resonant → Radiant
- **Reputation Tracking** - Real-time reputation labels and trust history

### 🛠️ Skill Marketplace
- **Skill Listings** - Publish services with hourly rates
- **Service Contracts** - Atomic contract settlement with automatic trust updates
- **Peer Reviews** - 1-5 star review system with automatic trust recalculation
- **Marketplace Directory** - Discover and hire citizens by trust level

### 🎯 Community Pools
- **Crowdfunding** - Create and manage community fundraising campaigns
- **Atomic Contributions** - Secure pool deposits with real-time progress tracking
- **Auto-Completion** - Pools automatically close when target is reached
- **Contribution Tracking** - Complete audit of all pool transactions

### 📊 SQL Demonstrator
- **Live Query Results** - Execute read-only queries against production data
- **Preset Queries** - Pre-built demonstrations for:
  - Trust score distribution
  - Transaction analysis
  - User activity metrics
  - Payment order status
- **Schema Visualization** - See actual database structure and relationships

---

## 🗄️ Database Architecture

### Core Tables
```
├── users_profile          (Profile, KYC, trust scores)
├── wallets_equinox        (Wallet balances, activation state)
├── transactions_master    (Complete ledger, GPS tracking)
├── payment_orders         (Razorpay integration, fulfillment)
├── equinox_cards          (Virtual cards, freeze state)
├── service_contracts      (Skill marketplace, settlement)
├── user_reviews           (Trust system feedback)
├── community_pools        (Crowdfunding campaigns)
├── trust_scores           (Calculated trust metrics)
└── login_history          (Security audit trail)
```

### Advanced Features
- **Stored Procedures** - Complex business logic encapsulated in database
- **Triggers** - Automatic calculations and cascading updates
- **JOINs** - Complex multi-table queries for reporting
- **Transactions** - ACID-compliant payment processing
- **Indexes** - Optimized query performance on high-volume tables

---

## 🚀 Quick Start

### Prerequisites
- **PHP 8.0+** with PDO support
- **MySQL 5.7+** (or MariaDB 10.3+)
- **Modern browser** (Chrome, Firefox, Safari, Edge)

### Installation

**1. Import Database Schema**
```bash
mysql -u root < database/schema.sql
```

**2. Configure Environment**
Edit `.env`:
```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=              # Leave empty if no password
DB_NAME=equinox_dbms

VITE_RAZORPAY_KEY_ID=rzp_test_RzYF4GJPLG8zoR
VITE_RAZORPAY_KEY_SECRET=fpRado9evw4btr4uRDkGaxz0
```

**3. Start Development Server**
```bash
php -S localhost:3002 router.php
```

**4. Open in Browser**
```
http://localhost:3002
```

---

## 💡 Demo Walkthrough

### 1️⃣ Register & Activate Wallet
- Create a new citizen account with email/password
- Initiate wallet activation (Rs. 1,500 payment)
- Complete Razorpay test payment
- Receive 500 Eq credits on success

### 2️⃣ Send Payments
- Generate QR code for your wallet
- Send Eq to other citizens using:
  - Wallet ID (copy-paste or scan)
  - **UPI ID** ⭐ (new feature - no payment required!)
  - Direct citizen directory selection

### 3️⃣ Issue Virtual Cards
- Request new card (Rs. 1,500 payment)
- Receive card number, expiry, CVV
- Manage up to 3 cards
- Freeze/unfreeze cards for security

### 4️⃣ Explore Marketplace
- Publish your skills with hourly rates
- Browse other citizens' offerings
- Create service contracts
- Settle completed work automatically

### 5️⃣ Build Community
- Create community pools for fundraising
- Contribute Eq to active pools
- Watch pools auto-complete at target
- Review counterparties to build trust

### 6️⃣ Check Database
- Run live SQL queries in demonstrator
- See trust score calculations in real-time
- Export transaction data
- Validate database design

---

## 🔑 Test Credentials

### Razorpay Test Mode
- **Key ID**: `rzp_test_RzYF4GJPLG8zoR`
- **Key Secret**: `fpRado9evw4btr4uRDkGaxz0`
- **Test Card**: 4111 1111 1111 1111 (any future date, any CVV)

### Create Test Users
1. Register **User A** - For sending payments
2. Register **User B** - For receiving payments
3. Activate both wallets with test payment
4. Try P2P transfers between them

---

## 📁 Project Structure

```
equinox_dbms/
├── templates/
│   ├── app.html          (Main UI)
│   ├── app.js            (Client logic)
│   └── styles.css        (Styling)
├── public/
│   └── vendor/           (QR & scanning libraries)
├── src/
│   ├── App.php           (API endpoints)
│   ├── Database.php      (MySQL connection)
│   ├── Auth.php          (JWT auth)
│   ├── Response.php      (JSON responses)
│   └── Env.php           (Configuration)
├── database/
│   └── schema.sql        (Database schema + procedures/triggers)
├── router.php            (Development router)
└── .env                  (Environment variables)
```

---

## 🎨 UI Features

- 🌙 **Dark Mode** - Default dark theme with cyan accents
- 📱 **Mobile Responsive** - Works on all devices (desktop, tablet, mobile)
- 🎯 **Intuitive Navigation** - Sidebar menu with clear sections
- ⚡ **Real-time Updates** - Dashboard refreshes every 12 seconds
- ♿ **Accessibility** - Keyboard navigation, ARIA labels, high contrast

---

## 🔒 Security & Compliance

- ✅ **JWT Authentication** - Stateless, verifiable tokens with expiration
- ✅ **Password Hashing** - Bcrypt with salt
- ✅ **Card CVV Protection** - Hashed storage, never logged
- ✅ **Razorpay Signature Verification** - Validates payment integrity
- ✅ **GPS + Device Tracking** - Fraud detection audit trail
- ✅ **CSRF-safe APIs** - Bearer token validation on all endpoints
- ✅ **SQL Injection Prevention** - Parameterized queries throughout
- ✅ **XSS Protection** - HTML escaping on all user inputs

---

## 📊 API Endpoints

### Authentication
```
POST   /api/register              Create new citizen
POST   /api/login                 Sign in with email/password
GET    /api/bootstrap             Load authenticated user data
```

### Payments
```
POST   /api/payments/create-order Initiate Razorpay order
POST   /api/payments/verify       Verify and fulfill payment
POST   /api/payments/upi          Process UPI payment ⭐ NEW
```

### Wallets
```
POST   /api/wallet/transfer       P2P Eq transfer
POST   /api/cards/freeze          Toggle card freeze state
```

### Marketplace
```
POST   /api/skills                Publish skill listing
POST   /api/contracts             Create service contract
POST   /api/contracts/settle      Settle completed contract
POST   /api/reviews               Submit review & update trust
```

### Community
```
POST   /api/pools                 Create community pool
POST   /api/pools/contribute      Add pool contribution
```

### Queries
```
POST   /api/query-demonstrator    Execute safe read-only queries
```

---

## 🚨 Troubleshooting

| Problem | Solution |
|---------|----------|
| **"Connection refused" error** | Verify MySQL is running: `mysql -u root` |
| **404 Not Found on /api/...** | Ensure PHP server running: `php -S localhost:3002` |
| **Razorpay checkout doesn't open** | Check .env has correct Razorpay test keys |
| **QR code not showing** | Browser may need camera/canvas permissions |
| **Trust scores not updating** | Verify MySQL procedure: `SHOW TRIGGERS;` |
| **UPI form not appearing** | Clear browser cache (Ctrl+Shift+Delete) and refresh |

---

## 💾 MySQL Workbench Connection

Use these values to connect in **MySQL Workbench**:

- **Connection Name**: `Equinox DBMS`
- **Connection Method**: `Standard (TCP/IP)`
- **Hostname**: `localhost`
- **Port**: `3306`
- **Username**: `root`
- **Password**: *(leave empty if no password)*
- **Default Schema**: `equinox_dbms`

---

## 🎓 Learning Outcomes

This project demonstrates:

- ✅ **Complex SQL** - JOINs, aggregations, subqueries, window functions
- ✅ **Stored Procedures** - Business logic in database with parameters
- ✅ **Triggers** - Automated calculations and cascading updates
- ✅ **Transactions** - ACID compliance with multi-step operations
- ✅ **Indexing** - Query optimization for performance
- ✅ **RESTful APIs** - Standard HTTP methods with proper status codes
- ✅ **Authentication** - JWT tokens with expiration and validation
- ✅ **Payment Integration** - Razorpay gateway with signature verification
- ✅ **Real-time Updates** - Database polling for live dashboards
- ✅ **Data Validation** - Input sanitization and business rule enforcement
- ✅ **Error Handling** - Graceful failures with meaningful messages
- ✅ **Security** - HTTPS-ready, SQL injection prevention, XSS protection

---

## 📸 Feature Showcase

### Dashboard
- 👤 User profile with trust tier
- 💰 Available balance and wallet status
- 📊 Trust score visualization (conic gradient)
- 🔔 Notification feed
- 📈 Recent transaction activity

### Wallet Section
- 🆔 Wallet ID with copy-to-clipboard
- 🎫 QR code generation and scanning
- 💸 **New: UPI payment option**
- 📤 P2P transfer form with GPS tracking

### Cards & Payments
- 🎴 Virtual card management
- ❄️ Freeze/unfreeze controls
- 📋 Payment order history
- ✅ Fulfillment status tracking

### Marketplace
- 🛠️ Skill listings with rates
- 📝 Service contracts
- ⭐ Review & rating system
- 👥 Citizen directory with trust levels

### Community Pools
- 🎯 Create fundraising campaigns
- 📊 Live progress bars
- 🤝 Contribution tracking
- 🏆 Auto-completion on target

---

## 📞 Support

For issues or questions:
1. ✅ Check the **Support** section in the app
2. ✅ Review MySQL error logs
3. ✅ Verify all environment variables
4. ✅ Ensure database schema is current

---

## 📄 License

**Academic Project** - For educational purposes as part of DBMS coursework.

---

<div align="center">

### 🌟 Made with ❤️ for DBMS Learning

**Equinox** © 2024 | SIT Pune Semester 4

If this project helped you learn, please **star** it! ⭐

</div>
