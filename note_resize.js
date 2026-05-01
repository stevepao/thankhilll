/**
 * Client-side resize for Today note photos.
 *
 * Bounds MUST stay aligned with server enforcement in includes/note_media.php
 * (NOTE_MEDIA_MAX_LANDSCAPE_*, NOTE_MEDIA_MAX_PORTRAIT_*).
 *
 * Guarantees (see computeTargetSize):
 * - Output width ≤ orientation max width; output height ≤ orientation max height.
 * - Aspect ratio preserved within independent-per-axis Math.floor rounding.
 * - Never upscales: scale factor is always ≤ 1.
 * - Each file is processed independently (no shared canvas or mutable cross-file state).
 *
 * Failure handling: any problem preparing a resized blob rejects the promise.
 * Callers must abort upload — never fall back to uploading the original file.
 */
(function () {
    'use strict';

    var MAX_LANDSCAPE_W = 1920;
    var MAX_LANDSCAPE_H = 1080;
    var MAX_PORTRAIT_W = 1080;
    var MAX_PORTRAIT_H = 1920;

    /** Shown when decoding, resizing, or encoding fails (see task copy). */
    var USER_VISIBLE_FAILURE_MESSAGE =
        "We couldn't process this photo. Please try a different image.";

    /** Separate guardrail before resize runs (not a Canvas failure). */
    function maxFilesExceededMessage(maxCount) {
        return 'You can add at most ' + maxCount + ' photos per note.';
    }

    /**
     * Hard stop if canvas.toBlob never invokes its callback (browser/resource bugs).
     * Keeps one settle path via finished flag (exactly one outcome per invocation).
     */
    var TO_BLOB_TIMEOUT_MS = 45000;

    /**
     * Orientation follows intrinsic pixels (naturalWidth × naturalHeight).
     * Square images use the landscape cap box (width ≥ height).
     */
    function boundsForOrientation(srcWidth, srcHeight) {
        if (srcWidth >= srcHeight) {
            return { maxWidth: MAX_LANDSCAPE_W, maxHeight: MAX_LANDSCAPE_H };
        }
        return { maxWidth: MAX_PORTRAIT_W, maxHeight: MAX_PORTRAIT_H };
    }

    /**
     * Compute fitted dimensions inside the orientation box without upscaling.
     *
     * Math:
     * - scaleUncapped = min(maxWidth/srcWidth, maxHeight/srcHeight) fits the image
     *   entirely inside the box while preserving aspect ratio.
     * - scaleFactor = min(scaleUncapped, 1) forbids enlarging small images.
     * - Dimensions use Math.floor so canvas pixel counts are integers and we never
     *   round upward past the logical scaled size (avoids exceeding caps).
     *
     * Edge cases:
     * - Already within limits → scaleUncapped ≥ 1 → scaleFactor = 1 → original size (floored).
     * - Extreme aspect ratios → floors keep at least 1×1 via Math.max(1, …).
     */
    function computeTargetSize(srcWidth, srcHeight) {
        // Guards NaN, Infinity, undefined coerced, zero — invalid intrinsic geometry.
        if (
            !Number.isFinite(srcWidth) ||
            !Number.isFinite(srcHeight) ||
            srcWidth <= 0 ||
            srcHeight <= 0
        ) {
            throw new Error('INVALID_DIMENSIONS');
        }

        var box = boundsForOrientation(srcWidth, srcHeight);
        var maxW = box.maxWidth;
        var maxH = box.maxHeight;

        var scaleUncapped = Math.min(maxW / srcWidth, maxH / srcHeight);
        // Protects division-by-edge-case quirks (Infinity/NaN) before scaling canvas.
        if (!Number.isFinite(scaleUncapped) || scaleUncapped <= 0) {
            throw new Error('INVALID_SCALE');
        }

        var scaleFactor = Math.min(scaleUncapped, 1);

        var targetWidth = Math.max(1, Math.floor(srcWidth * scaleFactor));
        var targetHeight = Math.max(1, Math.floor(srcHeight * scaleFactor));

        // Belt-and-suspenders: independent floors must never exceed caps (e.g. FP noise).
        targetWidth = Math.min(maxW, targetWidth);
        targetHeight = Math.min(maxH, targetHeight);

        return {
            targetWidth: targetWidth,
            targetHeight: targetHeight,
        };
    }

    /**
     * Encode canvas pixels to Blob with timeout + null/empty blob detection.
     * finished prevents double resolve/reject if timeout races toBlob callback.
     */
    function canvasToBlobReliable(canvas, mimeType, quality) {
        return new Promise(function (resolve, reject) {
            var finished = false;

            function settle(ok, value) {
                if (finished) {
                    return;
                }
                finished = true;
                window.clearTimeout(timeoutId);
                if (ok) {
                    resolve(value);
                } else {
                    reject(value);
                }
            }

            var timeoutId = window.setTimeout(function () {
                settle(false, new Error('TO_BLOB_TIMEOUT'));
            }, TO_BLOB_TIMEOUT_MS);

            try {
                canvas.toBlob(
                    function (blob) {
                        // toBlob contract: failure → null; we also reject empty blobs.
                        if (!blob || blob.size === 0) {
                            settle(false, new Error('TO_BLOB_NULL_OR_EMPTY'));
                            return;
                        }
                        settle(true, blob);
                    },
                    mimeType,
                    quality
                );
            } catch (syncErr) {
                // Rare: canvas unusable or implementation threw before callback scheduled.
                settle(false, syncErr);
            }
        });
    }

    /**
     * Decode image fully after load; revokes object URL exactly once on success or failure.
     * Ensures resize runs only after Image.onload (dimensions live after decode path too).
     */
    function loadImageFile(file) {
        return new Promise(function (resolve, reject) {
            var objectUrl = URL.createObjectURL(file);
            var settled = false;
            var img = new Image();

            function cleanupUrl() {
                try {
                    URL.revokeObjectURL(objectUrl);
                } catch (e) {
                    /* ignore */
                }
            }

            function fail(reason) {
                if (settled) {
                    return;
                }
                settled = true;
                cleanupUrl();
                reject(reason);
            }

            function succeed(decodedImg) {
                if (settled) {
                    return;
                }
                settled = true;
                cleanupUrl();
                resolve(decodedImg);
            }

            img.onload = function () {
                var nw = img.naturalWidth;
                var nh = img.naturalHeight;

                // Decode succeeded but bitmap missing dimensions (broken/corrupt assets).
                if (
                    !Number.isFinite(nw) ||
                    !Number.isFinite(nh) ||
                    nw <= 0 ||
                    nh <= 0
                ) {
                    fail(new Error('BAD_NATURAL_SIZE'));
                    return;
                }

                function proceed() {
                    succeed(img);
                }

                // Prefer decode() so raster work finishes before canvas draw where supported.
                if (typeof img.decode === 'function') {
                    img.decode().then(proceed).catch(function () {
                        fail(new Error('DECODE_FAILED'));
                    });
                } else {
                    proceed();
                }
            };

            img.onerror = function () {
                // Network/format decode stopped before dimensions exist (Image.onerror).
                fail(new Error('IMAGE_ONERROR'));
            };

            img.src = objectUrl;
        });
    }

    /**
     * Resize after Image has fired onload (caller passes loaded img).
     * Single draw path; wraps drawImage for synchronous canvas exceptions.
     */
    function resizeDecodedImage(img, mime) {
        var srcW = img.naturalWidth;
        var srcH = img.naturalHeight;

        // Defensive: async gap between decode promise and draw — re-read naturals.
        if (
            !Number.isFinite(srcW) ||
            !Number.isFinite(srcH) ||
            srcW <= 0 ||
            srcH <= 0
        ) {
            return Promise.reject(new Error('NATURALS_STALE'));
        }

        var sized;
        try {
            sized = computeTargetSize(srcW, srcH);
        } catch (e) {
            return Promise.reject(e);
        }

        // Canvas pixel buffers must be finite integers ≥ 1 (guarded by computeTargetSize).
        if (
            !Number.isFinite(sized.targetWidth) ||
            !Number.isFinite(sized.targetHeight) ||
            sized.targetWidth < 1 ||
            sized.targetHeight < 1
        ) {
            return Promise.reject(new Error('BAD_TARGET_SIZE'));
        }

        var canvas = document.createElement('canvas');
        canvas.width = sized.targetWidth;
        canvas.height = sized.targetHeight;

        var ctx = canvas.getContext('2d');
        // Canvas may refuse a context (memory/policy); cannot paint without it.
        if (!ctx) {
            return Promise.reject(new Error('NO_CONTEXT'));
        }

        try {
            ctx.drawImage(img, 0, 0, sized.targetWidth, sized.targetHeight);
        } catch (drawErr) {
            // Security errors / broken GPU paths surface here synchronously.
            return Promise.reject(drawErr);
        }

        var outMime = mime === 'image/png' ? 'image/png' : 'image/jpeg';
        var quality = outMime === 'image/jpeg' ? 0.88 : undefined;

        return canvasToBlobReliable(canvas, outMime, quality);
    }

    /**
     * Resize a single file once; returns a Blob.
     * Maps any failure to USER_VISIBLE_FAILURE_MESSAGE for stable UX (single outcome).
     *
     * @param {File} file
     * @returns {Promise<Blob>}
     */
    function resizeImageFile(file) {
        var mime = file.type || '';
        if (mime !== 'image/jpeg' && mime !== 'image/png') {
            return Promise.reject(new Error(USER_VISIBLE_FAILURE_MESSAGE));
        }

        return loadImageFile(file)
            .then(function (img) {
                return resizeDecodedImage(img, mime);
            })
            .catch(function () {
                // One visible outcome per file — never swallow failures silently.
                return Promise.reject(new Error(USER_VISIBLE_FAILURE_MESSAGE));
            });
    }

    /**
     * Process files in parallel; each entry is independent (Promise.all).
     *
     * @param {FileList|null} files
     * @returns {Promise<Blob[]>}
     */
    function resizeAll(files, maxCount) {
        maxCount = typeof maxCount === 'number' && maxCount > 0 ? maxCount : 10;

        if (!files || files.length === 0) {
            return Promise.resolve([]);
        }

        if (files.length > maxCount) {
            return Promise.reject(new Error(maxFilesExceededMessage(maxCount)));
        }

        var tasks = [];
        for (var i = 0; i < files.length; i++) {
            tasks.push(resizeImageFile(files[i]));
        }

        return Promise.all(tasks);
    }

    window.NotePhotoResize = {
        resizeAll: resizeAll,
        maxFiles: 10,
        USER_VISIBLE_FAILURE_MESSAGE: USER_VISIBLE_FAILURE_MESSAGE,
    };
})();
