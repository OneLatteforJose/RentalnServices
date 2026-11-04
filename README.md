# RentalnServices

A web system where users book vehicles (cars, vans, trucks, with or without a driver) or request local services
(handyman, plumbing, cleaning, electrical, beauty, laundry, gardening, driver, body massage).
Each city or municipality has a manager who approves bookings and requests. Providers own the vehicles and do the services.

**Status:** the admin side, the public landing page and customer sign-up/sign-in are built. Booking and service requests, and the manager and provider sides, are not.

## Setup (XAMPP)

1. Put this folder in `C:\xampp\htdocs\RentalnServices`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. In phpMyAdmin, create a database named `booking_and_services` (collation `utf8mb4_general_ci`) and import `booking_and_services.sql`.
   The file drops and recreates every table, so only import it into an empty database or one you can reset.
4. Create the first admin from the project folder:
   ```
   C:\xampp\php\php.exe create_admin.php "Your Name" you@example.com "a-strong-password"
   ```
5. Open <http://localhost/RentalnServices/>. Admins log in at the bottom of the page. Customers use **Sign up** (`register.php`) to create an account.

Database settings default to `localhost`, port `3306`, user `root`, no password.
To change them, copy `config.example.php` to `config.local.php` and edit it. `config.local.php` is git-ignored.

## How areas work

There is one `areas` table with a row per barangay (province, city/municipality, barangay).
A city is every row that shares the same province and city name.
Accounts, vehicles, services, bookings and requests all point at a barangay.
A manager covers the whole city of their barangay, and a city can have only one active manager.
A barangay or city that is still in use cannot be removed.

## Admin pages (`/admin`)

| Page | What it does |
| --- | --- |
| `dashboard.php` | Totals, approvals waiting, cities without a manager, latest bookings |
| `accounts.php`, `account.php` | List, search, add managers and providers; edit details, reset password, activate or deactivate |
| `partners.php` | Managers and providers overview |
| `catalog.php` | Review every vehicle and service |
| `activity.php`, `booking.php`, `request.php` | Bookings, requests, payments and reviews, with detail pages |
| `reports.php`, `export.php` | Totals for a date range and city, plus CSV downloads |
| `areas.php`, `area.php` | Add and manage cities and barangays |
| `logs.php` | Record of admin actions (passwords are never logged) |
| `profile.php` | Change your own password |

## Project layout

```
index.php            public landing page
register.php         customer sign-up (always creates a customer account)
home.php             a signed-in customer's account page
login.php            sign-in for admins and customers
logout.php
create_admin.php     one-time command-line script
booking_and_services.sql   database
config.example.php
admin/               the admin side
includes/            db, auth, layout, areas, reports, public site helpers
assets/              style.css (admin), landing.css (public)
```
