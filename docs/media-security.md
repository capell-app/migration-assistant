# Imported media security

The importer sniffs each checksum-verified binary and stores it with the canonical extension for that MIME, regardless of the client filename or declared MIME. An AVIF named `x.php` or `x.avif.php` therefore stores as `.avif`. SVG, HTML and scripts are refused as media types.

PNG, JPEG, GIF, WebP, MP3 and Ogg framing must end at EOF, so appended PHP or other trailing bytes are refused. ISO-BMFF boxes must have checked finite lengths and end at EOF. AVIF requires `ftyp` and `meta`; parsed `iloc` extents must stay inside their file or `idat` source. Parsing is bounded at 65,536 items and 262,144 extents.

Container payloads are opaque. Animation tracks, grid tiles, metadata, padding and `free` boxes need not be covered by `iloc`. Text inside a correctly framed box is not proof of executable content, and validation does not promise to decode codecs or identify every payload byte.

The security boundary is storage and serving. Imported paths cannot escape the disk. Local destinations cannot be application code, the public document root or compiled Blade views; existing symlinks are resolved before reuse or writing. Core generates media URLs through Spatie's disk URL generator. The default public disk stores files under `storage/app/public` and exposes them as static files via `/storage`.

Configure the web server or object store to serve the canonical media `Content-Type` and `X-Content-Type-Options: nosniff`, and never execute files in a media location. The importer does not control static-server response headers; package tests assert the sniffed MIME, canonical extension, physical path and disk URL instead.
