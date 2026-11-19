/**
 * S3 Browser Upload Functionality - Updated with File Validation
 * Handles direct browser uploads to S3 buckets using presigned URLs
 *
 * A file from S3BrowserGlobalConfig.multipart.threshold up -- and every file
 * past the 5 GB one PUT may carry -- goes as a multipart upload instead: the
 * server starts it and signs part URLs, the browser PUTs each part with a
 * Content-MD5 storage checks it against, a few at a time, retrying a part
 * that fails, and the server assembles what storage says it holds. Dropping
 * the same file again after a reload carries on from the parts storage kept.
 */
(function ($) {
    'use strict';

    // Extend the main S3Browser object with upload methods
    $.extend(window.S3Browser, {

        // Upload-specific state
        activeUploads: {},
        activeUploadCount: 0,

        /**
         * Bind upload event handlers
         */
        bindUploadEvents: function () {
            var self = this;
            var $dropzone = $('.s3-upload-zone');
            var $fileInput = $('.s3-file-input');

            // Drag and drop
            $dropzone.on({
                dragover: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).addClass('s3-dragover');
                },
                dragleave: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('s3-dragover');
                },
                drop: function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('s3-dragover');

                    var files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        var bucket = $(this).data('bucket');
                        var prefix = $(this).data('prefix') || '';
                        self.uploadFiles(files, bucket, prefix);
                    }
                }
            });

            // File input
            $fileInput.on('change', function () {
                if (this.files.length) {
                    var bucket = $dropzone.data('bucket');
                    var prefix = $dropzone.data('prefix') || '';
                    self.uploadFiles(this.files, bucket, prefix);
                    this.value = '';
                }
            });

            // Cancel uploads
            $('.s3-upload-list').on('click', '.s3-cancel-upload', function (e) {
                e.preventDefault();
                var uploadId = $(this).data('upload-id');
                self.cancelUpload(uploadId);
            });
        },

        /**
         * Validate files before upload
         */
        validateFiles: function (files) {
            var self = this;
            var validFiles = [];
            var errors = [];
            var config = S3BrowserGlobalConfig.fileValidation || {};

            Array.from(files).forEach(function (file) {
                var validation = self.validateSingleFile(file, config);

                if (validation.valid) {
                    validFiles.push(file);
                } else {
                    errors.push({
                        fileName: file.name,
                        error: validation.error
                    });
                }
            });

            // Show validation errors
            if (errors.length > 0) {
                self.showValidationErrors(errors);
            }

            return validFiles;
        },

        /**
         * Validate a single file
         */
        validateSingleFile: function (file, config) {
            var allowedExtensions = config.allowedExtensions || [];

            // Get file extension
            var fileName = file.name.toLowerCase();
            var extension = fileName.split('.').pop();

            // Check file extension
            if (allowedExtensions.length > 0 && !allowedExtensions.includes(extension)) {
                return {
                    valid: false,
                    error: s3BrowserConfig.i18n.validation.invalidFileType.replace('{extension}', extension)
                };
            }

            // Check MIME type if available
            if (file.type && config.allowedMimeTypes) {
                var mimeAllowed = Object.values(config.allowedMimeTypes).includes(file.type);
                if (!mimeAllowed) {
                    return {
                        valid: false,
                        error: s3BrowserConfig.i18n.validation.invalidMimeType.replace('{mimeType}', file.type)
                    };
                }
            }

            return {valid: true};
        },

        /**
         * Show validation errors
         */
        showValidationErrors: function (errors) {
            var errorHtml = '<div class="s3-validation-errors">';
            errorHtml += '<h4>' + s3BrowserConfig.i18n.validation.validationFailed + '</h4>';
            errorHtml += '<ul>';

            errors.forEach(function (error) {
                errorHtml += '<li><strong>' + error.fileName + ':</strong> ' + error.error + '</li>';
            });

            errorHtml += '</ul></div>';

            this.showUploadError(errorHtml);
        },

        /**
         * Show upload error message
         */
        showUploadError: function (message) {
            $('.s3-upload-notice').remove();
            var $notice = $('<div class="s3-upload-notice">' + message + '</div>');
            $('.s3-upload-list').before($notice);

            setTimeout(function () {
                $notice.fadeOut(500, function () {
                    $(this).remove();
                });
            }, 8000);
        },

        /**
         * Upload multiple files (updated with validation)
         */
        uploadFiles: function (files, bucket, prefix) {
            var self = this;

            // Validate files first
            var validFiles = this.validateFiles(files);

            if (validFiles.length === 0) {
                // All files failed validation
                return;
            }

            // Show info if some files were rejected
            if (validFiles.length < files.length) {
                var rejectedCount = files.length - validFiles.length;
                this.showNotification(
                    s3BrowserConfig.i18n.validation.someFilesRejected
                        .replace('{accepted}', validFiles.length)
                        .replace('{rejected}', rejectedCount),
                    'info'
                );
            }

            var $uploadList = $('.s3-upload-list');
            var uploadPromises = [];

            $uploadList.show();
            $(document).trigger('s3UploadStarted');

            Array.from(validFiles).forEach(function (file) {
                var normalizedPrefix = prefix ? (prefix.endsWith('/') ? prefix : prefix + '/') : '';
                var objectKey = normalizedPrefix + file.name;
                var uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

                var $progress = $([
                    '<div class="s3-upload-item" id="' + uploadId + '">',
                    '  <div class="s3-upload-item-info">',
                    '    <span class="s3-filename">' + window.S3Browser.escapeHtml(file.name) + '</span>',
                    '    <span class="s3-filesize">' + self.formatFileSize(file.size) + '</span>',
                    '  </div>',
                    '  <div class="s3-progress-container">',
                    '    <div class="s3-progress-bar">',
                    '      <div class="s3-progress" style="width: 0"></div>',
                    '    </div>',
                    '    <span class="s3-progress-text">0%</span>',
                    '    <span class="s3-transfer-data"></span>',
                    '  </div>',
                    '  <div class="s3-upload-status">',
                    '    <button class="s3-cancel-upload" title="Cancel upload" data-upload-id="' + uploadId + '">',
                    '      ' + window.S3Browser.icon('cancel'),
                    '    </button>',
                    '  </div>',
                    '</div>'
                ].join(''));

                $uploadList.append($progress);
                self.activeUploadCount++;

                var uploadPromise = (self.usesParts(file)
                    ? self.uploadInParts(file, bucket, objectKey, $progress, uploadId)
                    : self.getPresignedUrl(bucket, objectKey)
                        .then(function (url) {
                            return self.uploadToS3(file, url, $progress, uploadId);
                        }))
                    .then(function () {
                        $progress.addClass('s3-upload-success');
                        $progress.find('.s3-upload-status').html(window.S3Browser.icon('done'));
                        self.handleUploadComplete(uploadId, true);
                    })
                    .catch(function (error) {
                        console.error('Upload error:', error);
                        self.handleUploadError(error, $progress, uploadId);
                    });

                uploadPromises.push(uploadPromise);
            });

            this.handleAllUploadsComplete(uploadPromises);
        },

        /**
         * Handle upload completion
         */
        handleUploadComplete: function (uploadId, success) {
            delete this.activeUploads[uploadId];
            this.activeUploadCount--;

            if (this.activeUploadCount === 0) {
                $(document).trigger('s3AllUploadsComplete');
            }
            $(document).trigger('s3UploadComplete', [success]);
        },

        /**
         * Handle upload errors
         */
        handleUploadError: function (error, $progress, uploadId) {
            var upload = s3BrowserConfig.i18n.upload;

            if (error.message === upload.uploadCancelled) {
                $progress.addClass('s3-upload-cancelled');
                $progress.find('.s3-upload-status').html(window.S3Browser.icon('cancel'));
                setTimeout(function () {
                    $progress.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                $progress.addClass('s3-upload-error');
                $progress.find('.s3-upload-status').html(window.S3Browser.icon('failed'));

                var errorMsg = error.message || upload.uploadFailed;
                if (errorMsg.includes('CORS') || errorMsg.includes('403') || errorMsg.includes('401')) {
                    this.showUploadError(upload.corsError);
                } else if (errorMsg.includes('network')) {
                    this.showUploadError(upload.networkError);
                } else {
                    this.showUploadError(upload.uploadFailed + ' ' + errorMsg);
                }
            }

            this.handleUploadComplete(uploadId, false);
        },

        /**
         * Handle all uploads completion
         */
        handleAllUploadsComplete: function (uploadPromises) {
            var self = this;
            Promise.allSettled(uploadPromises).then(function () {
                setTimeout(function () {
                    var hasSuccessfulUploads = $('.s3-upload-success').length > 0;
                    if (hasSuccessfulUploads) {
                        self.showNotification(s3BrowserConfig.i18n.upload.uploadComplete, 'success');
                        window.location.reload();
                    }
                }, 3000);
            });
        },

        /**
         * Get presigned URL for upload
         */
        getPresignedUrl: function (bucket, objectKey) {
            var self = this;
            return new Promise(function (resolve, reject) {
                $.ajax({
                    url: S3BrowserGlobalConfig.restUrl
                        + '/buckets/' + encodeURIComponent(bucket) + '/objects/upload-url',
                    method: 'POST',
                    contentType: 'application/json; charset=utf-8',
                    headers: {'X-WP-Nonce': S3BrowserGlobalConfig.restNonce},
                    data: JSON.stringify({key: objectKey}),
                    success: function (payload) {
                        if (payload && payload.url) {
                            resolve(payload.url);
                        } else {
                            reject(new Error((payload && payload.message) || s3BrowserConfig.i18n.upload.failedPresignedUrl));
                        }
                    },
                    error: function (xhr, status, error) {
                        if (xhr.status === 403 || xhr.status === 401) {
                            reject(new Error('Authentication failed - check S3 credentials'));
                        } else {
                            reject(new Error(error || s3BrowserConfig.i18n.upload.networkError));
                        }
                    }
                });
            });
        },

        /**
         * Upload file to S3 using presigned URL
         */
        uploadToS3: function (file, presignedUrl, $progress, uploadId) {
            var self = this;
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                var lastLoaded = 0;
                var lastTime = Date.now();

                self.activeUploads[uploadId] = xhr;

                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        $progress.find('.s3-progress').css('width', percentComplete + '%');
                        $progress.find('.s3-progress-text').text(percentComplete + '%');

                        var currentTime = Date.now();
                        var timeDiff = (currentTime - lastTime) / 1000;

                        if (timeDiff > 0.5) {
                            var loadedDiff = e.loaded - lastLoaded;
                            var uploadSpeed = loadedDiff / timeDiff;

                            var transferred = self.formatFileSize(e.loaded) + ' / ' + self.formatFileSize(e.total);
                            var speed = self.formatFileSize(uploadSpeed) + '/s';
                            $progress.find('.s3-transfer-data').text(transferred + ' • ' + speed);

                            lastLoaded = e.loaded;
                            lastTime = currentTime;
                        }
                    }
                });

                xhr.addEventListener('load', function () {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(xhr.response);
                    } else {
                        reject(new Error(s3BrowserConfig.i18n.upload.uploadFailedStatus + ' ' + xhr.status));
                    }
                });

                xhr.addEventListener('error', function (e) {
                    if (e.target.status === 0) {
                        reject(new Error(s3BrowserConfig.i18n.upload.corsError));
                    } else {
                        reject(new Error(s3BrowserConfig.i18n.upload.networkError));
                    }
                });

                xhr.addEventListener('abort', function () {
                    reject(new Error(s3BrowserConfig.i18n.upload.uploadCancelled));
                });

                xhr.open('PUT', presignedUrl, true);
                xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.send(file);
            });
        },

        /**
         * The multipart settings, with defaults for a page that has none.
         */
        partSettings: function () {
            var given = (window.S3BrowserGlobalConfig && S3BrowserGlobalConfig.multipart) || {};

            return {
                threshold: given.threshold || 64 * 1048576,
                singlePutMax: given.singlePutMax || 5 * 1073741824,
                maxSize: given.maxSize || 5 * 1099511627776,
                concurrency: Math.max(1, given.concurrency || 4),
                signBatch: Math.max(1, given.signBatch || 20),
                retries: Math.max(1, given.retries || 4)
            };
        },

        /**
         * Whether a file goes in parts.
         */
        usesParts: function (file) {
            var settings = this.partSettings();

            return file.size >= settings.threshold || file.size > settings.singlePutMax;
        },

        /**
         * One call to the upload routes.
         */
        partsRequest: function (method, bucket, path, data) {
            var strings = s3BrowserConfig.i18n.upload;

            return new Promise(function (resolve, reject) {
                $.ajax({
                    url: S3BrowserGlobalConfig.restUrl + '/buckets/' + encodeURIComponent(bucket) + '/uploads' + (path ? '/' + path : ''),
                    method: method,
                    contentType: 'application/json; charset=utf-8',
                    dataType: 'json',
                    headers: {'X-WP-Nonce': S3BrowserGlobalConfig.restNonce},
                    data: data ? JSON.stringify(data) : undefined,
                    success: function (payload) {
                        resolve(payload || {});
                    },
                    error: function (xhr) {
                        var body = xhr.responseJSON || {};
                        var error = new Error(body.message || strings.networkError);

                        error.code = body.code || '';
                        error.data = body.data || {};
                        error.status = xhr.status;
                        reject(error);
                    }
                });
            });
        },

        /**
         * Send a file as a multipart upload, resuming one this file began.
         */
        uploadInParts: function (file, bucket, objectKey, $progress, rowId) {
            var self = this;
            var settings = this.partSettings();
            var strings = s3BrowserConfig.i18n.upload;
            var job = {
                file: file,
                bucket: bucket,
                id: '',
                partSize: 0,
                partCount: 0,
                done: {},
                doneBytes: 0,
                inFlight: {},
                xhrs: {},
                urls: {},
                signing: null,
                checksums: true,
                cancelled: false,
                $progress: $progress,
                lastLoaded: 0,
                lastTime: Date.now()
            };

            if (file.size > settings.maxSize) {
                return Promise.reject(new Error(strings.tooLarge.replace('{filename}', file.name)));
            }

            self.activeUploads[rowId] = {
                abort: function () {
                    job.cancelled = true;

                    Object.keys(job.xhrs).forEach(function (number) {
                        job.xhrs[number].abort();
                    });

                    // Free the parts in storage rather than leave them
                    // billed until the weekly clear-up.
                    if (job.id) {
                        self.partsRequest('DELETE', bucket, job.id).catch(function () {});
                    }
                }
            };

            return self.partsRequest('POST', bucket, '', {
                key: objectKey,
                size: file.size,
                mime_type: file.type || 'application/octet-stream',
                fingerprint: [file.name, file.size, file.lastModified].join('|')
            }).then(function (upload) {
                job.id = upload.id;
                job.partSize = upload.part_size;
                job.partCount = upload.part_count;

                // The server asked the bucket whether it lets this page send
                // Content-MD5; one that does not refuses the part outright.
                if (upload.checksums === false) {
                    job.checksums = false;
                    self.partNotice($progress, strings.noChecksums);
                }

                (upload.done || []).forEach(function (number) {
                    job.done[number] = true;
                    job.doneBytes += self.partLength(job, number);
                });

                if (upload.resumed && (upload.done || []).length) {
                    self.partNotice($progress, strings.resuming
                        .replace('{done}', upload.done.length)
                        .replace('{total}', upload.part_count));
                }

                self.showPartProgress(job);

                return self.sendMissingParts(job);
            }).then(function () {
                return self.completeParts(job, true);
            });
        },

        /**
         * Ask the server to assemble the upload; send again any part storage
         * says it is missing, once.
         */
        completeParts: function (job, mayRetry) {
            var self = this;

            self.partNotice(job.$progress, s3BrowserConfig.i18n.upload.assembling);

            return self.partsRequest('POST', job.bucket, job.id + '/complete').catch(function (error) {
                if (!mayRetry || error.code !== 's3_upload_incomplete' || !error.data || !error.data.missing) {
                    throw error;
                }

                error.data.missing.forEach(function (number) {
                    if (job.done[number]) {
                        job.done[number] = false;
                        job.doneBytes -= self.partLength(job, number);
                    }
                });

                return self.sendMissingParts(job).then(function () {
                    return self.completeParts(job, false);
                });
            });
        },

        /**
         * Bytes in one part: the part size, except the last.
         */
        partLength: function (job, number) {
            return Math.min(job.partSize, job.file.size - (number - 1) * job.partSize);
        },

        /**
         * Send every part not yet in storage, a few at a time.
         */
        sendMissingParts: function (job) {
            var self = this;
            var settings = this.partSettings();
            var queue = [];

            for (var number = 1; number <= job.partCount; number++) {
                if (!job.done[number]) {
                    queue.push(number);
                }
            }

            var next = 0;

            function worker() {
                if (job.cancelled) {
                    return Promise.reject(new Error(s3BrowserConfig.i18n.upload.uploadCancelled));
                }

                if (job.halted || next >= queue.length) {
                    return Promise.resolve();
                }

                var part = queue[next++];

                return self.sendPart(job, part, queue.slice(next), 1).then(worker);
            }

            var workers = [];

            for (var w = 0; w < Math.min(settings.concurrency, queue.length); w++) {
                workers.push(worker());
            }

            job.halted = false;

            // One part given up on stops the rest, without cancelling: the
            // parts already in storage stay, for the same file dropped again.
            return Promise.all(workers).catch(function (error) {
                job.halted = true;

                Object.keys(job.xhrs).forEach(function (number) {
                    job.xhrs[number].abort();
                });

                throw error;
            });
        },

        /**
         * A signed URL for a part, asking for the next batch with it.
         */
        partUrl: function (job, number, upcoming) {
            var self = this;

            if (job.urls[number]) {
                return Promise.resolve(job.urls[number]);
            }

            // One signing at a time; a worker that needs a URL the running
            // one did not ask for waits for it and asks again.
            if (job.signing) {
                return job.signing.then(function () {
                    return self.partUrl(job, number, upcoming);
                });
            }

            var wanted = [number].concat(upcoming.filter(function (n) {
                return !job.urls[n];
            })).slice(0, this.partSettings().signBatch);

            job.signing = self.partsRequest('POST', job.bucket, job.id + '/parts', {parts: wanted}).then(function (signed) {
                Object.keys(signed.urls || {}).forEach(function (n) {
                    job.urls[n] = signed.urls[n];
                });
                job.signing = null;
            }, function (error) {
                job.signing = null;
                throw error;
            });

            return job.signing.then(function () {
                if (!job.urls[number]) {
                    throw new Error(s3BrowserConfig.i18n.upload.failedPresignedUrl);
                }

                return job.urls[number];
            });
        },

        /**
         * Send one part, retrying on a dropped connection or a refusal that
         * may not happen again.
         */
        sendPart: function (job, number, upcoming, attempt) {
            var self = this;
            var settings = this.partSettings();
            var strings = s3BrowserConfig.i18n.upload;
            var offset = (number - 1) * job.partSize;
            var blob = job.file.slice(offset, offset + self.partLength(job, number));

            return self.partUrl(job, number, upcoming).then(function (url) {
                return blob.arrayBuffer().then(function (bytes) {
                    var checksum = job.checksums ? window.S3BrowserMd5.base64(bytes) : '';

                    return self.putPart(job, number, url, bytes, checksum);
                });
            }).then(function () {
                job.done[number] = true;
                job.doneBytes += self.partLength(job, number);
                delete job.inFlight[number];
                self.showPartProgress(job);
            }).catch(function (error) {
                delete job.inFlight[number];

                if (job.cancelled || job.halted) {
                    throw new Error(strings.uploadCancelled);
                }

                // An expired or refused URL is signed again.
                if (error.status === 403) {
                    delete job.urls[number];
                }

                var worthAgain = error.status === 0 || error.status === 403 || error.status === 408 || error.status === 429 || error.status >= 500;

                if (!worthAgain || attempt >= settings.retries) {
                    throw new Error(strings.partFailed.replace('{part}', number).replace('{reason}', error.message) + ' ' + strings.resumeHint);
                }

                self.partNotice(job.$progress, strings.retrying.replace('{part}', number));

                return new Promise(function (resolve) {
                    setTimeout(resolve, 1000 * Math.pow(2, attempt - 1));
                }).then(function () {
                    return self.sendPart(job, number, upcoming, attempt + 1);
                });
            });
        },

        /**
         * PUT one part's bytes to its URL.
         */
        putPart: function (job, number, url, bytes, checksum) {
            var self = this;

            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();

                job.xhrs[number] = xhr;

                xhr.upload.addEventListener('progress', function (e) {
                    job.inFlight[number] = e.loaded;
                    self.showPartProgress(job);
                });

                xhr.addEventListener('load', function () {
                    delete job.xhrs[number];

                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                        return;
                    }

                    var reason = (/<Message>([^<]*)<\/Message>/.exec(xhr.responseText || '') || [])[1];
                    var error = new Error(reason || (s3BrowserConfig.i18n.upload.uploadFailedStatus + ' ' + xhr.status));

                    error.status = xhr.status;
                    reject(error);
                });

                xhr.addEventListener('error', function () {
                    delete job.xhrs[number];

                    var error = new Error(s3BrowserConfig.i18n.upload.networkError);

                    error.status = 0;
                    reject(error);
                });

                xhr.addEventListener('abort', function () {
                    delete job.xhrs[number];

                    var error = new Error(s3BrowserConfig.i18n.upload.uploadCancelled);

                    error.status = -1;
                    reject(error);
                });

                xhr.open('PUT', url, true);

                if (checksum) {
                    xhr.setRequestHeader('Content-MD5', checksum);
                }

                xhr.send(bytes);
            });
        },

        /**
         * Draw a multipart upload's progress: parts in storage plus what is
         * on its way.
         */
        showPartProgress: function (job) {
            var $progress = job.$progress;
            var size = job.file.size;
            var moving = 0;

            Object.keys(job.inFlight).forEach(function (number) {
                moving += job.inFlight[number];
            });

            var loaded = Math.min(size, job.doneBytes + moving);
            var percent = size ? Math.floor((loaded / size) * 100) : 0;

            if (!$progress || !$progress.find) {
                return;
            }

            $progress.find('.s3-progress').css('width', percent + '%');
            $progress.find('.s3-progress-text').text(percent + '%');

            var now = Date.now();
            var seconds = (now - job.lastTime) / 1000;

            if (seconds > 0.5) {
                var speed = Math.max(0, loaded - job.lastLoaded) / seconds;

                $progress.find('.s3-transfer-data').text(
                    this.formatFileSize(loaded) + ' / ' + this.formatFileSize(size) + ' \u2022 ' + this.formatFileSize(speed) + '/s'
                );

                job.lastLoaded = loaded;
                job.lastTime = now;
            }
        },

        /**
         * A line under one upload's bar.
         */
        partNotice: function ($progress, text) {
            if (!$progress || !$progress.find) {
                return;
            }

            var $note = $progress.find('.s3-upload-part-note');

            if (!$note.length) {
                $note = $('<div class="s3-upload-part-note"></div>');
                $progress.find('.s3-progress-container').after($note);
            }

            $note.text(text);
        },

        /**
         * Cancel an upload
         */
        cancelUpload: function (uploadId) {
            var $uploadItem = $('#' + uploadId);
            var filename = $uploadItem.find('.s3-filename').text();
            var confirmMessage = s3BrowserConfig.i18n.upload.cancelUploadConfirm.replace('{filename}', filename);

            if (confirm(confirmMessage) && this.activeUploads[uploadId]) {
                this.activeUploads[uploadId].abort();
                $uploadItem.addClass('s3-upload-cancelled');
                setTimeout(function () {
                    $uploadItem.fadeOut(800, function () {
                        $(this).remove();
                    });
                }, 3000);
            }
        },

        /**
         * Format file size for display
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

    });

})(jQuery);