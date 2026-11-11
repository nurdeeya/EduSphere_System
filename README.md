# EduSpace_System
---
1. Install phpMussel:
   ```
   composer require phpmussel/core
   ```
2. Install signature files. phpMussel ships no signatures through Composer: download the `.gz`
   files from the phpMussel/Signatures repository (or the ZIP from phpMussel/Examples) and copy them to
   `<storage root>/phpmussel/signatures/`. Keep them updated.
3. Storage root (created automatically): `eduspace_storage` **next to your web root**
   (`C:\wamp64\eduspace_storage`). Override with the `EDUSPACE_STORAGE_DIR` environment variable.
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
   Delete the `tests/` folder