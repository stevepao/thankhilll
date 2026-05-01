<?php
/**
 * includes/note_media.php — Note photo storage paths, validation, and persistence.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/** Landscape-oriented caps (width ≥ height). */
const NOTE_MEDIA_MAX_LANDSCAPE_W = 1920;
const NOTE_MEDIA_MAX_LANDSCAPE_H = 1080;

/** Portrait-oriented caps (width < height). */
const NOTE_MEDIA_MAX_PORTRAIT_W = 1080;
const NOTE_MEDIA_MAX_PORTRAIT_H = 1920;

const NOTE_MEDIA_MAX_FILE_BYTES = 5242880;

/** Hard limit per note per save (Today composer). */
const NOTE_MEDIA_MAX_FILES_PER_UPLOAD = 10;

function note_media_storage_root(): string
{
    loadEnv();
    $configured = $_ENV['NOTE_MEDIA_STORAGE_PATH'] ?? getenv('NOTE_MEDIA_STORAGE_PATH');
    if (is_string($configured) && $configured !== '') {
        return $configured;
    }

    return dirname(__DIR__) . '/storage/note_media';
}

/**
 * Max dimensions for an image with given width/height (same rules as client).
 *
 * @return array{0:int,1:int}|null null if invalid type
 */
function note_media_max_dimensions_for_shape(int $width, int $height): ?array
{
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    if ($width >= $height) {
        return [NOTE_MEDIA_MAX_LANDSCAPE_W, NOTE_MEDIA_MAX_LANDSCAPE_H];
    }

    return [NOTE_MEDIA_MAX_PORTRAIT_W, NOTE_MEDIA_MAX_PORTRAIT_H];
}

/**
 * True when dimensions fit inside orientation-specific caps (server enforcement).
 */
function note_media_dimensions_allowed(int $width, int $height): bool
{
    $caps = note_media_max_dimensions_for_shape($width, $height);
    if ($caps === null) {
        return false;
    }

    return $width <= $caps[0] && $height <= $caps[1];
}

/**
 * Validate MIME + real image type + caps + file size. Does not move the file.
 *
 * @return array{ok:true,mime:string,ext:string,width:int,height:int}|array{ok:false,error:string}
 */
function note_media_validate_upload(string $tmpPath, int $sizeBytes): array
{
    if ($sizeBytes <= 0 || $sizeBytes > NOTE_MEDIA_MAX_FILE_BYTES) {
        return ['ok' => false, 'error' => 'Photo file is too large or empty.'];
    }

    if (!is_readable($tmpPath)) {
        return ['ok' => false, 'error' => 'Could not read the uploaded photo.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    if (!is_string($mime)) {
        return ['ok' => false, 'error' => 'Could not detect image type.'];
    }

    $info = @getimagesize($tmpPath);
    if ($info === false || !isset($info[0], $info[1], $info[2])) {
        return ['ok' => false, 'error' => 'Not a valid image file.'];
    }

    $w = (int) $info[0];
    $h = (int) $info[1];
    $itype = (int) $info[2];

    $allowed = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
    ];
    if (!isset($allowed[$itype])) {
        return ['ok' => false, 'error' => 'Only JPEG or PNG photos are allowed.'];
    }

    [$expectMime, $ext] = $allowed[$itype];
    if ($mime !== $expectMime) {
        return ['ok' => false, 'error' => 'Image type does not match its contents.'];
    }

    if (!note_media_dimensions_allowed($w, $h)) {
        return ['ok' => false, 'error' => 'Image dimensions exceed allowed limits.'];
    }

    return [
        'ok' => true,
        'mime' => $mime,
        'ext' => $ext,
        'width' => $w,
        'height' => $h,
    ];
}

/**
 * Store validated upload under storage root; returns relative path key or null on failure.
 */
function note_media_store_file(string $tmpPath, string $ext): ?string
{
    $root = note_media_storage_root();
    if (!is_dir($root)) {
        if (!@mkdir($root, 0770, true) && !is_dir($root)) {
            return null;
        }
    }

    $relDir = bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(1));
    $dir = $root . '/' . $relDir;
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return null;
    }

    $name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $targetAbs = $dir . '/' . $name;
    $relPath = $relDir . '/' . $name;

    if (!@move_uploaded_file($tmpPath, $targetAbs)) {
        if (!@rename($tmpPath, $targetAbs)) {
            return null;
        }
    }

    @chmod($targetAbs, 0660);

    return $relPath;
}

/**
 * Delete filesystem object for a stored relative path (best-effort).
 */
