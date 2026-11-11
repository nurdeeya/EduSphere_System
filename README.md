# EduSpace_System
---

## Secure file upload (SR3) and malware scanning

Every upload (assignment files, student submissions, learning materials) goes through
`secure_file_upload()` in `config/upload.php`, which applies, in order:

1. Size limit (10 MB, empty files rejected)
2. Extension whitelist (PDF / DOCX / PPTX / ZIP depending on the form)
3. Magic-byte MIME check (content must match the extension)
4. **Malware scan with phpMussel** (`config/malware_scan.php`) - before anything is stored
5. Storage **outside the web root** (refused if the target is inside it)
6. Random 32-hex filename (original name is never used on disk)

Files are only served by `download.php` / `material_download.php` (login, role and ownership checked).
Blocked uploads are written to the audit log as `UPLOAD_BLOCKED`.

The scan **fails closed**: if phpMussel is missing, has no signatures, or cannot finish scanning a file,
the upload is rejected (`UPLOAD_SCAN_FAIL_CLOSED` in `config/upload.php`).

### One-time setup

1. Install phpMussel:
   ```
   composer require phpmussel/core
   ```
2. Install signature files. phpMussel ships no signatures through Composer: download the `.gz`
   files from the phpMussel/Signatures repository (or the ZIP from phpMussel/Examples) and copy them to
   `<storage root>/phpmussel/signatures/`. Keep them updated.
3. Storage root (created automatically): `eduspace_storage` **next to your web root**
   (XAMPP: `C:\xampp\eduspace_storage`). Override with the `EDUSPACE_STORAGE_DIR` environment variable.
   Uploaded files live in `<storage root>/uploads/`, phpMussel's data in `<storage root>/phpmussel/`.
4. Move any existing files from the old `uploads/` folder:
   ```
   php migrate_uploads_to_storage.php
   ```
   then delete `uploads/` and the migration script.
5. Verify (uses the harmless EICAR test string):
   ```
   php tests/check_upload_security.php
   ```
   Delete the `tests/` folder before a public deployment.

Note: phpMussel is signature based, so it catches known malware, not brand-new threats. For a production
deployment, ClamAV is the stronger engine; the scan is isolated in `malware_scan_file()` so it can be swapped.
