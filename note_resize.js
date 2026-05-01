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
 */
(function () {
    'use strict';

    var MAX_LANDSCAPE_W = 1920;
    var MAX_LANDSCAPE_H = 1080;
    var MAX_PORTRAIT_W = 1080;
    var MAX_PORTRAIT_H = 1920;

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
        if (
            !Number.isFinite(srcWidth) ||
            !Number.isFinite(srcHeight) ||
            srcWidth <= 0 ||
            srcHeight <= 0
        ) {
            throw new Error('Invalid image dimensions.');
        }

        var box = boundsForOrientation(srcWidth, srcHeight);
        var maxW = box.maxWidth;
        var maxH = box.maxHeight;

        var scaleUncapped = Math.min(maxW / srcWidth, maxH / srcHeight);
        if (!Number.isFinite(scaleUncapped) || scaleUncapped <= 0) {
            throw new Error('Could not compute resize scale.');
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
     * Decode image fully before drawing to canvas (object URL revoked after success).
     */
    function loadImageFile(file) {
        return new Promise(function (resolve, reject) {
            var objectUrl = URL.createObjectURL(file);
            var img = new Image();

            function cleanupUrl() {
                try {
                    URL.revokeObjectURL(objectUrl);
                } catch (e) {
                    /* ignore */
                }
            }

            function fail(message) {
                cleanupUrl();
                reject(new Error(message));
            }

            img.onload = function () {
                var nw = img.naturalWidth;
                var nh = img.naturalHeight;

                if (
                    !Number.isFinite(nw) ||
                    !Number.isFinite(nh) ||
                    nw <= 0 ||
                    nh <= 0
                ) {
                    fail('Image has no usable pixel dimensions.');
                    return;
                }

                function proceed() {
                    cleanupUrl();
                    resolve(img);
                }

                // Prefer decode() so raster work finishes before canvas draw where supported.
                if (typeof img.decode === 'function') {
                    img.decode().then(proceed).catch(function () {
                        fail('Could not decode this image.');
                    });
                } else {
                    proceed();
                }
            };

            img.onerror = function () {
                fail('Could not read this image.');
            };

            img.src = objectUrl;
        });
    }

    function canvasToBlob(canvas, mimeType, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(
                function (blob) {
                    if (!blob || blob.size === 0) {
                        reject(new Error('Could not encode this image.'));
                        return;
                    }
                    resolve(blob);
                },
                mimeType,
                quality
            );
        });
    }

    /**
     * Resize a single file once; returns a Blob. Stateless aside from local variables.
     *
     * @param {File} file
     * @returns {Promise<Blob>}
     */
    function resizeImageFile(file) {
        var mime = file.type || '';
        if (mime !== 'image/jpeg' && mime !== 'image/png') {
            return Promise.reject(new Error('Please choose JPEG or PNG photos only.'));
        }

        return loadImageFile(file).then(function (img) {
            var srcW = img.naturalWidth;
            var srcH = img.naturalHeight;

            var sized = computeTargetSize(srcW, srcH);

            var canvas = document.createElement('canvas');
            canvas.width = sized.targetWidth;
            canvas.height = sized.targetHeight;

            var ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('Could not prepare image.');
            }

            // Single draw: intrinsic image → target bitmap (one resize pass per file).
            ctx.drawImage(img, 0, 0, sized.targetWidth, sized.targetHeight);

            var outMime = mime === 'image/png' ? 'image/png' : 'image/jpeg';
            var quality = outMime === 'image/jpeg' ? 0.88 : undefined;

            return canvasToBlob(canvas, outMime, quality);
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
            return Promise.reject(
                new Error('You can add at most ' + maxCount + ' photos per note.')
            );
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
    };
})();
