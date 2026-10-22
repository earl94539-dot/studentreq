# SRVP — Student Request & Payment Portal

A PHP + MySQL web app where students request registrar documents, record payments,
and track their status — with a frosted-glass (glassmorphism) UI on every page.

## Features

**Student**
- Register / sign in (student number or email)
- Browse services with fees & processing times
- Submit document requests with notes (gets a reference number like `REQ-20260925-A1B2C3`)
- Record payments (cash / GCash / Maya / bank) with reference no. + optional receipt image upload
- Track every request on a status timeline (pending → processing → ready → released)
- Printable official receipt per request
- Profile: update email / course / year level, change password

**Admin**
- Dashboard KPIs: students, pipeline stages, collected revenue
- Review each request: verify payment, set status, leave a note for the student
- Payments ledger with per-method totals and receipt previews
- Full CRUD for services (name, description, fee, processing days, active toggle)
- Registrar: payments & receipt reports with CSV export; cashier: payments ledger and cash walk-in payments
- Audit log of every administrative action

## How the system follows the data flow diagram

| DFD process | Who | Where in the app |
|---|---|---|
| 1.0 Manage account | Student, cashier, registrar | `register.php`, `login.php`, `logout.php`, `student/profile.php` (logins and logouts go to the audit log) |
| 2.0 Manage services | Registrar | `admin/services.php` |
| 3.0 Services request and submission | Student | `student/services.php`, `student/request.php` (request log written by a database trigger) |
| 4.0 Request verification | Registrar | `admin/process.php` — verify (approve) or decline a pending request, logged as `request_verified` / `request_rejected` |
| 4.1 Payment transaction | Cashier | `admin/process.php` — verify or decline the student's receipt, or record a cash payment. Only possible after the registrar verified the request. Logged as `payment_verified`, `payment_declined`, `payment_cash` |
| 5.0 Receipt generation | System | Issued automatically (database trigger) when a payment becomes paid; shown in `student/receipt.php` |
| 6.0 Payments and receipt reports | Registrar | `admin/reports.php` (date filter + CSV export) |
| 7.0 Request processing | Registrar | `admin/process.php` — processing → ready → released |
| 8.0 Document update notification | System → student | `includes/sms.php`, inbox at `student/notifications.php` |
| 9.0 / 10.0 Manage and display instruction | Registrar / student | `admin/instructions.php`, `student/instructions.php` |

Request life cycle: `pending` →(registrar verifies)→ `approved` →(student pays, cashier verifies)→ `processing` →(registrar)→ `ready` →(registrar)→ `released`.
These rules are enforced on the server, not only hidden in the UI: only the registrar can change a request's status, only the cashier can verify or record payments.

Data stores: D:1–D:3 `students`, `cashiers`, `registrars` · D:4 `services` · D:5 `audit_logs` · D:6 `requests` · D:7 `payments` · D:8 `receipts` · D:9 `sms_notifications` · D:10 `site_settings`.

### SMS notifications

Every status change writes a message to `sms_notifications` (D:9) and sends it to the student's mobile number (saved at registration or in the profile).
Set `SMS_MODE` in `.env.local`:

- `log` (default): nothing is sent; messages are stored in the database and appended to `var/sms/sms.log`.
- `textbee`: free option for demos. Install the TextBee app on an Android phone, register the device at [textbee.dev](https://textbee.dev), and put its API key in `SMS_API_KEY`. The text is sent from that phone's SIM (free plan: 50 messages/day).
- `semaphore`: sends real SMS through [Semaphore](https://semaphore.co) (set `SMS_API_KEY` and `SMS_SENDER_NAME`). This path has not been tested against the live service.

Students without a number still see every message in their Notifications page.

### Upgrading an existing database

Open `setup.php` once (it is safe to re-run), or import `migrations/dfd_alignment.sql`. This adds `students.phone`, the `cash` payment method and the `sms_notifications` table.

## Requirements

- XAMPP (or any stack with PHP 8.0+ and MySQL/MariaDB)
- No Composer or npm dependencies

## Install (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\` (or point the Apache DocumentRoot at it).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Open <http://localhost/setup.php> — it creates the database, tables and seed data.
   Re-running it is safe; delete the file on production.
4. Open <http://localhost/> and sign in.

### Roles & ID Formats

| Role | ID Format | Example ID | Email Requirement | Default Demo Login | Password |
|---|---|---|---|---|---|
| **Student** | `YYYY-NNNNN` | `2024-10001` | `@gmail.com` | Register on portal | Your chosen password |
| **Cashier** | `CSH-XXXXX` | `CSH-00001` | `@gmail.com` | `cashier.admin@gmail.com` or `CSH-00001` | `Admin@1234` |
| **Registrar** | `REG-XXXXX` | `REG-00001` | `@gmail.com` | `registrar.admin@gmail.com` or `REG-00001` | `Admin@1234` |

> **Note:** All roles can be registered directly at `/register.php`. The system automatically detects your role based on the ID prefix (`CSH-` for Cashier, `REG-` for Registrar, or `YYYY-NNNNN` for Student) and mandates a `@gmail.com` address.

## Configuration

Database credentials live in `config/database.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
Defaults match a stock XAMPP install (`root`, empty password).

## Structure

```
config/database.php   PDO connection + session bootstrap
includes/             auth guards, helpers, glass layout (header/footer)
admin/                dashboard, requests, process, payments, reports, services, instructions, audit
student/              dashboard, services, request, requests, payment, profile, receipt, notifications, instructions
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
