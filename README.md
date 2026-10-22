# SRVP — Student Request & Payment Portal

A PHP + MySQL web application for Stratford International School where students request registrar documents, make secure online payments, and track status — with a frosted-glass (glassmorphism) UI on every page.

## System Objectives

The main objectives of the system are:

1. **Guest Request Workflow**: Students can request documents without requiring login or a dashboard. They fill out a paper-style form with their personal information and submit requests directly to the admin/cashier workflow.

2. **Secure Online Payments**: Students pay through GCash by scanning a QR code and uploading a payment screenshot. The system validates the screenshot before forwarding the request to the cashier for verification.

3. **Dual Notification System**: Students receive notifications via both Gmail and SMS when their document is ready for pickup, ensuring they are informed regardless of their preferred communication channel.

4. **Request Rejection with Reasons**: When the cashier or registrar rejects a request, they must provide a specific reason. The system automatically notifies the student via email and SMS with the detailed rejection reason.

5. **Receipt Generation**: The system automatically generates official e-receipts when payments are verified. Students can view, download, or print proof of payment for their records.

6. **Enhanced Communication and Transparency**: The system maintains clear communication between students, cashiers, and registrars through detailed status tracking, audit logs, and rejection reason documentation.

## Features

**Public Landing Page**
- Services display with fees and processing times
- Guest request initiation without login requirement
- School branding with customizable background and logo

**Guest Request Workflow**
- Paper-style form with blank underline fields
- Full-screen layout for better document-like experience
- Collects: full name, address, Gmail, course, year level, contact number
- QR code display for GCash payment
- Screenshot upload with drag-and-drop interface
- Confirmation overlay with reference number after submission

**Admin Login (Registrar & Cashier Only)**
- Role-based login with role selection buttons
- Default accounts for registrar and cashier
- No student login required (guest workflow handles requests)

**Registrar Dashboard**
- Review and approve/reject pending requests
- Mark documents as ready for pickup
- Generate rejection reasons with automatic student notification
- View service statistics and request pipeline
- Full audit log access

**Cashier Dashboard**
- Verify payment screenshots from guest requests
- Record cash payments for walk-in students
- Approve/reject payment receipts
- Generate rejection reasons with automatic student notification
- View collected payments ledger

**Notifications**
- SMS notifications to student's contact number when request is ready
- Email notifications to student's Gmail when request is ready
- Rejection notifications via both SMS and email with detailed reasons
- Request status updates for approved, processing, ready, and released statuses

## How the system follows the data flow diagram

|| DFD process | Who | Where in the app |
||---|---|---|
|| 1.0 Guest Request Submission | Guest (no login required) | `guest-request.php` — paper-style form with personal info, GCash QR payment, screenshot upload |
|| 2.0 Payment Screenshot Validation | System | `guest-request.php` — validates uploaded image before submission |
|| 3.0 Payment Verification | Cashier | `admin/process.php` — verify guest payment screenshots or record cash payments |
|| 4.0 Request Verification | Registrar | `admin/process.php` — approve or reject requests with reason |
|| 5.0 Request Processing | Registrar | `admin/process.php` — processing → ready → released |
|| 6.0 Receipt Generation | System | Issued automatically when payment becomes paid |
|| 7.0 SMS Notifications | System → Student | `includes/sms.php` — notifies via SMS when ready or rejected |
|| 8.0 Email Notifications | System → Student | `includes/mail.php` — notifies via Gmail when ready or rejected |
|| 9.0 Rejection Documentation | Registrar/Cashier | Modal dialog with reason input, stored in database |

Request life cycle: `pending` →(cashier verifies payment)→ `approved` →(registrar)→ `processing` →(registrar)→ `ready` →(registrar)→ `released`.
Rejection can occur at any stage with detailed reason provided and communicated to student.

Data stores: `students`, `cashiers`, `registrars` · `services` · `audit_logs` · `requests` · `payments` · `receipts` · `sms_notifications` · `site_settings`.

### SMS notifications

Every status change writes a message to `sms_notifications` and sends it to the student's mobile number (saved at registration or in the profile).
Set `SMS_MODE` in `.env.local`:

- `log` (default): nothing is sent; messages are stored in the database and appended to `var/sms/sms.log`.
- `textbee`: free option for demos. Install the TextBee app on an Android phone, register the device at [textbee.dev](https://textbee.dev), and put its API key in `SMS_API_KEY`. The text is sent from that phone's SIM (free plan: 50 messages/day).
- `semaphore`: sends real SMS through [Semaphore](https://semaphore.co) (set `SMS_API_KEY` and `SMS_SENDER_NAME`). This path has not been tested against the live service.

Students without a number still see every message in their Gmail.

### Email notifications

Email notifications are sent to the student's Gmail address for:
- Request approval
- Request rejection with detailed reason
- Request processing status
- Document ready for pickup
- Document release

## Requirements

- XAMPP (or any stack with PHP 8.0+ and MySQL/MariaDB)
- No Composer or npm dependencies

## Install (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\` (or point the Apache DocumentRoot at it).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Open <http://localhost/setup.php> — it creates the database, tables and seed data.
   Re-running it is safe; delete the file on production.
4. Open <http://localhost/> to see the landing page.

### Admin Login Credentials

- **Registrar**: Email = `registrar.admin@gmail.com`, Password = `Admin@1234`
- **Cashier**: Email = `cashier.admin@gmail.com`, Password = `Admin@1234`

## Configuration

Database credentials live in `config/database.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
Defaults match a stock XAMPP install (`root`, empty password).

## Structure

```
config/database.php   PDO connection + session bootstrap
includes/             auth guards, helpers, glass layout (header/footer)
admin/                dashboard, requests, process, payments, reports, services, instructions, audit
student/              dashboard, services, request, requests, payment, profile, receipt, notifications, instructions
guest-request.php      Guest request form (no login required)
index.php              Public landing page
login.php              Admin login (registrar/cashier only)
assets/               style.css (frosted-glass design system), main.js
uploads/              receipt images (PHP execution blocked via .htaccess)
database.sql          Unified single schema + stored procedures + seed data
```

## Security notes

- Passwords hashed with `password_hash()` (bcrypt)
- All queries use prepared statements (PDO)
- CSRF tokens on every mutating form; `session_regenerate_id()` on login
- Uploads validated by real image type & size; `.htaccess` blocks script execution
- Output escaped with `e()` (htmlspecialchars) throughout
