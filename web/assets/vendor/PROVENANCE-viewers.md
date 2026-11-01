# Attachment viewer libraries

Downloaded 2026-10-03 and vendored (never loaded from a CDN at runtime, same
reasoning as `quill/PROVENANCE.md`). They are loaded lazily by
`assets/docViewers.js`, only when someone previews a file of that kind.

| Folder | Library | Version | Source | License |
|---|---|---|---|---|
| `sheetjs/xlsx.full.min.js` | SheetJS Community Edition | 0.20.3 | `https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js` | Apache-2.0 |
| `mammoth/mammoth.browser.min.js` | mammoth.js | 1.8.0 | `https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js` | BSD-2-Clause |
| `jszip/jszip.min.js` | JSZip | 3.10.1 | `https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js` | MIT or GPLv3 (MIT used) |
| `dxf-parser/dxf-parser.js` | dxf-parser | 1.1.2 | `https://cdn.jsdelivr.net/npm/dxf-parser@1.1.2/dist/dxf-parser.js` | MIT |
| `qrcode/qrcode.js` | qrcode-generator (Kazuhiko Arase) — QR for two-factor setup on the Account page | 1.4.4 | `https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js` | MIT |
| `dompurify/purify.min.js` | DOMPurify | 3.1.7 | `https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.7/purify.min.js` | Apache-2.0 or MPL-2.0 |

Security: attachment bytes are untrusted. Spreadsheet and slide text is written
with `textContent`; Word HTML from mammoth goes through DOMPurify before it is
inserted, and the viewers never run scripts or load remote resources.
