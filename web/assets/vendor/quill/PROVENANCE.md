# Quill 2.0.2

Downloaded 2026-09-17 from
`https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/{quill.js,quill.snow.css}`.

Vendored rather than loaded from a CDN at runtime, matching how this repo already
carries jQuery, select2, sweetalert2 and three.js in `assets/`. A CDN script tag would
make the composer depend on a third party being reachable, and would hand that third
party a request from every user who opens mail.

License: BSD-3-Clause (Copyright 2017-2024 Slab, Copyright 2014 Jason Chen). See the
license banner at the top of `quill.js`.

The step requires a stable editor library rather than a hand-built `contenteditable`.
Quill's output is still sanitized server-side before storage or send — see
`lib/sanitize.php`. Never trust the editor's HTML.
