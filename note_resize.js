/**
 * Client-side resize for Today note photos (must match server caps in note_media.php).
 */
(function () {
    'use strict';

    var MAX_LANDSCAPE_W = 1920;
    var MAX_LANDSCAPE_H = 1080;
    var MAX_PORTRAIT_W = 1080;
    var MAX_PORTRAIT_H = 1920;

    function maxBox(w, h) {
        if (w >= h) {
            return { maxW: MAX_LANDSCAPE_W, maxH: MAX_LANDSCAPE_H };
        }
        return { maxW: MAX_PORTRAIT_W, maxH: MAX_PORTRAIT_H };
    }

    /**
     * Target dimensions: fit inside orientation box, preserve aspect, never upscale.
     */
    function targetDimensions(w, h) {
        var box = maxBox(w, h);
        if (w <= box.maxW && h <= box.maxH) {
            return { w: w, h: h };
        }
        var scale = Math.min(box.maxW / w, box.maxH / h);
        if (scale >= 1) {
            return { w: w, h: h };
        }
        return {
            w: Math.max(1, Math.round(w * scale)),
            h: Math.max(1, Math.round(h * scale)),
        };
    }

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                resolve(img);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('Could not read this image.'));
            };
            img.src = url;
        });
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(
                function (blob) {
                    if (!blob) {
                        reject(new Error('Could not process this image.'));
                        return;
                    }
                    resolve(blob);
                },
                type,
                quality
            );
        });
    }

    /**
     * @param {File} file
     * @returns {Promise<Blob>}
     */
    function resizeImageFile(file) {
        var mime = file.type || '';
        if (mime !== 'image/jpeg' && mime !== 'image/png') {
            return Promise.reject(new Error('Please choose JPEG or PNG photos only.'));
        }

        return loadImage(file).then(function (img) {
            var tw = targetDimensions(img.naturalWidth, img.naturalHeight);
            var canvas = document.createElement('canvas');
            canvas.width = tw.w;
            canvas.height = tw.h;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('Could not prepare image.');
            }
            ctx.drawImage(img, 0, 0, tw.w, tw.h);

            var outType = mime === 'image/png' ? 'image/png' : 'image/jpeg';
            var q = outType === 'image/jpeg' ? 0.88 : undefined;
            return canvasToBlob(canvas, outType, q);
        });
    }

    /**
     * @param {FileList|null} files
     * @returns {Promise<Blob[]>}
     */
    function resizeAll(files, maxCount) {
        maxCount = maxCount || 10;
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
