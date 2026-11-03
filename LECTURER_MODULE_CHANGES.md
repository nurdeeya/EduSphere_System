# Lecturer Module - Siti Sarah (FR2, FR3, FR4, FR5)

## How to install (one time)
1. Copy every file in this zip into your `EduSphere_System` folder (keep the folders). Replace when asked.
2. phpMyAdmin -> database `edusphere_system` -> Import -> `sql/lecturer_module_update.sql`
   (adds the `learning_materials` and `notifications` tables; safe to run twice).
3. Log in as a lecturer -> every Lecturer Tools card now works.

## What changed

**Edited (my own files, other members' changes kept):**
| File | Change |
|---|---|
| `config/upload.php` | Extension whitelist BEFORE MIME check (a real PDF named `shell.php` used to be saved as `.php`), extension must match file content, empty/oversize files rejected. Same return format, so the Student Module still works unchanged. |
| `lecturer_create_assignment.php` | Length limits (title 100, description 2000), deadline must be in the future and within 1 year, duplicate-title check, no database error text shown to the user, assignment + audit log saved in one transaction, submission counts and Submissions button. Irham's `require_role()` kept. |
| `lecturer_edit_deadline.php` | Needs password **and** authenticator (MFA) code (Lab 4 threat #160), 5 failed confirmations = 15 min block, deadline must be in the future and different, update + audit log + student notifications in one transaction, recent change history. Irham's `require_role()`, id validation and error pages kept. |
| `lecturer_view_assignment.php` | Download goes through `download.php` (was a direct `uploads/` link that skipped login checks), submission counts, buttons. |
| `lecturer_dashboard.php` | Planned Feature cards now link to real pages, overview numbers, upcoming deadlines. Irham's `require_role()` kept. |

**New files:**
| File | Purpose |
|---|---|
| `lecturer_view_submissions.php` | FR5: every enrolled student with On time / Late / Not submitted, search + filter, download via Irham's `download.php` |
| `lecturer_materials.php` | FR4: upload / list / delete learning materials (PDF, DOCX, PPTX, ZIP) |
| `lecturer_students.php` | Student Overview: enrolled students and submission progress |
| `material_download.php` | Secure download for learning materials (same protections as `download.php`) |
| `student_materials.php` | Student side of materials + deadline notifications. **Irham:** only needs a link from the student dashboard: `<a href="student_materials.php">Learning Materials</a>` |
| `includes/lecturer.php` | Lecturer Module helpers (limits, audit log, MFA step-up, deadline rules) |
| `css/lecturer_module_style.css` | Styles for the lecturer pages (loaded after the existing dashboard CSS) |
| `sql/lecturer_module_update.sql` | New tables |
| `uploads/.htaccess` | Blocks direct URL access and script execution in `uploads/` (Apache/WAMP) |

**Not touched:** login, MFA, admin, student pages, `download.php`, `includes/access.php`, `config/database.php`, `edusphere_system.sql`.

## Things I noticed in other files (not changed - for the owner to decide)
- `verify_mfa.php` line 1 has a stray ```` ```php ```` before `<?php`. It is printed to the page and only works because WAMP buffers output.
- `config/database.php` catches `PDOExecption` (typo), so a DB connection failure is not caught and shows a raw error. Should be `PDOException`.
- `setup_mfa.php` does not set `$_SESSION['full_name']` after first-time MFA setup, so the dashboard says "Welcome, Lecturer" until the next login.
- Admin: `users.role` in `edusphere_system.sql` is `enum('student','lecturer')`, and `includes/authentication.php` / `verify_mfa.php` only accept student or lecturer, so an admin account cannot log in yet.
- `forgot_password.php` and `js/validation.js` are empty files.
