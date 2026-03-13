# 📚 E-Library System

A web-based library management system built with **PHP**, **MySQL**, and vanilla **HTML/CSS/JS**. Students can browse and borrow books, while admins manage the entire catalog and user base.

---

## Features

### Student Side
- **Browse books** with search by title/author and category filter
- **Borrow & return** books with one click
- **Track** currently borrowed books

### Admin Side
- **Dashboard** with quick stats (total books, students, borrowed, returned)
- **Manage books** — add, edit, and delete from the catalog
- **Manage students** — view registered students, remove accounts
- **Activity log** — see all borrow/return history
- **Register new admins** from within the dashboard

---

## Tech Stack

| Layer     | Technology                          |
|-----------|-------------------------------------|
| Backend   | PHP 8+ (procedural, prepared stmts) |
| Database  | MySQL / MariaDB                     |
| Frontend  | HTML5, CSS3, JavaScript             |
| Icons     | Font Awesome 6.5                    |
| Fonts     | Google Fonts (Poppins, Playfair Display) |
| Server    | XAMPP (Apache)                      |

---

## Setup

1. **Install XAMPP** and start **Apache** and **MySQL** from the XAMPP Control Panel.

2. **Copy the project** to your XAMPP `htdocs` folder:
   ```
   C:\xampp\htdocs\library\
   ```

3. **Import the database** — open phpMyAdmin at `http://localhost/phpmyadmin`, then:
   - Click **Import** → choose `setup.sql` → click **Go**
   - This creates the `db_library` database with all tables and sample data.

4. **Open in browser**:
   ```
   http://localhost/library/home.html
   ```

---

## Configuration (important before pushing to GitHub)

### Secrets / env vars

- **Do not commit** real SMTP credentials.
- This repo includes:
   - `.env.example` (template)
   - `mail_config.example.php` (template)
- The real `mail_config.php` is **ignored by git** (see `.gitignore`).

Environment variables supported:

- App: `APP_BASE_URL`, `APP_ENV`
- DB: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`
- SMTP: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`

### Composer / vendor

If you're using PHPMailer via Composer, run Composer locally and **do not commit `vendor/`**.
It’s ignored in `.gitignore`.

---

## Hosting on Vercel (PHP serverless)

This repo includes a Vercel front-controller so you can deploy it on Vercel using a PHP runtime.

### Requirements

- An **external MySQL/MariaDB** database (PlanetScale / Railway / Aiven / etc.).
   - Vercel does not provide MySQL.
- Vercel project environment variables:
   - `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`
   - `APP_BASE_URL` (your Vercel URL)
   - SMTP vars if you want email verification

### How it works

- `vercel.json` routes all requests to `api/index.php`.
- `api/index.php` loads your existing pages (e.g. `student_dashboard.php`).
- PHP dependencies (PHPMailer) are installed via Composer using `composer.json`.

### Deploy steps (GitHub → Vercel)

1. Push this repo to GitHub (make sure `mail_config.php` is NOT committed).
2. In Vercel: **New Project** → import your GitHub repo.
3. Set the environment variables (DB + app + SMTP).
4. Deploy.

If you need a SQL bootstrap on your external DB, import `setup.sql` manually once.

---

## Hosting on InfinityFree (recommended for PHP)

InfinityFree is a classic PHP + MySQL host, so this project runs naturally there.

### 1) Upload files

Upload the contents of this project into your InfinityFree domain’s `htdocs/` folder.

Security hardening:
- Keep `.htaccess` (blocks access to `setup.sql`, `config.php`, etc.)

### 2) Database

1. Create a MySQL database + user in InfinityFree.
2. Open phpMyAdmin from the InfinityFree control panel.
3. Import `setup.sql` into your new database.

### 3) Configure DB + SMTP (email verification)

InfinityFree usually doesn’t support setting environment variables, so use `config.php`:

1. Copy `config.example.php` → `config.php`
2. Fill in:
   - `db.host`, `db.name`, `db.user`, `db.pass`
   - `app.base_url` (your InfinityFree domain)
   - `smtp.*` (SMTP provider credentials)

`config.php` is ignored by git (`.gitignore`) so you won’t leak credentials.

### 4) PHPMailer dependency

Email verification requires PHPMailer.

On InfinityFree you typically **can’t run Composer**, so you have two options:

**Option A (simplest): upload `vendor/`**
- Run Composer locally (on your PC): install PHPMailer.
- Upload the generated `vendor/` folder to InfinityFree.

**Option B: use InfinityFree’s built-in mail (not recommended)**
- Many free hosts block reliable email sending.

### SMTP provider note

Gmail SMTP sometimes works, sometimes gets blocked on free hosts. If Gmail fails, use a transactional SMTP provider (Brevo/SendGrid) and use their SMTP credentials.

---

## Default Accounts

| Role    | Username | Password   |
|---------|----------|------------|
| Admin   | admin    | admin123   |
| Student | *(register from Sign Up page)* | |

---

## File Structure

```
library/
├── home.html              Landing page
├── login.html             Student login form
├── index.php              Student login handler
├── registration.php       Student registration (form + handler)
├── admin.php              Admin login (form + handler)
├── adminreg.php           Admin registration handler
├── student_dashboard.php  Student panel (browse, borrow, return)
├── admin_dashboard.php    Admin panel (CRUD books, students, activity)
├── db.php                 Shared database connection helper
├── auth.php               Session / role guard helper
├── logout.php             Destroys session, redirects to home
├── setup.sql              Database schema + seed data
├── README.md              This file
├── Images/
│   ├── Icon.png           Brand icon
│   └── imgbg.png          Background image
└── gnc.jpg                Background asset
```

---

## Database Schema

| Table          | Purpose                  |
|----------------|--------------------------|
| `tbl_login`    | Student accounts         |
| `tbl_adminreg` | Admin accounts           |
| `tbl_books`    | Book catalog             |
| `tbl_borrow`   | Borrow / return records  |

---

## License

This project is for **educational purposes**.