function note_media_delete_relative(string $relativePath): void
{
    $root = realpath(note_media_storage_root());
    if ($root === false) {
        return;
    }

    $suffix = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $full = realpath($root . DIRECTORY_SEPARATOR . $suffix);
    if ($full !== false && strncmp($full, $root, strlen($root)) === 0 && is_file($full)) {
        @unlink($full);
    }
}

/**
 * Resolve absolute path for serving; returns null if unsafe or missing.
 */
function note_media_resolve_absolute(string $relativePath): ?string
{
    $root = realpath(note_media_storage_root());
    if ($root === false) {
        return null;
    }

    $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    if (strncmp($candidate, $root, strlen($root)) !== 0) {
        return null;
    }

    return $candidate;
}

/**
 * Normalize multipart photos[] from the Today form (JPEG/PNG uploads only).
 *
 * @return array{ok:true,items:list<array{tmp:string,size:int}>}|array{ok:false,error:string}
 */
function note_media_normalize_uploads_from_request(): array
{
    if (!isset($_FILES['photos'])) {
        return ['ok' => true, 'items' => []];
    }

    $f = $_FILES['photos'];
    if (!is_array($f['tmp_name'])) {
        $tmps = [$f['tmp_name']];
        $errs = [$f['error'] ?? UPLOAD_ERR_OK];
        $sizes = [$f['size'] ?? 0];
    } else {
        $tmps = $f['tmp_name'];
        $errs = $f['error'];
        $sizes = $f['size'];
    }

    $items = [];
    foreach ($tmps as $i => $tmp) {
        $err = is_array($errs) ? ($errs[$i] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK;
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Photo upload failed. Please try again.'];
        }
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid photo upload.'];
        }
        $sz = (int) (is_array($sizes) ? ($sizes[$i] ?? 0) : $sizes);
        $items[] = ['tmp' => $tmp, 'size' => $sz];
    }

    if (count($items) > NOTE_MEDIA_MAX_FILES_PER_UPLOAD) {
        return ['ok' => false, 'error' => 'Too many photos for one note.'];
    }

    return ['ok' => true, 'items' => $items];
}

/**
 * @param list<array{tmp:string,size:int}> $normalizedUploads
 * @return array{ok:true}|array{ok:false,error:string}
 */
function note_media_attach_to_note(PDO $pdo, int $noteId, array $normalizedUploads): array
{
    $onDisk = [];

    foreach ($normalizedUploads as $item) {
        $tmp = $item['tmp'];
        $size = $item['size'];
        $v = note_media_validate_upload($tmp, $size);
        if (!$v['ok']) {
            foreach ($onDisk as $p) {
                note_media_delete_relative($p);
            }

            return ['ok' => false, 'error' => $v['error']];
        }

        /** @var array{ok:true,mime:string,ext:string,width:int,height:int} $v */
        $rel = note_media_store_file($tmp, $v['ext']);
        if ($rel === null) {
            foreach ($onDisk as $p) {
                note_media_delete_relative($p);
            }

            return ['ok' => false, 'error' => 'Could not store the photo.'];
        }

        $onDisk[] = $rel;

        try {
            $ins = $pdo->prepare(
                'INSERT INTO note_media (note_id, file_path, width, height, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $ins->execute([$noteId, $rel, $v['width'], $v['height']]);
        } catch (Throwable $e) {
            foreach ($onDisk as $p) {
                note_media_delete_relative($p);
            }
            error_log('note_media_attach_to_note: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not save photo metadata.'];
        }
    }

    return ['ok' => true];
}

/**
 * @return list<array{id:int,file_path:string,width:int,height:int}>
 */
function note_media_for_note(PDO $pdo, int $noteId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, file_path, width, height FROM note_media WHERE note_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$noteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'file_path' => (string) $r['file_path'],
            'width' => (int) $r['width'],
            'height' => (int) $r['height'],
        ];
    }, $rows);
}

/**
 * All media rows for the given notes (small batches for UI).
 *
 * @param list<int> $noteIds
 * @return array<int, list<array{id:int,width:int,height:int}>>
 */
function note_media_grouped_by_note(PDO $pdo, array $noteIds): array
{
    $noteIds = array_values(array_unique(array_filter(array_map('intval', $noteIds), static fn (int $id): bool => $id > 0)));
    if ($noteIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
    $sql = "SELECT id, note_id, width, height FROM note_media WHERE note_id IN ($placeholders) ORDER BY note_id ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($noteIds);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nid = (int) $row['note_id'];
        if (!isset($map[$nid])) {
            $map[$nid] = [];
        }
        $map[$nid][] = [
            'id' => (int) $row['id'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
        ];
    }

    return $map;
}
