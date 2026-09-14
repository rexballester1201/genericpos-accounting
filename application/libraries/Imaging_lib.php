<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Imaging_lib.php — upload → small, safe image
 *
 * GenericPOS · CodeIgniter 3.1.9 · PHP 8.1+ · GD optional
 *
 * ─── Responsibility ───────────────────────────────────────────────
 * Take one uploaded image and write a small, safe copy of it to disk.
 * Nothing else. No DB, no routing — the callers own those. One entry point
 * per kind of picture:
 *   product_from_upload()  product photos (admin/Products) — fitted inside a
 *                          max edge, no crop, plus a smaller copy for grids
 *                          and the POS
 *   fit_from_upload()      the store's logo and banners (admin/Settings) —
 *                          fitted inside a max edge, aspect ratio kept
 *   square_from_upload()   profile pictures (Profile) — centre-cropped square
 *
 * ─── Why re-encode instead of storing the upload ──────────────────
 * A phone photo is 3–12 MB and 4000px wide; it is shown as a product card, a
 * till thumbnail or an avatar. Re-encoding through GD also:
 *   • strips EXIF (GPS coordinates live in there — storing a raw phone photo
 *     would publish the exact spot it was taken, often somebody's home),
 *   • strips any payload hidden in the file (a "JPEG" that is really a PHP
 *     script does not survive imagecreatefromjpeg + imagejpeg),
 *   • normalises every format to one predictable output (JPEG).
 * The upload as received is NEVER written to disk.
 *
 * ─── Without GD ───────────────────────────────────────────────────
 * GD is optional. Without it the upload passes the same checks
 * (_check_upload) and is stored at its own size and in its own format — JPG,
 * PNG or WEBP; GIF is refused — with its metadata stripped by _strip_jpeg() /
 * _strip_png() / _strip_webp(): EXIF and XMP (GPS included), IPTC, comments,
 * text and timestamp chunks. The result carries 'raw' => TRUE. What is lost:
 * the resize (product_from_upload() returns the same file as 'thumb') and the
 * re-encode, which is what destroys a payload hidden inside an image.
 * Enabling GD (php.ini: extension=gd) restores both.
 *
 * ─── EXIF orientation ─────────────────────────────────────────────
 * Phone cameras store the photo in sensor orientation and set an EXIF flag
 * saying "rotate me". GD ignores that flag, so a portrait photo comes out
 * sideways. _apply_orientation() reads it BEFORE the crop — cropping a sideways
 * image square would also crop the wrong part of it. Without GD nothing is
 * rotated here: _strip_jpeg() writes the orientation tag back on its own, so
 * the browser still turns the photo upright.
 *
 * ─── Usage ────────────────────────────────────────────────────────
 *   $this->load->library('Imaging_lib', NULL, 'imaging');
 *
 *   $res = $this->imaging->product_from_upload($_FILES['image'], $abs_dir, 1600, 600);
 *   // → ['ok'=>true, 'file'=>'ab12cd.jpg', 'thumb'=>'ef34gh.jpg', 'width'=>1600,
 *   //    'height'=>1200, 'bytes'=>184220, 'mime'=>'image/jpeg']
 *
 *   $res = $this->imaging->fit_from_upload($_FILES['file'], $abs_dir, $max_edge);
 *   $res = $this->imaging->square_from_upload($_FILES['avatar'], $abs_dir, 320);
 *   // → ['ok'=>true, 'file'=>'ab12cd.jpg', 'width'=>320, 'height'=>320,
 *   //    'bytes'=>18422, 'mime'=>'image/jpeg']
 *
 *   // Any of them, on failure:
 *   // → ['ok'=>false, 'error'=>'too_large', 'message'=>'…']
 */
class Imaging_lib
{
    /** Output edge in px. A profile picture shows at 40–72px; 320 covers retina screens. */
    const DEFAULT_SIZE = 320;

    /** JPEG quality. 82 is the usual visually-lossless/size sweet spot. */
    const JPEG_QUALITY = 82;

    /**
     * Default longest side for fit_from_upload(), which keeps the whole picture
     * (a logo, a banner; Settings passes 800 and 2000). 1600 keeps text in an
     * image legible when zoomed; 320 (DEFAULT_SIZE) does not, and neither does 800 for small
     * print. Bounded by MAX_BYTES on the way in either way.
     */
    const DOC_MAX_EDGE = 1600;

    /** Higher than JPEG_QUALITY: documents are TEXT, and artefacts hit type hardest. */
    const DOC_JPEG_QUALITY = 88;

    /** Hard ceiling on the incoming file. Anything bigger is a camera original. */
    const MAX_BYTES = 8388608;   // 8 MB

    /**
     * Guard against decompression bombs: a 40000×40000 PNG is only ~1 MB.
     *
     * NOTE: 24 MP, NOT 50 MP, AND THE OLD VALUE CONTRADICTED ITS OWN COMMENT.
     * The check below says GD needs ~4 bytes per pixel — at 50 MP that is
     * ~196 MB of allocation, which no shared-host memory_limit permits. A
     * 160 KB TRUECOLOUR 7000x7000 PNG passed the cap and died inside
     * imagecreatefrompng() with an E_ERROR that cannot be caught, so
     * _decode_upload() never returned and the caller's 422 envelope was never
     * emitted: a blank 500 from an authenticated endpoint, repeatable at
     * rate_avatar_upload per hour.
     *
     * (Reproducing it needs a TRUECOLOUR bomb. The same image saved as an
     * indexed/palette PNG decodes at one byte per pixel and survives a 128M
     * limit, which is how this gets mistaken for a false report.)
     *
     * 24 MP is still ~5 times a 4K photo and 75 times what a 320 px square
     * needs. The real defence is the headroom projection in _decode_upload();
     * this is the cheap first test.
     */
    const MAX_PIXELS = 24000000; // 24 MP ≈ 96 MB truecolour

    /**
     * Longest permitted edge on the SOURCE image.
     *
     * A pixel count alone does not bound a decode: 300000 x 80 is 24 MP and
     * passes, while GD allocates a row pointer per line and the aspect ratio
     * alone breaks the resize maths downstream. Nothing a camera or phone
     * produces comes close to this.
     */
    const MAX_EDGE_PX = 8000;

    /** Headroom left for everything else in the request, on top of the decode. */
    const DECODE_HEADROOM_BYTES = 8388608;   // 8 MB

    private $accepted = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_GIF  => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Process one uploaded file into a square JPEG.
     *
     * @param  array  $file     One entry from $_FILES
     * @param  string $abs_dir  Absolute destination dir (created if missing)
     * @param  int    $size     Output edge in px
     * @return array
     */
    public function square_from_upload($file, $abs_dir, $size = self::DEFAULT_SIZE)
    {
        if ( ! extension_loaded('gd')) return $this->_store_raw($file, $abs_dir);

        $d = $this->_decode_upload($file);
        if (empty($d['ok'])) {
            return $d;                       // already a _fail() payload
        }

        $src   = $d['src'];
        $src_w = $d['w'];
        $src_h = $d['h'];

        // ── Centre-crop to a square, then scale ──────────────────────
        $edge = min($src_w, $src_h);
        $sx   = (int) floor(($src_w - $edge) / 2);
        $sy   = (int) floor(($src_h - $edge) / 2);

        // Never upscale: a 100px source becomes a 100px square, not a blurry 320.
        $out_edge = (int) min($size, $edge);
        if ($out_edge < 1) {
            imagedestroy($src);
            return $this->_fail('decode_failed', 'That image could not be read.');
        }

        $dst = $this->_canvas($out_edge, $out_edge);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out_edge, $out_edge, $edge, $edge);
        imagedestroy($src);

        return $this->_write_jpeg($dst, $abs_dir, $out_edge, $out_edge, self::JPEG_QUALITY);
    }

    /**
     * Process one uploaded file into a JPEG that FITS INSIDE $max_edge, keeping
     * its aspect ratio. No crop.
     *
     * NOTE: THIS EXISTS BECAUSE `square_from_upload()` DESTROYS WIDE AND TALL
     * IMAGES, AND THE DAMAGE LOOKS LIKE A SUCCESSFUL UPLOAD. That method
     * centre-crops to a square at 320px, which is right for a profile picture and
     * ruinous for a logo or a banner: a wide banner centre-cropped to 320×320
     * keeps a square from its middle, throws away both ends, and scales what is
     * left until any text in it is unreadable. It returns `ok: true`.
     *
     * So: never route a logo or a banner through square_from_upload(), and never
     * "unify" these two methods. They have opposite jobs — one makes an icon, one
     * keeps the whole picture.
     *
     * NOTE: ALL THE VALIDATION AND THE EXIF-ORIENTATION FIX ARE SHARED via
     * `_decode_upload()`, and that is deliberate: those checks are the security
     * boundary (is_uploaded_file, header sniffing rather than the forgeable
     * $file['type'], the 8 MB cap, the MAX_PIXELS decompression-bomb guard, GD
     * re-encode to strip EXIF/GPS and any embedded payload). Duplicating them so
     * a second method could have its own copy is how one gets fixed and the other
     * does not.
     *
     * @param  array  $file      One entry from $_FILES
     * @param  string $abs_dir   Absolute destination dir (created if missing)
     * @param  int    $max_edge  Longest output side in px
     * @return array
     */
    public function fit_from_upload($file, $abs_dir, $max_edge = self::DOC_MAX_EDGE)
    {
        if ( ! extension_loaded('gd')) return $this->_store_raw($file, $abs_dir);

        $d = $this->_decode_upload($file);
        if (empty($d['ok'])) {
            return $d;
        }

        $src   = $d['src'];
        $src_w = $d['w'];
        $src_h = $d['h'];

        // min(1.0, …) is what stops an upscale — a 600px photo of a letter stays
        // 600px rather than being interpolated up to 1600 and looking worse.
        $scale = min(1.0, $max_edge / max($src_w, $src_h));
        $out_w = max(1, (int) round($src_w * $scale));
        $out_h = max(1, (int) round($src_h * $scale));

        $dst = $this->_canvas($out_w, $out_h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $out_w, $out_h, $src_w, $src_h);
        imagedestroy($src);

        // Higher quality than an icon: this is TEXT, and JPEG artefacts land
        // hardest on the sharp edges of small type. A document an admin cannot
        // read is the same as no document.
        return $this->_write_jpeg($dst, $abs_dir, $out_w, $out_h, self::DOC_JPEG_QUALITY);
    }

    /**
     * A PRODUCT PHOTO: the main image fitted inside $max_edge (no crop — a
     * product shot must show the whole product) plus a smaller copy for grids
     * and the POS, fitted inside $thumb_edge.
     *
     * @return array ['ok'=>TRUE,'file','thumb','width','height','bytes','mime'] or a _fail()
     *
     * NOTE: the main image is written and freed BEFORE the thumbnail canvas is
     * allocated, so the peak is the decoded source plus one output — not two.
     */
    public function product_from_upload($file, $abs_dir, $max_edge = 1600, $thumb_edge = 600)
    {
        if ( ! extension_loaded('gd')) {
            $r = $this->_store_raw($file, $abs_dir);
            if ( ! empty($r['ok'])) $r['thumb'] = $r['file'];    // no resampling without GD
            return $r;
        }

        $d = $this->_decode_upload($file);
        if (empty($d['ok'])) return $d;

        $src = $d['src'];
        $w   = $d['w'];
        $h   = $d['h'];

        $s  = min(1.0, $max_edge / max($w, $h));
        $mw = max(1, (int) round($w * $s));
        $mh = max(1, (int) round($h * $s));
        $main = $this->_canvas($mw, $mh);
        imagecopyresampled($main, $src, 0, 0, 0, 0, $mw, $mh, $w, $h);
        $a = $this->_write_jpeg($main, $abs_dir, $mw, $mh, 85);
        if (empty($a['ok'])) { imagedestroy($src); return $a; }

        $t  = min(1.0, $thumb_edge / max($w, $h));
        $tw = max(1, (int) round($w * $t));
        $th = max(1, (int) round($h * $t));
        $thumb = $this->_canvas($tw, $th);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);
        $b = $this->_write_jpeg($thumb, $abs_dir, $tw, $th, 80);
        if (empty($b['ok'])) {
            @unlink(rtrim($abs_dir, '/\\') . DIRECTORY_SEPARATOR . $a['file']);
            return $b;
        }

        return [
            'ok' => TRUE, 'file' => $a['file'], 'thumb' => $b['file'],
            'width' => $mw, 'height' => $mh, 'bytes' => $a['bytes'], 'mime' => 'image/jpeg',
        ];
    }

    /**
     * NO GD ON THIS SERVER: keep the upload itself — validated exactly like
     * every other image (_check_upload), metadata stripped, under a random name
     * with an extension chosen from its real type.
     *
     * NOTE: WHAT IS LOST WITHOUT GD, so nobody mistakes this for the full path:
     * no resize (originals are served, pages are heavier) and no re-encode —
     * and the re-encode is what destroys a payload hidden inside an image.
     * What remains: header-sniffed type, size and pixel caps, GPS/camera data
     * stripped below, a server-chosen name, and uploads/.htaccess refusing to
     * execute anything. Enabling GD (php.ini: extension=gd) restores it all.
     */
    private function _store_raw($file, $abs_dir)
    {
        $c = $this->_check_upload($file);
        if (empty($c['ok'])) return $c;

        $exts = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if ( ! isset($exts[$c['type']])) return $this->_fail('bad_format', 'Use a JPG, PNG or WEBP image.');

        $bytes = @file_get_contents($file['tmp_name']);
        if ($bytes === FALSE || $bytes === '') return $this->_fail('decode_failed', 'That image could not be read.');

        $clean = $this->_strip_metadata($bytes, $c['type']);
        if ($clean === NULL) return $this->_fail('decode_failed', 'That image could not be read.');

        if ( ! is_dir($abs_dir) && ! @mkdir($abs_dir, 0755, TRUE) && ! is_dir($abs_dir)) {
            log_message('error', '[Imaging_lib] Could not create directory: ' . $abs_dir);
            return $this->_fail('server_no_dir', 'Could not save the image. Please try again.');
        }

        $name = bin2hex(random_bytes(8)) . '.' . $exts[$c['type']];
        $abs  = rtrim($abs_dir, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (@file_put_contents($abs, $clean, LOCK_EX) !== strlen($clean)) {
            @unlink($abs);
            log_message('error', '[Imaging_lib] raw write failed: ' . $abs);
            return $this->_fail('write_failed', 'Could not save the image. Please try again.');
        }
        @chmod($abs, 0644);

        /* The stripped file must still be the same kind of image. */
        $info = @getimagesize($abs);
        if ($info === FALSE || (int) $info[2] !== (int) $c['type']) {
            @unlink($abs);
            return $this->_fail('decode_failed', 'That image could not be read.');
        }

        return [
            'ok' => TRUE, 'file' => $name, 'width' => (int) $c['w'], 'height' => (int) $c['h'],
            'bytes' => strlen($clean), 'mime' => image_type_to_mime_type($c['type']), 'raw' => TRUE,
        ];
    }

    /** @return string|null  the image without metadata, or NULL if its structure is malformed */
    private function _strip_metadata($bytes, $type)
    {
        switch ((int) $type) {
            case IMAGETYPE_JPEG: return $this->_strip_jpeg($bytes);
            case IMAGETYPE_PNG:  return $this->_strip_png($bytes);
            case IMAGETYPE_WEBP: return $this->_strip_webp($bytes);
        }
        return NULL;
    }

    /**
     * JPEG: drop APP1 (EXIF/XMP — GPS lives here), APP13 (IPTC) and comments;
     * keep everything the decoder needs.
     *
     * NOTE: THE ORIENTATION FLAG IS PUT BACK. Browsers rotate a photo by its
     * EXIF orientation; dropping EXIF wholesale would turn every portrait
     * phone photo on its side. A minimal APP1 carrying ONLY that tag is written
     * in its place.
     */
    private function _strip_jpeg($b)
    {
        $n = strlen($b);
        if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") return NULL;

        $i = 2; $app0 = ''; $segs = ''; $orientation = NULL; $sos = FALSE;
        while ($i < $n) {
            if ($b[$i] !== "\xFF") return NULL;
            while ($i < $n && $b[$i] === "\xFF") $i++;                  // fill bytes
            if ($i >= $n) return NULL;
            $marker = ord($b[$i]);
            $i++;

            if ($marker === 0xD9) break;                                 // EOI before any scan
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { $segs .= "\xFF" . chr($marker); continue; }
            if ($i + 2 > $n) return NULL;

            $len = unpack('n', substr($b, $i, 2))[1];
            if ($len < 2 || $i + $len > $n) return NULL;
            $seg  = "\xFF" . chr($marker) . substr($b, $i, $len);
            $data = substr($b, $i + 2, $len - 2);
            $i += $len;

            if ($marker === 0xDA) { $segs .= $seg . substr($b, $i); $sos = TRUE; break; }   // image data follows
            if ($marker === 0xE1) {
                if ($orientation === NULL && strncmp($data, "Exif\0\0", 6) === 0) {
                    $orientation = $this->_tiff_orientation(substr($data, 6));
                }
                continue;
            }
            if ($marker === 0xED || $marker === 0xFE) continue;
            if ($marker === 0xE0 && $app0 === '' && $segs === '') { $app0 = $seg; continue; }
            $segs .= $seg;
        }
        if ( ! $sos) return NULL;

        $exif = '';
        if ($orientation !== NULL && $orientation !== 1) {
            $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1)
                  . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . pack('n', 0)
                  . pack('N', 0);
            $payload = "Exif\0\0" . $tiff;
            $exif = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        }
        return "\xFF\xD8" . $app0 . $exif . $segs;
    }

    /** The Orientation tag (1–8) from a TIFF/EXIF block, or NULL. */
    private function _tiff_orientation($t)
    {
        $len = strlen($t);
        if ($len < 8) return NULL;
        $bo = substr($t, 0, 2);
        if ($bo === 'II')     { $u16 = 'v'; $u32 = 'V'; }
        elseif ($bo === 'MM') { $u16 = 'n'; $u32 = 'N'; }
        else return NULL;
        if (unpack($u16, substr($t, 2, 2))[1] !== 42) return NULL;

        $ifd = unpack($u32, substr($t, 4, 4))[1];
        if ($ifd < 8 || $ifd + 2 > $len) return NULL;
        $count = unpack($u16, substr($t, $ifd, 2))[1];
        for ($k = 0; $k < $count && $k < 256; $k++) {
            $e = $ifd + 2 + $k * 12;
            if ($e + 12 > $len) return NULL;
            if (unpack($u16, substr($t, $e, 2))[1] === 0x0112) {
                $v = unpack($u16, substr($t, $e + 8, 2))[1];
                return ($v >= 1 && $v <= 8) ? $v : NULL;
            }
        }
        return NULL;
    }

    /** PNG: drop text, EXIF and timestamp chunks. */
    private function _strip_png($b)
    {
        if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return NULL;
        $drop = ['tEXt' => 1, 'zTXt' => 1, 'iTXt' => 1, 'eXIf' => 1, 'tIME' => 1];
        $out = substr($b, 0, 8);
        $n = strlen($b); $i = 8; $end = FALSE;
        while ($i + 12 <= $n) {
            $len  = unpack('N', substr($b, $i, 4))[1];
            $type = substr($b, $i + 4, 4);
            if ($len < 0 || $len > $n - $i - 12) return NULL;
            if ( ! isset($drop[$type])) $out .= substr($b, $i, $len + 12);
            $i += $len + 12;
            if ($type === 'IEND') { $end = TRUE; break; }
        }
        return $end ? $out : NULL;
    }

    /** WEBP: drop the EXIF and XMP chunks and clear their flags in VP8X. */
    private function _strip_webp($b)
    {
        $n = strlen($b);
        if ($n < 20 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') return NULL;
        $body = ''; $i = 12;
        while ($i + 8 <= $n) {
            $fourcc = substr($b, $i, 4);
            $size   = unpack('V', substr($b, $i + 4, 4))[1];
            if ($size < 0 || $i + 8 + $size > $n) return NULL;
            $padded = $size + ($size & 1);
            $chunk  = substr($b, $i, 8 + min($padded, $n - $i - 8));
            $i += 8 + $padded;
            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') continue;
            if ($fourcc === 'VP8X' && $size >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            $body .= $chunk;
        }
        if ($body === '') return NULL;
        return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
    }

    /**
     * Validate an upload, decode it, and correct its EXIF orientation.
     *
     * Extracted 2026-07-26 so `square_from_upload()` and `fit_from_upload()` share
     * ONE copy of the security checks. Returns either
     * `['ok'=>TRUE,'src'=>resource,'w'=>int,'h'=>int,'type'=>int]`
     * or a `_fail()` payload the caller can return verbatim.
     *
     * @param  array $file
     * @return array
     */
    private function _decode_upload($file)
    {
        if ( ! extension_loaded('gd')) {
            log_message('error', '[Imaging_lib] GD extension is not loaded — cannot process uploads.');
            return $this->_fail('server_no_gd', 'Image processing is unavailable on this server.');
        }

        $c = $this->_check_upload($file);
        if (empty($c['ok'])) return $c;

        return $this->_decode_checked($file, $c['w'], $c['h'], $c['type']);
    }

    /**
     * Steps 1–2 of EVERY upload, with or without GD: is this really an uploaded
     * file, within the size caps, and an image by its header (never by the
     * client's claimed type)? Returns ['ok'=>TRUE,'w','h','type'] or a _fail().
     *
     * NOTE: the one copy of these checks. _decode_upload() (GD) and _store_raw()
     * (no GD) both call it, so a fix here protects both paths.
     */
    private function _check_upload($file)
    {
        // ── 1. Upload-level checks ───────────────────────────────────
        if ( ! is_array($file) || ! isset($file['error'])) {
            return $this->_fail('no_file', 'No file was uploaded.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->_fail(
                'upload_error',
                $this->_upload_error_message((int) $file['error'])
            );
        }

        // is_uploaded_file() is the real guard: it proves the path came from
        // this POST and not from an attacker naming /etc/passwd.
        if (empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return $this->_fail('no_file', 'No file was uploaded.');
        }

        $bytes = (int) $file['size'];
        if ($bytes <= 0) {
            return $this->_fail('empty_file', 'That file is empty.');
        }
        if ($bytes > self::MAX_BYTES) {
            return $this->_fail('too_large', 'That image is too large. Please use one under 8 MB.');
        }

        // ── 2. Is it REALLY an image? ────────────────────────────────
        // getimagesize() reads the header. Never trust $file['type'] — it is
        // just a client-supplied string and is trivially forged.
        $info = @getimagesize($file['tmp_name']);
        if ($info === FALSE) {
            return $this->_fail('not_image', 'That file is not an image.');
        }

        list($src_w, $src_h, $type) = $info;

        if ( ! isset($this->accepted[$type])) {
            return $this->_fail('bad_format', 'Use a JPG, PNG, GIF or WEBP image.');
        }
        if ($src_w < 1 || $src_h < 1) {
            return $this->_fail('not_image', 'That file is not an image.');
        }
        if (($src_w * $src_h) > self::MAX_PIXELS) {
            // Guards RAM, not disk: GD needs ~4 bytes per pixel to decode.
            return $this->_fail('too_large', 'That image has too many pixels. Please use a smaller one.');
        }
        if ($src_w > self::MAX_EDGE_PX || $src_h > self::MAX_EDGE_PX) {
            // A pixel-count test alone cannot catch an extreme aspect ratio.
            return $this->_fail('too_large', 'That image is too big. Please use a smaller one.');
        }

        return ['ok' => TRUE, 'w' => (int) $src_w, 'h' => (int) $src_h, 'type' => (int) $type];
    }

    /** Steps 3–4, GD only: memory projection, decode, EXIF orientation. */
    private function _decode_checked($file, $src_w, $src_h, $type)
    {
        /* ── THE CHECK THAT ACTUALLY STOPS THE FATAL ──────────────────
           Every test above compares against a constant. This one compares
           against the memory THIS process actually has left, which is the only
           number that decides whether the next line survives.

           It has to exist because a GD decode failure is not a failure this
           code can catch: exhausting memory_limit inside imagecreatefrompng()
           is an E_ERROR, the request dies there, and the caller's 422 envelope
           is never written — the user sees a blank 500 with nothing in it,
           and so does an attacker repeating it.

           NOTE: THE DOUBLING IS PER-ORIENTATION, NOT PER-JPEG, AND THE COMMENT
           THAT USED TO SIT HERE DESCRIBED ONE WHILE THE CODE DID THE OTHER. It
           said the second buffer was needed for "EXIF orientations 3, 5, 6, 7
           and 8 — which is every portrait phone photo", and then doubled for
           every JPEG, portrait or not. _apply_orientation() only calls
           imagerotate() — the call that allocates a SECOND full-size buffer —
           for those five orientations; 1 is a no-op and 2 and 4 are imageflip(),
           which works in place. So an ordinary LANDSCAPE 4608x3456 camera frame,
           16 MP and well inside MAX_PIXELS, projected 122 MB against a 128M
           limit and was refused with "too big for the server to process" when
           its real peak is ~61 MB. (Both figures in the units the log line
           below prints, which is MiB.) _will_rotate() asks the same table
           _apply_orientation() applies, so the two cannot drift apart.

           Where the orientation cannot be determined — no exif extension, or a
           JPEG carrying no EXIF at all — _will_rotate() answers TRUE and the
           estimate stays doubled. That is deliberately the pessimistic side:
           over-projecting refuses an image that would have fitted and the user
           reads a clean 422, while under-projecting is the uncatchable E_ERROR
           this whole block exists to prevent.

           The result is that a 48 MP phone photo and a hand-built decompression
           bomb both become the same clean 422, on a 128M host and a 512M host
           alike, instead of one being a fatal and the other an inconsistency
           between two servers. */
        $limit = $this->_memory_limit_bytes();

        if ($limit > 0) {
            $need = (float) $src_w * (float) $src_h * 4.0;

            // Only JPEG reaches _apply_orientation() at all — see step 4 below.
            if ($type === IMAGETYPE_JPEG && $this->_will_rotate($file['tmp_name'])) {
                $need *= 2.0;
            }

            if ($need + memory_get_usage(TRUE) + self::DECODE_HEADROOM_BYTES > $limit) {
                log_message('error', '[Imaging] refused a ' . $src_w . 'x' . $src_h
                    . ' image: decoding it needs about ' . (int) round($need / 1048576)
                    . ' MB and the memory limit is ' . (int) round($limit / 1048576)
                    . ' MB. Raise memory_limit or ask for a smaller image.');
                return $this->_fail('too_large',
                    'That image is too big for the server to process. Please use a smaller one.');
            }
        }

        // ── 3. Decode ────────────────────────────────────────────────
        $src = $this->_load($file['tmp_name'], $type);
        if ( ! $src) {
            return $this->_fail('decode_failed', 'That image could not be read.');
        }

        // ── 4. Orientation BEFORE any crop or scale ──────────────────
        if ($type === IMAGETYPE_JPEG) {
            $src = $this->_apply_orientation($src, $file['tmp_name']);
            // Dimensions may have swapped on a 90/270° rotate.
            $src_w = imagesx($src);
            $src_h = imagesy($src);
        }

        return ['ok' => TRUE, 'src' => $src, 'w' => $src_w, 'h' => $src_h, 'type' => $type];
    }

    /**
     * A truecolour canvas pre-flattened onto WHITE.
     *
     * NOTE: THE WHITE FILL IS NOT COSMETIC. Output is JPEG, which has no alpha
     * channel, so a transparent PNG composited onto an unfilled truecolour canvas
     * comes out with a BLACK background.
     */
    private function _canvas($w, $h)
    {
        $dst   = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        return $dst;
    }

    /**
     * Write a GD image to $abs_dir as a randomly-named JPEG. Destroys $dst.
     *
     * Extracted 2026-07-26 — shared by both public entry points.
     */
    private function _write_jpeg($dst, $abs_dir, $w, $h, $quality = self::JPEG_QUALITY)
    {
        if ( ! is_dir($abs_dir) && ! @mkdir($abs_dir, 0755, TRUE) && ! is_dir($abs_dir)) {
            imagedestroy($dst);
            log_message('error', '[Imaging_lib] Could not create directory: ' . $abs_dir);
            return $this->_fail('server_no_dir', 'Could not save the image. Please try again.');
        }
        if ( ! is_writable($abs_dir)) {
            imagedestroy($dst);
            log_message('error', '[Imaging_lib] Directory not writable: ' . $abs_dir);
            return $this->_fail('server_no_dir', 'Could not save the image. Please try again.');
        }

        // Random name: the user's filename never touches the filesystem, so
        // there is nothing to traverse with and nothing to collide.
        $name = bin2hex(random_bytes(8)) . '.jpg';
        $abs  = rtrim($abs_dir, '/\\') . DIRECTORY_SEPARATOR . $name;

        $ok = imagejpeg($dst, $abs, (int) $quality);
        imagedestroy($dst);

        if ( ! $ok || ! file_exists($abs)) {
            log_message('error', '[Imaging_lib] imagejpeg() failed writing ' . $abs);
            return $this->_fail('write_failed', 'Could not save the image. Please try again.');
        }

        @chmod($abs, 0644);

        return [
            'ok'     => TRUE,
            'file'   => $name,
            'width'  => (int) $w,
            'height' => (int) $h,
            'bytes'  => (int) filesize($abs),
            'mime'   => 'image/jpeg',
        ];
    }


    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * memory_limit in bytes, or 0 when there is no limit to compare against.
     *
     * NOTE: ini_get('memory_limit') returns a SHORTHAND string — "128M",
     * "512M", sometimes "-1". Casting it to int gives 128, and a projection
     * measured in bytes compared against 128 refuses every upload including a
     * thumbnail. The suffix has to be expanded.
     *
     * NOTE: -1 and 0 both mean "no limit" and return 0 here, which the caller
     * reads as "skip the check". Guessing a ceiling in that case would refuse
     * uploads on the one configuration that could actually handle them.
     */
    private function _memory_limit_bytes()
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1' || $raw === '0') return 0;

        if ( ! preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)$/i', $raw, $m)) return 0;

        $n = (float) $m[1];

        switch (strtolower($m[2])) {
            case 'g': $n *= 1024;   // fall through
            case 'm': $n *= 1024;   // fall through
            case 'k': $n *= 1024;
        }

        return $n;
    }

    private function _load($path, $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
            case IMAGETYPE_GIF:  return @imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                // WEBP support is a GD build flag, not a given on shared hosts.
                return function_exists('imagecreatefromwebp')
                    ? @imagecreatefromwebp($path)
                    : FALSE;
        }
        return FALSE;
    }

    /**
     * Rotate/flip per the EXIF orientation flag.
     *
     * Without this every portrait phone photo lands sideways. exif is a separate
     * PHP extension and is genuinely absent on some shared hosts — treat that as
     * "assume upright" rather than failing the upload over a rotation.
     */
    private function _apply_orientation($img, $path)
    {
        $ops = $this->_orientation_ops($this->_exif_orientation($path));

        // Rotate BEFORE the flip. Orientations 5 and 7 are a rotate AND a
        // mirror, and mirroring first mirrors the wrong axis.
        if ($ops['rotate'] !== NULL) {
            $rotated = imagerotate($img, $ops['rotate'], 0);
            // FALSE would otherwise propagate into imagesx() as a fatal. An
            // unrotated image is the same "assume upright" fallback this method
            // already takes when exif is unavailable.
            if ($rotated !== FALSE) $img = $rotated;
        }

        if ($ops['flip'] !== NULL) {
            imageflip($img, $ops['flip']);
        }

        return $img;
    }

    /**
     * The GD operations one EXIF orientation calls for:
     * `['rotate' => degrees|NULL, 'flip' => IMG_FLIP_*|NULL]`.
     *
     * NOTE: THIS TABLE IS ALSO WHERE _decode_upload() GETS ITS ANSWER TO "WILL
     * THIS ROTATE?", AND THAT IS THE WHOLE REASON IT IS A TABLE RATHER THAN A
     * SWITCH OF STATEMENTS. The memory projection must double its estimate
     * exactly when imagerotate() runs, because that is the call that allocates a
     * second full-size buffer. A separate list of "the rotating orientations"
     * kept next to the projection is how the two came apart: the projection
     * doubled for every JPEG while this switch rotated only five of the eight
     * orientations, and ordinary landscape photos were refused for a buffer that
     * was never going to be allocated.
     *
     * A NULL orientation — unreadable, absent, or out of range — maps to "do
     * nothing", which is what this code has always done with one.
     */
    private function _orientation_ops($orientation)
    {
        switch ((int) $orientation) {
            case 2: return ['rotate' => NULL, 'flip' => IMG_FLIP_HORIZONTAL];
            case 3: return ['rotate' => 180,  'flip' => NULL];
            case 4: return ['rotate' => NULL, 'flip' => IMG_FLIP_VERTICAL];
            case 5: return ['rotate' => -90,  'flip' => IMG_FLIP_HORIZONTAL];
            // imagerotate() is counter-clockwise; EXIF 6 means rotate CW.
            case 6: return ['rotate' => -90,  'flip' => NULL];
            case 7: return ['rotate' => 90,   'flip' => IMG_FLIP_HORIZONTAL];
            case 8: return ['rotate' => 90,   'flip' => NULL];
        }

        return ['rotate' => NULL, 'flip' => NULL];   // 1, absent, or nonsense
    }

    /**
     * Will _apply_orientation() allocate a second full-size buffer for this
     * file? Asked by the memory projection in _decode_upload(), which runs
     * BEFORE the decode — so the answer has to come from the file on disk, not
     * from a GD image that does not exist yet.
     *
     * NOTE: "CANNOT TELL" ANSWERS TRUE, DELIBERATELY. exif is an optional
     * extension that is genuinely absent on some shared hosts, and
     * exif_read_data() warns — on some builds throws — for a JPEG that carries
     * no EXIF at all. Being wrong in the TRUE direction refuses an upload that
     * would have fitted and the user reads a clean 422 asking for a smaller
     * image. Being wrong the other way is an uncatchable E_ERROR inside GD, a
     * blank 500, and no envelope at all.
     */
    private function _will_rotate($path)
    {
        $orientation = $this->_exif_orientation($path);

        if ($orientation === NULL) return TRUE;

        return $this->_orientation_ops($orientation)['rotate'] !== NULL;
    }

    /**
     * The EXIF orientation of a file as 1–8, or NULL when it cannot be
     * determined.
     *
     * NOTE: NULL MEANS UNKNOWN, NOT UPRIGHT. The two callers read it
     * differently on purpose — _orientation_ops() as "do nothing", which is what
     * an unrotatable image needs, and _will_rotate() as the worst case, which is
     * what a memory projection needs. Collapsing NULL into 1 would make the
     * projection optimistic on exactly the hosts where it cannot check.
     */
    private function _exif_orientation($path)
    {
        if ( ! function_exists('exif_read_data')) return NULL;

        try {
            $exif = @exif_read_data($path);
        } catch (\Throwable $e) {
            return NULL;
        }

        if ( ! is_array($exif) || ! isset($exif['Orientation'])) return NULL;

        $orientation = (int) $exif['Orientation'];

        return ($orientation >= 1 && $orientation <= 8) ? $orientation : NULL;
    }

    private function _upload_error_message($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                // php.ini upload_max_filesize / post_max_size — the file never
                // reached us, so our own MAX_BYTES check never got a look in.
                return 'That image is too large. Please use a smaller one.';
            case UPLOAD_ERR_PARTIAL:
                return 'The upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                log_message('error', '[Imaging_lib] Server temp dir problem, UPLOAD_ERR code ' . $code);
                return 'Could not save the image. Please try again.';
            default:
                return 'The upload failed. Please try again.';
        }
    }

    private function _fail($error, $message)
    {
        return ['ok' => FALSE, 'error' => $error, 'message' => $message];
    }
}

/* End of file Imaging_lib.php */
/* Location: application/libraries/Imaging_lib.php */
